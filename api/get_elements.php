<?php
/*
 * get_elements.php
 *
 * Search/browse endpoint for JO chemical components.
 * Query params:
 *   - match_records=0|1 (look up matching records and include their IDs in the output; default: 0)
 * Output:
 * [
 *   {"element":"H","present":true,"jo_records":[1,2,3]},
 *   {"element":"He","present":false,"jo_records":[]},
 *   ...
 * ]
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require 'config.inc.php';
function json_fail(string $message, int $status = 500): never {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
  $matchRecords = isset($_GET['match_records']) && (string)$_GET['match_records'] !== '0';
  $elements = [
    'H','He','Li','Be','B','C','N','O','F','Ne','Na','Mg','Al','Si','P','S','Cl','Ar',
    'K','Ca','Sc','Ti','V','Cr','Mn','Fe','Co','Ni','Cu','Zn','Ga','Ge','As','Se','Br','Kr',
    'Rb','Sr','Y','Zr','Nb','Mo','Tc','Ru','Rh','Pd','Ag','Cd','In','Sn','Sb','Te','I','Xe',
    'Cs','Ba','La','Ce','Pr','Nd','Pm','Sm','Eu','Gd','Tb','Dy','Ho','Er','Tm','Yb','Lu','Hf','Ta','W','Re','Os','Ir','Pt','Au','Hg','Tl','Pb','Bi','Po','At','Rn',
    'Fr','Ra','Ac','Th','Pa','U','Np','Pu','Am','Cm','Bk','Cf','Es','Fm','Md','No','Lr','Rf','Db','Sg','Bh','Hs','Mt','Ds','Rg','Cn','Nh','Fl','Mc','Lv','Ts','Og',
  ];
  $presentMap = [];
  $recordsMap = [];
  if ($matchRecords) {
    $sql = "
      SELECT
        ce.element,
        CAST(jr.id AS UNSIGNED) AS jo_record_id
      FROM jo_composition_elements ce
      INNER JOIN jo_records jr
        ON jr.id = ce.record_id
      WHERE jr.review_status = 'approved'
        AND ce.element IS NOT NULL
        AND ce.element <> ''
      ORDER BY ce.element, jr.id
    ";
    $st = $pdo->query($sql);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $el = (string)$row['element'];
      $rid = (int)$row['jo_record_id'];
      $presentMap[$el] = true;
      $recordsMap[$el][$rid] = true;
    }
  } 
  else {
    $sql = "
      SELECT DISTINCT ce.element
      FROM jo_composition_elements ce
      INNER JOIN jo_records jr
        ON jr.id = ce.record_id
      WHERE jr.review_status = 'approved'
        AND ce.element IS NOT NULL
        AND ce.element <> ''
    ";
    $st = $pdo->query($sql);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $el = (string)$row['element'];
      $presentMap[$el] = true;
    }
  }
  $out = [];
  foreach ($elements as $el) {
    $recordIds = [];
    if ($matchRecords && isset($recordsMap[$el])) {
      $recordIds = array_map('intval', array_keys($recordsMap[$el]));
      sort($recordIds, SORT_NUMERIC);
      $out[] = [
        'element'    => $el,
        'present'    => isset($presentMap[$el]),
        'jo_records' => $recordIds
      ];
    } 
    else {
      $out[] = [
        'element'    => $el,
        'present'    => isset($presentMap[$el])
      ];
    }
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} 
catch (Throwable $e) {
  json_fail('Server error: ' . $e->getMessage(), 500);
}
