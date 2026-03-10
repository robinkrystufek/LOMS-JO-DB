<?php
/**
 * get_components.php
 *
 * Search/browse endpoint for JO chemical components.
 * If ?id=123 is supplied, return the matching row from jo_components with all metadata
 * If ?id is not supplied, return all jo_components rows that are linked
 * through jo_composition_components to jo_records with review_status='approved'
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require 'config.inc.php';
function json_out(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
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
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('Database connection ($pdo) is not available.');
  }
  $idRaw = $_GET['id'] ?? null;
  if ($idRaw !== null && $idRaw !== '') {
    if (!ctype_digit((string)$idRaw)) {
      json_out([
        'ok' => false,
        'error' => 'Parameter "id" must be a positive integer.'
      ], 400);
    }
    $id = (int)$idRaw;
    $stmt = $pdo->prepare("
      SELECT
        c.*,
        COALESCE(
            JSON_ARRAYAGG(jr.id),
            JSON_ARRAY()
        ) AS jo_record_ids
      FROM jo_components c
      LEFT JOIN jo_composition_components jcc
        ON jcc.component_id = c.id
      LEFT JOIN jo_records jr
        ON jr.id = jcc.jo_record_id
        AND jr.review_status = 'approved'
      WHERE c.id = :id
      GROUP BY c.id
      LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      json_out([
        'ok' => false,
        'error' => 'Component not found.',
        'id' => $id
      ], 404);
    }
    $row['jo_record_ids'] = $row['jo_record_ids']
      ? json_decode($row['jo_record_ids'], true)
      : [];
    $row['jo_record_ids'] = array_values(array_filter(
      $row['jo_record_ids'],
      static fn($v) => $v !== null
    ));
    json_out([
      'ok' => true,
      'mode' => 'component_by_id',
      'data' => $row
    ]);
  }

  $stmt = $pdo->query("
    SELECT
      c.id, c.ui_name, c.cid, c.pubchem_name, c.date_created,
      JSON_ARRAYAGG(jr.id) AS jo_record_ids
    FROM jo_components c
    INNER JOIN jo_composition_components jcc
      ON jcc.component_id = c.id
    INNER JOIN jo_records jr
      ON jr.id = jcc.jo_record_id
    WHERE jr.review_status = 'approved'
    GROUP BY c.id
    ORDER BY c.ui_name ASC, c.id ASC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$row) {
    $row['jo_record_ids'] = $row['jo_record_ids']
      ? json_decode($row['jo_record_ids'], true)
      : [];
  }
  unset($row);
  json_out([
    'ok' => true,
    'mode' => 'all_approved',
    'count' => count($rows),
    'data' => $rows
  ]);
} 
catch (Throwable $e) {
  json_out([
    'ok' => false,
    'error' => 'Server error: ' . $e->getMessage()
  ], 500);
}
