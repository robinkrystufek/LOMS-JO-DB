<?php
/*
 * get_record_doi.php
 *
 * Given a list of DOIs, returns publication IDs and JO record counts.
 * Used by the "JO count" badges in the publication details page.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function norm_doi(string $s): string {
  $s = trim($s);
  $s = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $s);
  $s = preg_replace('~^doi:\s*~i', '', $s);
  $s = preg_replace('~\s+~', '', $s);
  return strtolower($s);
}

$raw = file_get_contents('php://input');
$req = json_decode($raw ?: '{}', true);
if (!is_array($req)) respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
$dois = $req['dois'] ?? [];
if (!is_array($dois)) $dois = [];
$set = [];
foreach ($dois as $d) {
  if (!is_string($d)) continue;
  $k = norm_doi($d);
  if ($k !== '') $set[$k] = true;
}
$keys = array_keys($set);
if (!$keys) respond(200, ['ok' => true, 'by_doi' => (object)[]]);

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
  $ph = implode(',', array_fill(0, count($keys), '?'));
  $sql = "
    SELECT
      p.id AS publication_id,
      p.doi,
      COUNT(r.id) AS jo_count
    FROM publications p
    LEFT JOIN jo_records r ON r.publication_id = p.id
    WHERE LOWER(p.doi) IN ($ph) AND r.review_status = 'approved'
    GROUP BY p.id, p.doi
  ";
  $st = $pdo->prepare($sql);
  $st->execute($keys);
  $map = [];
  foreach ($st->fetchAll() as $r) {
    $doi = (string)($r['doi'] ?? '');
    $k = norm_doi($doi);
    if ($k === '') continue;
    $map[$k] = [
      'publication_id' => (int)$r['publication_id'],
      'doi' => $doi,
      'jo_count' => (int)($r['jo_count'] ?? 0),
    ];
  }
  respond(200, ['ok' => true, 'by_doi' => $map]);
} catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}