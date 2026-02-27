<?php
/**
 * lookup_refractive_index_info.php
 *
 * Checks whether a material component exists in the
 * "main" shelf of RefractiveIndex.info by searching
 * for id="main/<component>" within the ai-tree JSON.
 * Used to decorate normalized composition entries
 * with external reference links.
 * Uses a local file cache with TTL and flock() locking
 * to prevent repeated upstream requests.
 * Returns a canonical URL of the form:
 * https://refractiveindex.info/?shelf=main&book=<component>
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

const AI_TREE_URL = 'https://refractiveindex.info/include/ai-tree.php';
const CANON_BASE  = 'https://refractiveindex.info/';
const CACHE_DIR = __DIR__ . '/../cache';
const CACHE_FILE  = CACHE_DIR . '/refractiveindex_ai_tree.json';
const LOCK_FILE   = CACHE_DIR . '/refractiveindex_ai_tree.lock';
const CACHE_TTL_SECONDS = 12 * 60 * 60;

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function ensure_cache_dir(): void {
  if (!is_dir(CACHE_DIR)) {
    if (!mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
      json_out(['ok' => false, 'error' => 'Failed to create cache dir'], 500);
    }
  }
}
function cache_is_fresh(string $file, int $ttl): bool {
  if (!is_file($file)) return false;
  $mtime = filemtime($file);
  if ($mtime === false) return false;
  return (time() - $mtime) < $ttl;
}
function http_get_json(string $url, int $timeoutSeconds = 12): array {
  $ch = curl_init($url);
  if ($ch === false) throw new RuntimeException('curl_init failed');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
    CURLOPT_TIMEOUT        => $timeoutSeconds,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_USERAGENT      => 'JO-Params-DB/1.0 (+cache)',
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false) throw new RuntimeException('Upstream fetch failed: ' . $err);
  if ($code < 200 || $code >= 300) throw new RuntimeException('Upstream HTTP status ' . $code);
  $data = json_decode($body, true);
  if (!is_array($data)) throw new RuntimeException('Upstream returned invalid JSON');
  return $data;
}
function load_tree_cached(): array {
  ensure_cache_dir();
  if (cache_is_fresh(CACHE_FILE, CACHE_TTL_SECONDS)) {
    $raw = file_get_contents(CACHE_FILE);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($data)) return $data;
  }
  $lock = fopen(LOCK_FILE, 'c+');
  if ($lock === false) throw new RuntimeException('Cannot open lock file');
  try {
    if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cannot acquire lock');
    if (cache_is_fresh(CACHE_FILE, CACHE_TTL_SECONDS)) {
      $raw = file_get_contents(CACHE_FILE);
      $data = $raw !== false ? json_decode($raw, true) : null;
      if (is_array($data)) return $data;
    }
    $data = http_get_json(AI_TREE_URL);
    $tmp = CACHE_FILE . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('json_encode failed');
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
      throw new RuntimeException('Failed writing temp cache');
    }
    if (!rename($tmp, CACHE_FILE)) {
      @unlink($tmp);
      throw new RuntimeException('Failed replacing cache');
    }
    return $data;
  } 
  finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
function normalize_component(string $s): string {
  $s = trim($s);
  if ($s === '' || mb_strlen($s) > 64) return '';
  return $s;
}
function tree_has_main_id(mixed $node, string $targetId, bool $caseInsensitive = true): bool {
  if (!is_array($node)) return false;
  if (array_key_exists('id', $node) && is_string($node['id'])) {
    if ($node['id'] === $targetId) return true;
    if ($caseInsensitive && mb_strtolower($node['id']) === mb_strtolower($targetId)) return true;
  }
  foreach ($node as $v) {
    if (is_array($v) && tree_has_main_id($v, $targetId, $caseInsensitive)) return true;
  }
  return false;
}

$component = normalize_component((string)($_GET['component'] ?? ''));
if ($component === '') {
  json_out(['ok' => false, 'error' => 'Missing/invalid component. Use ?component=CaO'], 400);
}
try {
  $tree = load_tree_cached();
  $targetId = 'main/' . $component;
  $found = tree_has_main_id($tree, $targetId, true);
  json_out([
    'ok' => true,
    'component' => $component,
    'found' => $found,
    'url' => $found ? (CANON_BASE . '?shelf=main&book=' . rawurlencode($component)) : null,
    'cache' => [
      'fresh' => cache_is_fresh(CACHE_FILE, CACHE_TTL_SECONDS),
      'ttl_seconds' => CACHE_TTL_SECONDS,
      'mtime' => is_file(CACHE_FILE) ? date('c', (int)filemtime(CACHE_FILE)) : null,
    ],
  ]);
} 
catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}