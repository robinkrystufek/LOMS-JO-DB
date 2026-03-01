<?php
/**
 * add_entry.inc.php
 *
 * Shared insertion logic for JO records.
 * Performs database inserts for jo_records, publications,
 * jo_composition_components, and contributors.
 * Intended to be included by entry endpoints.
 */
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
function json_fail(string $msg, int $http = 400): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($http);
  $payload = ['ok' => false, 'error' => $msg];
  $json = json_encode($payload,
    JSON_UNESCAPED_UNICODE
    | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($json === false) {
    $json = '{"ok":false,"error":"JSON encoding failed"}';
  }
  echo $json;
  exit;
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
function fetchComponentDetails($compRows, ?PDO $pdo = null) {
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
    $st = $pdo->prepare("SELECT id, ui_name FROM jo_components WHERE ui_name IN ($ph)");
    $st->execute($names);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $local[(string)$r['ui_name']] = (int)$r['id'];
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
    if ($q !== '' && isset($local[$q])) {
      $compRows[$i]['id'] = (int)$local[$q];
      continue;
    }
    $url = ($q !== '') ? ($componentLinks[$q] ?? null) : null;
    $r = ($url !== null) ? ($out[$url] ?? null) : null;
    if (is_array($r) && isset($r['json']['component_id'])) {
      $compRows[$i]['id'] = (int)$r['json']['component_id'];
    } else {
      $compRows[$i]['id'] = null;
    }
  }
  return $compRows;
}
