<?php
/**
 * add_entry.inc.php
 *
 * Shared insertion logic for JO records.
 * Performs database inserts for jo_records, publications,
 * jo_composition_components, and contributors.
 * Intended to be included by entry endpoints.
 */
require 'composition.inc.php';
$contributor_info  = $userInfo['user_id']; 
$contributor_email = $userInfo['email'];
$contributor_name  = $userInfo['name'];
$contributor_aff   = isset($userInfo['picture']) ? explode(';', $userInfo['picture'])[0] : null;
$contributor_orcid = isset($userInfo['picture']) ? (explode(';', $userInfo['picture'] ?? '', 2)[1] ?? null) : null;
if ($contributor_info === null) json_fail('Contributor info is required: Authentication error');

function normalize_utf8(string $s): string {
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  if ($s === '' || preg_match('//u', $s)) return $s;
  $bom2 = substr($s, 0, 2);
  if ($bom2 === "\xFF\xFE") {
    $t = @mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
    return (is_string($t) && preg_match('//u', $t)) ? $t : '';
  }
  if ($bom2 === "\xFE\xFF") {
    $t = @mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
    return (is_string($t) && preg_match('//u', $t)) ? $t : '';
  }
  if (strpos($s, "\x00") !== false) {
    $even = $odd = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
      if ($s[$i] === "\x00") {
        if (($i % 2) === 0) $even++; else $odd++;
      }
    }
    $src = ($odd > $even) ? 'UTF-16LE' : 'UTF-16BE';
    $t = @mb_convert_encoding($s, 'UTF-8', $src);
    if (is_string($t) && preg_match('//u', $t)) return $t;
  }
  foreach (['Windows-1250', 'Windows-1252', 'ISO-8859-1'] as $enc) {
    $t = @iconv($enc, 'UTF-8//IGNORE', $s);
    if (is_string($t) && $t !== '' && preg_match('//u', $t)) return $t;
  }
  $t = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
  return is_string($t) ? $t : '';
}
function parse_JSON_POST($raw): string {
  if (is_string($raw) && $raw !== '') {
    $raw = urldecode($raw);
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      return json_encode(
        $decoded,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
      );
    }
  }
  return '{}';
}
function safe_filename(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
  return ($name === '' ? 'upload.dat' : $name);
}
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      json_fail('Failed to create upload directory.', 500);
    }
  }
}
function as_trimmed(?string $v): ?string {
  if ($v === null) return null;
  $v = trim($v);
  return ($v === '' ? null : $v);
}
function to_float($v): ?float {
  if ($v === null) return null;
  if (is_string($v)) $v = str_replace(',', '.', trim($v));
  if ($v === '' || $v === null) return null;
  if (!is_numeric($v)) return null;
  return (float)$v;
}
function to_int($v): ?int {
  if ($v === null) return null;
  if (is_string($v)) $v = trim($v);
  if ($v === '' || $v === null) return null;
  if (!preg_match('/^-?\d+$/', (string)$v)) return null;
  return (int)$v;
}
function bool01($v): int {
  if ($v === null) return 0;
  if (is_bool($v)) return $v ? 1 : 0;
  $s = strtolower(trim((string)$v));
  return in_array($s, ['1','true','on','yes','y'], true) ? 1 : 0;
}
function map_host_type(?string $s): string {
  if ($s === null) return 'other';
  $t = $s;
  $map = [
    'Glass (G)' => 'glass',
    'Glass-ceramic (GC)' => 'glass_ceramic',
    'Polycrystalline (PC)' => 'polycrystal',
    'Single-crystalline (SC)' => 'single_crystal',
    'Vapor (V)' => 'vapor',
    'Solution (S)' => 'solution',
    'Melt (M)' => 'melt',
    'Powder (P)' => 'powder',
    'Aqueous (A)' => 'aqua',
    'Other' => 'other',
  ];
  return $map[$t] ?? 'other';
}
function map_conc_unit(?string $s): string {
  if ($s === null) return 'unknown';
  $t = strtolower(trim($s));
  $map = [
    'mol%' => 'mol%',
    'wt%' => 'wt%',
    'at%' => 'at%',
    'ions/cm³' => 'ions/cm3',
    'ions/cm3' => 'ions/cm3',
    'unknown' => 'unknown',
  ];
  return $map[$t] ?? 'unknown';
}
function parse_kv_file(string $text): array {
  $text = normalize_utf8($text);
  $out = [];
  $lines = explode("\n", $text);
  foreach ($lines as $ln => $line) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line[0] === '#' || $line[0] === ';') continue;
    if (!preg_match('/^([A-Za-z0-9_]+)\s*=\s*"((?:\\\\.|[^"\\\\])*)"\s*(?:[;#].*)?$/', $line, $m)) {
      json_fail('Invalid line format at line ' . ($ln + 1) . ': ' . $line);
    }
    $key = $m[1];
    $val = stripcslashes($m[2]);
    $val = normalize_utf8($val);
    if (array_key_exists($key, $out)) {
      if (!is_array($out[$key])) $out[$key] = [$out[$key]];
      $out[$key][] = $val;
    } else {
      $out[$key] = $val;
    }
  }
  return $out;
}
function kv_get_first(array $kv, string $key): ?string {
  if (!array_key_exists($key, $kv)) return null;
  $v = $kv[$key];
  if (is_array($v)) return (string)($v[0] ?? '');
  return (string)$v;
}
function kv_get_all(array $kv, string $key): array {
  if (!array_key_exists($key, $kv)) return [];
  $v = $kv[$key];
  if (is_array($v)) return array_map('strval', $v);
  return [(string)$v];
}
function curl_multi_json(array $urls, int $concurrency = 6, int $timeoutMs = 15000, int $connectTimeoutMs = 1600): array {
  $mh = curl_multi_init();
  $queue = array_values($urls);
  $handles = [];
  $out = [];
  $makeHandle = function(string $url) use ($timeoutMs, $connectTimeoutMs) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
      CURLOPT_TIMEOUT_MS => $timeoutMs,
      CURLOPT_USERAGENT => 'JO-DB add_entry multi/1.0',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    return $ch;
  };
  for ($i = 0; $i < $concurrency && count($queue); $i++) {
    $url = array_shift($queue);
    $ch = $makeHandle($url);
    $handles[(int)$ch] = $url;
    curl_multi_add_handle($mh, $ch);
  }
  $running = null;
  do {
    do {
      $mrc = curl_multi_exec($mh, $running);
    } while ($mrc === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
      $ch = $info['handle'];
      $url = $handles[(int)$ch] ?? '(unknown)';
      $raw = curl_multi_getcontent($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      $json = null;
      if ($raw !== false && $raw !== '' && $err === '') {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
      }
      $out[$url] = [
        'ok' => ($err === '' && $http >= 200 && $http < 300 && $json !== null),
        'http' => $http,
        'json' => $json,
        'raw' => is_string($raw) ? $raw : '',
        'error' => $err ?: ($json === null ? 'Invalid JSON' : ''),
      ];
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);
      unset($handles[(int)$ch]);
      if (count($queue)) {
        $nextUrl = array_shift($queue);
        $nextCh = $makeHandle($nextUrl);
        $handles[(int)$nextCh] = $nextUrl;
        curl_multi_add_handle($mh, $nextCh);
      }
    }
    if ($running) {
      curl_multi_select($mh, 0.25);
    }
  } while ($running || count($handles));
  curl_multi_close($mh);
  return $out;
}
function detect_allowed_upload(string $tmpPath, string $originalName): array {
  $name = strtolower($originalName);
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $allowedExts = ['csv', 'txt', 'xls', 'xlsx', 'zip'];
  if (!in_array($ext, $allowedExts, true)) {
      json_fail('Unsupported file type.', 400);
  }
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmpPath) ?: 'application/octet-stream';
  $allowedMimes = [
      'csv' => [
          'text/plain',
          'text/csv',
          'application/csv',
          'application/vnd.ms-excel',
      ],
      'txt' => [
          'text/plain',
      ],
      'xls' => [
          'application/vnd.ms-excel',
          'application/octet-stream',
      ],
      'xlsx' => [
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'application/zip',
          'application/octet-stream',
      ],
      'zip' => [
          'application/zip',
          'application/x-zip-compressed',
          'application/octet-stream',
      ]
  ];
  if (!in_array($mime, $allowedMimes[$ext], true)) {
      json_fail("File content does not match .$ext upload.", 400);
  }
  if ($ext === 'xlsx') {
      validate_xlsx_structure($tmpPath);
  } elseif ($ext === 'zip') {
      validate_zip_contents($tmpPath);
  }
  return [$ext, $mime];
}
function validate_xlsx_structure(string $path): void {
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
      json_fail('Invalid XLSX file.', 400);
  }
  $hasContentTypes = false;
  $hasWorkbook = false;
  for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      if ($name === '[Content_Types].xml') $hasContentTypes = true;
      if ($name === 'xl/workbook.xml') $hasWorkbook = true;
  }
  $zip->close();
  if (!$hasContentTypes || !$hasWorkbook) {
      json_fail('Invalid XLSX structure.', 400);
  }
}
function validate_zip_contents(string $path): void {
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
      json_fail('Invalid ZIP archive.', 400);
  }
  $allowedInnerExts = ['csv', 'txt', 'xls', 'xlsx'];
  $maxFiles = 40;
  $maxTotalUncompressed = 20 * 1024 * 1024;
  $total = 0;
  if ($zip->numFiles > $maxFiles) {
      $zip->close();
      json_fail('ZIP contains too many files.', 400);
  }
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'] ?? '';
    $normalized = str_replace('\\', '/', $name);
    if (
        $normalized === '' ||
        str_starts_with($normalized, '/') ||
        str_contains($normalized, '../') ||
        str_contains($normalized, '..\\')
    ) {
        $zip->close();
        json_fail('Unsafe ZIP entry path.', 400);
    }
    if (str_ends_with($normalized, '/')) {
        continue;
    }
    if (is_ignorable_archive_entry($normalized)) {
        continue;
    }
    $innerExt = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
    if (!in_array($innerExt, $allowedInnerExts, true)) {
        $zip->close();
        json_fail("ZIP contains unsupported file: {$normalized}", 400);
    }
    $size = (int)($stat['size'] ?? 0);
    $total += $size;
    if ($total > $maxTotalUncompressed) {
        $zip->close();
        json_fail('ZIP uncompressed content too large.', 400);
    }
  }
  $zip->close();
}
function is_ignorable_archive_entry(string $path): bool {
  $normalized = str_replace('\\', '/', $path);
  $base = strtolower(basename($normalized));
  if ($base === '.ds_store') return true;
  if ($base === 'thumbs.db') return true;
  if ($base === 'desktop.ini') return true;
  if (str_starts_with($normalized, '__MACOSX/')) return true;
  return false;
}
function update_composition(array $compRows, PDO $pdo, int $jo_record_id, ?string $re_ion, ?float $re_conc_value, ?string $re_conc_unit): void {
  $compRows = fetch_component_details($compRows, $pdo);
  $elementRows = [];
  if (!empty($compRows)) {
    $elementRows = calculate_composition_storage_payload($compRows);
  }
  if (!empty($compRows)) {
    $insComp = $pdo->prepare("
      INSERT INTO jo_composition_components
        (jo_record_id, component, value, unit, calc_mol, calc_wt, calc_at, component_id)
      VALUES
        (:jo_record_id, :component, :value, :unit, :calc_mol, :calc_wt, :calc_at, :component_id)
    ");
    foreach ($compRows as $r) {
      $insComp->execute([
        ':jo_record_id' => $jo_record_id,
        ':component'    => $r['component'],
        ':value'        => $r['value'],
        ':unit'         => $r['unit'],
        ':calc_mol'     => $r['calc_mol'] ?? null,
        ':calc_wt'      => $r['calc_wt'] ?? null,
        ':calc_at'      => $r['calc_at'] ?? null,
        ':component_id' => $r['id'] ?? null,
      ]);
    }
  }
  if (!empty($elementRows)) {
    $insElem = $pdo->prepare("
      INSERT INTO jo_composition_elements
        (element, c_mol, c_wt, re_c, re_c_unit, record_id)
      VALUES
        (:element, :c_mol, :c_wt, :re_c, :re_c_unit, :record_id)
    ");
    foreach ($elementRows as $el => $row) {
      $insElem->execute([
        ':element'   => $el,
        ':c_mol'     => $row['c_mol'] ?? null,
        ':c_wt'      => $row['c_wt'] ?? null,
        ':re_c'      => $el == $re_ion ? $re_conc_value : null,
        ':re_c_unit' => $el == $re_ion ? (in_array($re_conc_unit, ['mol%','wt%','at%'], true) ? $re_conc_unit : null) : null,
        ':record_id' => $jo_record_id,
      ]);
    }
  }
  if(!isset($elementRows[$re_ion])) {
    $insElem = $pdo->prepare("
      INSERT INTO jo_composition_elements
        (element, c_mol, c_wt, re_c, re_c_unit, record_id)
      VALUES
        (:element, :c_mol, :c_wt, :re_c, :re_c_unit, :record_id)
    ");
    $insElem->execute([
      ':element'   => substr($re_ion, 0, 10),
      ':c_mol'     => null,
      ':c_wt'      => null,
      ':re_c'      => $re_conc_value,
      ':re_c_unit' => in_array($re_conc_unit, ['mol%','wt%','at%'], true) ? $re_conc_unit : null,
      ':record_id' => $jo_record_id,
    ]);
  }
}
function fetch_component_details($compRows, ?PDO $pdo = null) {
  $baseUrl = 'https://www.loms.cz/jo-db/api/lookup_pubchem.php?q=';
  $uniq = [];
  foreach ($compRows as $row) {
    $q = (string)($row['component'] ?? '');
    if ($q !== '') $uniq[$q] = true;
  }
  $names = array_keys($uniq);
  $local = [];
  if ($pdo instanceof PDO && count($names)) {
    $ph = implode(',', array_fill(0, count($names), '?'));
    $st = $pdo->prepare("
      SELECT id, ui_name, mw, atom_number, composition
      FROM jo_components
      WHERE ui_name IN ($ph)
    ");
    $st->execute($names);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $local[(string)$r['ui_name']] = $r;
    }
  }
  $componentLinks = [];
  foreach ($names as $name) {
    if (isset($local[$name])) continue;
    $componentLinks[$name] = $baseUrl . rawurlencode($name);
  }
  $out = [];
  if (count($componentLinks)) {
    $out = curl_multi_json(array_values($componentLinks), concurrency: 6, timeoutMs: 15000, connectTimeoutMs: 1600);
  }
  for ($i = 0; $i < count($compRows); $i++) {
    $q = (string)($compRows[$i]['component'] ?? '');
    $compRows[$i]['id'] = null;
    $compRows[$i]['mw'] = null;
    $compRows[$i]['atom_number'] = null;
    $compRows[$i]['composition'] = null;
    if ($q !== '' && isset($local[$q])) {
      $compRows[$i]['id'] = (int)$local[$q]['id'];
      $compRows[$i]['mw'] = to_float($local[$q]['mw']);
      $compRows[$i]['atom_number'] = to_float($local[$q]['atom_number']);
      $compRows[$i]['composition'] = $local[$q]['composition'];
      continue;
    }
    $url = ($q !== '') ? ($componentLinks[$q] ?? null) : null;
    $r = ($url !== null) ? ($out[$url] ?? null) : null;
    if (is_array($r) && isset($r['json']['component_id'])) {
      $compRows[$i]['id'] = (int)$r['json']['component_id'];
      if ($pdo instanceof PDO) {
        $st = $pdo->prepare("
          SELECT id, mw, atom_number, composition
          FROM jo_components
          WHERE id = ?
          LIMIT 1
        ");
        $st->execute([$compRows[$i]['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          $compRows[$i]['mw'] = to_float($row['mw']);
          $compRows[$i]['atom_number'] = to_float($row['atom_number']);
          $compRows[$i]['composition'] = $row['composition'];
        }
      }
    }
  }
  return $compRows;
}
function calculate_composition_storage_payload(array &$components): array {
  if (!$components) return [];
  $EPS = 1e-12;
  $moles = [];
  $totalMoles = 0.0;
  foreach ($components as $i => $c) {
    $val  = (float)($c['value'] ?? 0);
    $unit = strtolower(trim((string)($c['unit'] ?? '')));
    $mw   = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atom_count_from_component_composition($c['composition'] ?? null, $fallbackAtoms);
    if ($val <= 0) {
      $moles[$i] = 0.0;
      continue;
    }
    if ($unit === 'mol%') {
      $n = $val;
    } 
    elseif ($unit === 'wt%') {
      $n = ($mw > $EPS) ? ($val / $mw) : 0.0;
    } 
    elseif ($unit === 'at%') {
      $n = ($atoms > $EPS) ? ($val / $atoms) : 0.0;
    } 
    else {
      $n = 0.0;
    }
    $moles[$i] = $n;
    $totalMoles += $n;
  }
  if ($totalMoles <= $EPS) return [];
  $totalMass = 0.0;
  $totalAtomCount = 0.0;
  foreach ($components as $i => $c) {
    $n = $moles[$i];
    if ($n <= 0) continue;
    $mw = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atom_count_from_component_composition($c['composition'] ?? null, $fallbackAtoms);
    $totalMass += $n * $mw;
    $totalAtomCount += $n * $atoms;
  }
  if ($totalMass <= $EPS) $totalMass = 1.0;
  if ($totalAtomCount <= $EPS) $totalAtomCount = 1.0;
  foreach ($components as $i => &$c) {
    $n = $moles[$i];
    $mw = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atom_count_from_component_composition($c['composition'] ?? null, $fallbackAtoms);

    $c['calc_mol'] = round(($n / $totalMoles) * 100.0, 10);
    $mass = $n * $mw;
    $c['calc_wt'] = round(($mass / $totalMass) * 100.0, 10);
    $atomCnt = $n * $atoms;
    $c['calc_at'] = round(($atomCnt / $totalAtomCount) * 100.0, 10);
  }
  unset($c);
  $elemAtoms = [];
  $elemMoles = [];
  $elemTotalAtoms = 0.0;
  foreach ($components as $i => $c) {
    $n = $moles[$i];
    if ($n <= 0) continue;
    $comp = parse_component_composition($c['composition'] ?? null);
    if (!$comp) continue;
    foreach ($comp as $el => $cnt) {
      $cnt = (float)$cnt;
      $atomsAdded = $n * $cnt;
      $elemAtoms[$el] = ($elemAtoms[$el] ?? 0.0) + $atomsAdded;
      $elemMoles[$el] = ($elemMoles[$el] ?? 0.0) + $atomsAdded;
      $elemTotalAtoms += $atomsAdded;
    }
  }
  if ($elemTotalAtoms <= $EPS) return [];
  $weights = element_atomic_weights();
  $out = [];
  $massTotal = 0.0;
  foreach ($elemAtoms as $el => $a) {
    $out[$el] = [
      'c_mol' => round(($elemMoles[$el] / $elemTotalAtoms) * 100.0, 10),
      'c_at'  => round(($a / $elemTotalAtoms) * 100.0, 10),
      'c_wt'  => null,
    ];
    if (isset($weights[$el])) {
      $mass = $a * $weights[$el];
      $out[$el]['_mass'] = $mass;
      $massTotal += $mass;
    }
  }
  if ($massTotal > $EPS) {
    foreach ($out as $el => &$row) {
      if (isset($row['_mass'])) {
        $row['c_wt'] = round(($row['_mass'] / $massTotal) * 100.0, 10);
        unset($row['_mass']);
      }
    }
    unset($row);
  } 
  else {
    foreach ($out as &$row) unset($row['_mass']);
    unset($row);
  }
  ksort($out);
  return $out;
}
function recalculate_record_composition_storage(PDO $pdo, int $jo_record_id): array {
  if ($jo_record_id <= 0) {
    throw new InvalidArgumentException('Invalid jo_record_id');
  }
  $stRec = $pdo->prepare("
    SELECT id, re_ion, re_conc_value, re_conc_unit
    FROM jo_records
    WHERE id = ?
    LIMIT 1
  ");
  $stRec->execute([$jo_record_id]);
  $rec = $stRec->fetch(PDO::FETCH_ASSOC);
  if (!$rec) {
    throw new RuntimeException("JO record {$jo_record_id} not found.");
  }
  $reIon = trim((string)($rec['re_ion'] ?? ''));
  $reConcValue = isset($rec['re_conc_value']) ? (float)$rec['re_conc_value'] : null;
  $reConcUnit = in_array(($rec['re_conc_unit'] ?? null), ['mol%','wt%','at%'], true)
    ? $rec['re_conc_unit']
    : null;
  $stComp = $pdo->prepare("
    SELECT
      cc.id AS composition_component_row_id,
      cc.component,
      cc.value,
      cc.unit,
      cc.component_id,
      jc.mw,
      jc.atom_number,
      jc.composition
    FROM jo_composition_components cc
    LEFT JOIN jo_components jc
      ON jc.id = cc.component_id
    WHERE cc.jo_record_id = ?
    ORDER BY cc.id ASC
  ");
  $stComp->execute([$jo_record_id]);
  $compRows = $stComp->fetchAll(PDO::FETCH_ASSOC);
  if (!$compRows) {
    return [
      'jo_record_id' => $jo_record_id,
      'components_updated' => 0,
      'elements_inserted' => 0,
      'note' => 'No component rows found.'
    ];
  }
  $calcRows = array_map(function(array $r) {
    return [
      'row_id' => (int)$r['composition_component_row_id'],
      'id' => isset($r['component_id']) ? (int)$r['component_id'] : null,
      'component' => $r['component'],
      'value' => $r['value'],
      'unit' => $r['unit'],
      'mw' => $r['mw'],
      'atom_number' => $r['atom_number'],
      'composition' => $r['composition'],
    ];
  }, $compRows);
  $calcRows = fetch_component_details($calcRows, $pdo);
  $elementRows = calculate_composition_storage_payload($calcRows);
  try {
    $stUpdComp = $pdo->prepare("
      UPDATE jo_composition_components
      SET
        component_id = :component_id,
        calc_mol = :calc_mol,
        calc_wt = :calc_wt,
        calc_at = :calc_at
      WHERE id = :id
        AND jo_record_id = :jo_record_id
    ");
    $componentsUpdated = 0;
    foreach ($calcRows as $r) {
      $stUpdComp->execute([
        ':component_id' => $r['id'] ?? null,
        ':calc_mol' => $r['calc_mol'] ?? null,
        ':calc_wt' => $r['calc_wt'] ?? null,
        ':calc_at' => $r['calc_at'] ?? null,
        ':id' => $r['row_id'],
        ':jo_record_id' => $jo_record_id,
      ]);
      $componentsUpdated += $stUpdComp->rowCount();
    }

    $stDelElem = $pdo->prepare("DELETE FROM jo_composition_elements WHERE record_id = ?");
    $stDelElem->execute([$jo_record_id]);
    $stInsElem = $pdo->prepare("
      INSERT INTO jo_composition_elements
        (element, c_mol, c_wt, re_c, re_c_unit, record_id)
      VALUES
        (:element, :c_mol, :c_wt, :re_c, :re_c_unit, :record_id)
    ");
    $elementsInserted = 0;
    foreach ($elementRows as $el => $row) {
      $isRe = ($reIon !== '' && $el === $reIon);
      $stInsElem->execute([
        ':element' => $el,
        ':c_mol' => $row['c_mol'] ?? null,
        ':c_wt' => $row['c_wt'] ?? null,
        ':re_c' => $isRe ? $reConcValue : null,
        ':re_c_unit' => $isRe ? $reConcUnit : null,
        ':record_id' => $jo_record_id,
      ]);
      $elementsInserted++;
    }

    if ($reIon !== '' && !isset($elementRows[$reIon])) {
      $stInsElem->execute([
        ':element' => $reIon,
        ':c_mol' => null,
        ':c_wt' => null,
        ':re_c' => $reConcValue,
        ':re_c_unit' => $reConcUnit,
        ':record_id' => $jo_record_id,
      ]);
      $elementsInserted++;
    }
    return [
      'jo_record_id' => $jo_record_id,
      'components_updated' => $componentsUpdated,
      'elements_inserted' => $elementsInserted,
      'note' => 'Recalculation successful.'
    ];
  } 
  catch (Throwable $e) {
    throw $e;
  }
}
function find_composition_backfill_candidates(PDO $pdo, int $windowSeconds = 600, int $limit = 200): array {
  $sql = "
    SELECT DISTINCT r.id
    FROM jo_records r
    LEFT JOIN jo_composition_components cc
      ON cc.jo_record_id = r.id
    LEFT JOIN jo_composition_elements ce
      ON ce.record_id = r.id
    WHERE
      (
        cc.id IS NOT NULL AND (
          cc.component_id IS NULL OR
          cc.calc_mol IS NULL OR
          cc.calc_wt IS NULL OR
          cc.calc_at IS NULL
        )
      )
      OR ce.id IS NULL
      OR (
        ce.updated_at IS NOT NULL
        AND r.date_submitted IS NOT NULL
        AND ABS(TIMESTAMPDIFF(SECOND, r.date_submitted, ce.updated_at)) <= :window_seconds
      )
    ORDER BY r.id DESC
  ";
  if ($limit !== null && $limit > 0) {
    $sql .= " LIMIT " . (int)$limit;
  }
  $st = $pdo->prepare($sql);
  $st->bindValue(':window_seconds', $windowSeconds, PDO::PARAM_INT);
  $st->execute();
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function backfill_composition_storage(PDO $pdo, int $windowSeconds = 600, int $limit = 200): array {
  $ids = find_composition_backfill_candidates($pdo, $windowSeconds, $limit);
  $results = [];
  $ok = 0;
  $fail = 0;
  foreach ($ids as $jo_record_id) {
    try {
      $results[] = recalculate_record_composition_storage($pdo, $jo_record_id);
      $ok++;
    } catch (Throwable $e) {
      $results[] = [
        'jo_record_id' => $jo_record_id,
        'error' => $e->getMessage(),
      ];
      $fail++;
    }
  }
  return [
    'candidate_count' => count($ids),
    'ok' => $ok,
    'fail' => $fail,
    'results' => $results,
  ];
}