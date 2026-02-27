<?php
/**
 * api/get_audit_trail.php
 *
 * Returns the audit trail for a JO record.
 * Server-side recursion follows jo_records.is_revision_of_id until NULL/0,
 * joining jo_contributors for:
 *  - submitted_by (jo_records.contributor_info)
 *  - approved_by  (jo_records.approved_by)
 *
 * Response:
 * { ok: true, chain: [ {record_id, is_revision_of_id, submitted_by_uid, submitted_by_name, approved_by_uid, approved_by_name}, ... ] }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$rid = (int)($_GET['id'] ?? 0);
if ($rid <= 0) respond(400, ['ok' => false, 'error' => 'Missing/invalid id']);
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
  $chain = [];
  $seen = [];
  $cur = $rid;
  $MAX_DEPTH = 50;
  $st = $pdo->prepare("
    SELECT
      r.id AS record_id,
      r.date_submitted AS record_date_submitted,
      r.date_approved AS record_date_approved,
      r.is_revision_of_id,
      r.contributor_info AS submitted_by_uid,
      r.approved_by      AS approved_by_uid,

      cs.name AS submitted_by_name,
      ca.name AS approved_by_name,

      cs.email AS submitted_by_email,
      ca.email AS approved_by_email
    FROM jo_records r
    LEFT JOIN jo_contributors cs ON CONVERT(cs.uid USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(r.contributor_info USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
    LEFT JOIN jo_contributors ca ON CONVERT(ca.uid USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(r.approved_by USING utf8mb4) COLLATE utf8mb4_0900_ai_ci
    WHERE r.id = :id
    LIMIT 1
  ");
  for ($i = 0; $i < $MAX_DEPTH && $cur > 0; $i++) {
    if (isset($seen[$cur])) break;
    $seen[$cur] = true;
    $st->execute([':id' => $cur]);
    $row = $st->fetch();
    if (!$row) break;
    $row['submitted_by_name'] = (string)($row['submitted_by_name'] ?: $row['submitted_by_email'] ?: '');
    $row['approved_by_name']  = (string)($row['approved_by_name']  ?: $row['approved_by_email']  ?: '');
    $chain[] = $row;
    $prev = (int)($row['is_revision_of_id'] ?? 0);
    if ($prev <= 0) break;
    $cur = $prev;
  }
  respond(200, ['ok' => true, 'chain' => $chain]);
} 
catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Server error'.$e->getMessage()]);
}