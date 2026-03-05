<?php
/**
 * get_pub_metadata.php
 * If ?doi=... is provided:
 * Returns stored reference JSONs for a DOI.
 * Used by the publication details page
 * If no doi is provided:
 * Returns ONLY publications that have approved jo_records
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function normalize_doi(string $s): string {
  $s = trim($s);
  $s = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $s);
  $s = preg_replace('~^doi:\s*~i', '', $s);
  $s = preg_replace('~\s+~', '', $s);
  return strtolower($s);
}

$doi = isset($_GET['doi']) ? normalize_doi((string)$_GET['doi']) : '';

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
  // DOI provided -> publication details
  if ($doi !== '') {
    $st = $pdo->prepare("SELECT * FROM publications WHERE doi = :doi ORDER BY id DESC LIMIT 1");
    $st->execute([':doi' => $doi]);
    $row = $st->fetch();
    if (!$row) {
      respond(404, ['ok' => false, 'error' => 'No publication record found for this DOI.']);
    }
    $refs = json_decode($row['alex_refs'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      respond(404, ['ok' => false, 'error' => 'No valid metadata for this DOI/publication.']);
    }
    $st2 = $pdo->prepare("
      SELECT r.id
      FROM jo_records r
      JOIN publications p ON p.id = r.publication_id
      WHERE p.doi = :doi AND r.review_status = 'approved'
      ORDER BY r.id ASC
    ");
    $st2->execute([':doi' => $doi]);
    $joRecordIds = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN, 0));
    $joCount = count($joRecordIds);
    $meta = [
      'updated_at' => $row['updated_at'] ?? ($row['created_at'] ?? null),
      'count' => is_array($refs) ? count($refs) : null,
      'jo_records_count_for_doi' => $joCount,
      'jo_record_ids_for_doi' => $joRecordIds,
    ];
    respond(200, [
      'ok' => true,
      'doi' => ($row['doi'] ?? null),
      'publication_id' => ($row['publication_id'] ?? null),
      'meta' => $meta,
      'refs' => $refs,
      'raw_row' => $row,
    ]);
  }
  // no DOI provided -> return DOIs with approved JO records
  else {
    $stAll = $pdo->prepare("
      SELECT
        p.id,
        p.doi,
        p.created_at,
        p.updated_at
      FROM publications p
      WHERE p.doi IS NOT NULL AND p.doi <> ''
        AND EXISTS (
          SELECT 1
          FROM jo_records r
          WHERE r.publication_id = p.id
            AND r.review_status = 'approved'
          LIMIT 1
        )
    ");
    $stAll->execute();
    $pubRows = $stAll->fetchAll();
    if (!$pubRows) {
      respond(500, ['ok' => false, 'error' => 'DOI fetch yielded no results']);
    }
    $pubIds = [];
    foreach ($pubRows as $r) $pubIds[] = (int)$r['id'];
    $idsByPub = [];
    if (!empty($pubIds)) {
      $placeholders = implode(',', array_fill(0, count($pubIds), '?'));
      $stIds = $pdo->prepare("
        SELECT publication_id, id
        FROM jo_records
        WHERE review_status = 'approved'
          AND publication_id IN ($placeholders)
        ORDER BY publication_id ASC, id ASC
      ");
      $stIds->execute($pubIds);
      while ($rr = $stIds->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$rr['publication_id'];
        $rid = (int)$rr['id'];
        $idsByPub[$pid][] = $rid;
      }
    }
    $out = [];
    foreach ($pubRows as $row) {
      $rowDoi = normalize_doi((string)($row['doi'] ?? ''));
      if ($rowDoi === '') continue;
      $pid = (int)$row['id'];
      $joRecordIds = $idsByPub[$pid] ?? [];
      $joCount = count($joRecordIds);
      $meta = [
        'updated_at' => $row['updated_at'] ?? ($row['created_at'] ?? null),
        'jo_records_count_for_doi' => $joCount,
        'jo_record_ids_for_doi' => $joRecordIds,
      ];
      $out[$rowDoi] = [
        'doi' => ($row['doi'] ?? null),
        'publication_id' => ($row['id'] ?? null),
        'meta' => $meta,
      ];
    }
    respond(200, [
      'ok' => true,
      'mode' => 'all_with_approved_jo_records',
      'count' => count($out),
      'by_doi' => $out,
    ]);
  }
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}