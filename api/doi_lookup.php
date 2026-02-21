<?php
/**
 * doi_lookup.php
 *
 * Performs DOI metadata lookup via external API.
 * Returns normalized publication metadata (authors, title,
 * journal, year, etc.) as JSON for autofill and storage.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
function out($data, int $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function norm_doi(string $s): string {
  $s = trim($s);
  $s = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $s);
  $s = preg_replace('~^doi:\s*~i', '', $s);
  return preg_replace('~\s+~', '', $s);
}
function get_json(string $url): ?array {
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 10,
      'header' => "User-Agent: DOI-Lookup/1.0\r\nAccept: application/json\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return null;
  $json = json_decode($raw, true);
  return is_array($json) ? $json : null;
}
function doi_lookup_fetch(string $doi): ?array {
  $doi = norm_doi($doi);
  if ($doi === '') return null;
  $raw_sources = [];
  $result = [
    'doi' => $doi,
    'title' => '',
    'authors' => '',
    'year' => '',
    'journal' => '',
    'url' => 'https://doi.org/' . $doi,
    'source' => '',
  ];
  $cr = get_json("https://api.crossref.org/works/" . rawurlencode($doi));
  if ($cr && isset($cr['message'])) {
    $raw_sources[] = ['source' => 'crossref', 'data' => $cr];
    $m = $cr['message'];
    $result['source'] = 'crossref';
    $result['title'] = $m['title'][0] ?? '';
    $result['journal'] = $m['container-title'][0] ?? '';
    if (!empty($m['author'])) {
      $names = [];
      foreach ($m['author'] as $a) {
        $names[] = trim(($a['given'] ?? '') . ' ' . ($a['family'] ?? ''));
      }
      $result['authors'] = implode('; ', $names);
    }
    if (!empty($m['issued']['date-parts'][0][0])) {
      $result['year'] = (string)$m['issued']['date-parts'][0][0];
    }
    if (!empty($m['URL'])) {
      $result['url'] = $m['URL'];
    }
  }
  if (!$result['title']) {
    $oa = get_json("https://api.openalex.org/works/doi:" . rawurlencode($doi));
    if ($oa) {
      $raw_sources[] = ['source' => 'openalex', 'data' => $oa];
      $result['source'] = 'openalex';
      $result['title'] = $oa['display_name'] ?? '';
      if (!empty($oa['authorships'])) {
        $names = array_map(fn($a) => $a['author']['display_name'] ?? '', $oa['authorships']);
        $result['authors'] = implode('; ', $names);
      }
      $result['year'] = (string)($oa['publication_year'] ?? '');
      $result['journal'] = $oa['primary_location']['source']['display_name'] ?? '';
      if (!empty($oa['primary_location']['landing_page_url'])) {
        $result['url'] = $oa['primary_location']['landing_page_url'];
      }
    }
  }
  if (!$result['title']) return null;
  $result['raw'] = $raw_sources;
  return $result;
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  $doi = norm_doi($_GET['doi'] ?? '');
  if (!$doi) out(['error' => 'Missing DOI'], 400);
  $r = doi_lookup_fetch($doi);
  if (!$r) out(['error' => 'Metadata not found'], 404);
  out($r);
}
