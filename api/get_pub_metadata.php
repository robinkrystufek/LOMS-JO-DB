<?php
/**
 * get_pub_metadata.php
 *
 * Returns stored reference JSONs for a DOI.
 * Used by the publication details page
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
  return $s;
}

$doi = isset($_GET['doi']) ? normalize_doi((string)$_GET['doi']) : '';
if ($doi === '') {
  respond(400, ['ok' => false, 'error' => 'Missing parameter: doi or publication_id.']);
}

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
  $params[':doi'] = $doi;
  $sql = "SELECT * FROM publications WHERE doi = :doi ORDER BY id DESC LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch();
  if (!$row) {
    respond(404, ['ok' => false, 'error' => 'No alex_refs row found for this DOI/publication.']);
  }
  $refs  = json_decode($row['alex_refs'], true);
  if (json_last_error() != JSON_ERROR_NONE) {
    respond(404, ['ok' => false, 'error' => 'No valid data for this DOI/publication.']);
  }
  $joCount = null;
  try {
    if ($doi !== '') {
      $st2 = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM jo_records r
        JOIN publications p ON p.id = r.publication_id
        WHERE p.doi = :doi AND r.review_status = 'approved'
      ");
      $st2->execute([':doi' => $doi]);
      $joCount = (int)($st2->fetchColumn() ?? 0);
    }
  } 
  catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
  }
  $meta = [
    'updated_at' => $row['updated_at'] ?? ($row['created_at'] ?? null),
    'count' => is_array($refs) ? count($refs) : null,
    'jo_records_count_for_doi' => $joCount
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
catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}