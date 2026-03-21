<?php
/**
 * lookup_loms.php
 *
 * Performs lookup of recalculated LOMS data stored in ZIP files linked to the given record.
 * Returns link to JO Analysis Suite with prefilled data if matching file is found, or error message if not.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require 'config.inc.php';
require 'records.inc.php';

function json_out(array $data, int $status = 200): never {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function search_file(string $haystack, array $needles): bool {
  foreach ($needles as $needle) {
    if ($needle === '') continue;
    if (strpos($haystack, $needle) === false) {
      return false;
    }
  }
  return true;
}

$recordId = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
if ($recordId <= 0) {
  json_out([
    'ok' => false,
    'error' => 'Missing or invalid GET parameter: record_id'
  ], 400);
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
  $st = $pdo->prepare("
    SELECT
      r.id,
      r.re_ion,
      r.sample_label,
      r.extra_notes,
      r.recalculated_loms_option
    FROM jo_records r
    WHERE r.id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $recordId]);
  $r = $st->fetch();

  if (!$r) {
    json_out([
      'ok' => false,
      'error' => 'Record not found'
    ], 400);
  }
  if ($r['recalculated_loms_option']!=2) {
    json_out([
      'ok' => false,
      'error' => 'Record does not indicate recalculated LOMS option'
    ], 200);
  }
  $lomsPath = extract_file_path((string)($r['extra_notes'] ?? ''));
  if (!$lomsPath) {
    json_out([
      'ok' => false,
      'error' => 'No LOMS ZIP path found in record extra_notes'
    ], 200);
  }
  $relPath = str_replace('\\', '/', $lomsPath);
  if (
    str_contains($relPath, "\0") ||
    str_contains($relPath, '../') ||
    str_starts_with($relPath, '/') ||
    preg_match('~^[a-zA-Z]+://~', $relPath)
  ) {
    json_out([
      'ok' => false,
      'error' => 'Invalid stored local path'
    ], 500);
  }
  $baseDir = realpath(__DIR__ . '/..');
  if ($baseDir === false) {
    json_out([
      'ok' => false,
      'error' => 'Base directory could not be resolved'
    ], 500);
  }
  $fullPath = realpath($baseDir . '/' . $relPath);
  if ($fullPath === false || !is_file($fullPath)) {
    json_out([
      'ok' => false,
      'error' => 'File not found',
      'loms_path' => $relPath
    ], 500);
  }
  if (strncmp($fullPath, $baseDir, strlen($baseDir)) !== 0) {
    json_out([
      'ok' => false,
      'error' => 'Resolved path escapes allowed base directory'
    ], 500);
  }

  $fileExt = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));

  $needles = [
    'excited_state,u2,u4,u6',
    'ref_index_type,',
    'sellmeier_A',
  ];

  if (in_array($fileExt, ['txt', 'csv'], true)) {
    $content = @file_get_contents($fullPath);
    if ($content === false) {
      json_out([
        'ok' => false,
        'error' => 'Could not read TXT/CSV file',
        'loms_path' => $relPath
      ], 500);
    }

    if (search_file($content, $needles)) {
      json_out([
        'ok' => true,
        'record_id' => $recordId,
        'loms_path' => $relPath,
        'sample_label' => $r['sample_label'] ?? pathinfo($fullPath, PATHINFO_FILENAME),
        'matched_file' => basename($fullPath),
        'content_urlencoded' => "https://www.loms.cz/jo/?RE=" . $r['re_ion'] .
          "&sample_id=" . rawurlencode($r['sample_label'] ?? "Record " . $recordId) .
          "&input=" . rawurlencode($content)
      ]);
    }

    json_out([
      'ok' => false,
      'record_id' => $recordId,
      'loms_path' => $relPath,
      'error' => 'TXT/CSV file does not contain required LOMS input markers'
    ], 200);
  }

  if ($fileExt !== 'zip') {
    json_out([
      'ok' => false,
      'record_id' => $recordId,
      'loms_path' => $relPath,
      'error' => 'Invalid file type: expected ZIP, TXT, or CSV'
    ], 200);
  }

  $zip = new ZipArchive();
  $openRes = $zip->open($fullPath);
  if ($openRes !== true) {
    json_out([
      'ok' => false,
      'error' => 'Could not open ZIP archive',
      'zip_error' => $openRes
    ], 500);
  }

  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat || empty($stat['name'])) continue;
    $name = $stat['name'];
    if (substr($name, -1) === '/') continue;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt', 'csv'], true)) continue;
    $content = $zip->getFromIndex($i);
    if ($content === false) continue;
    if (search_file($content, $needles)) {
      $zip->close();
      json_out([
        'ok' => true,
        'record_id' => $recordId,
        'loms_path' => $relPath,
        'sample_label' => $r['sample_label'] ?? pathinfo($name, PATHINFO_FILENAME),
        'matched_file' => $name,
        'content_urlencoded' => "https://www.loms.cz/jo/?RE=" . $r['re_ion'] .
          "&sample_id=" . rawurlencode($r['sample_label'] ?? pathinfo($name, PATHINFO_FILENAME)) .
          "&input=" . rawurlencode($content)
      ]);
    }
  }
  $zip->close();

  json_out([
    'ok' => false,
    'record_id' => $recordId,
    'loms_path' => $relPath,
    'error' => 'No matching TXT or CSV file found in ZIP'
  ], 200);
} catch (Throwable $e) {
  json_out([
    'ok' => false,
    'error' => 'Server error',
    'detail' => $e->getMessage()
  ], 500);
}