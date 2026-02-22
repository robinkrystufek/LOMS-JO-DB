<?php
/**
 * doi_lookup.php
 *
 * Performs DOI metadata lookup via external APIs (CrossRef + OpenAlex).
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
function get_json(string $url): ?array {
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 10,
      'header' => "User-Agent: DOI-Lookup/1.0\r\nAccept: application/json\r\nUser-Agent: LOMSJO/1.0 (mailto:info@loms.cz)\r\n"
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) return null;
  $json = json_decode($raw, true);
  return is_array($json) ? $json : null;
}
function normalize_doi(?string $doi): string {
  if (!is_string($doi) || $doi === '') return '';
  $doi = trim($doi);
  $doi = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi);
  return strtolower($doi);
}
function oa_strip_id(?string $id): string {
    if (!is_string($id) || $id === '') return '';
    if (preg_match('~W\d+~', $id, $m)) return $m[0];
    return $id;
}
function oa_compact_authors($authorships, int $maxNames = 8): string {
    if (!is_array($authorships) || !$authorships) return '';
    $names = [];
    foreach ($authorships as $a) {
        $n = $a['author']['display_name'] ?? null;
        if (is_string($n) && $n !== '') $names[] = $n;
        if (count($names) >= $maxNames) break;
    }
    if (!$names) return '';
    $s = implode(', ', $names);
    if (count($authorships) > $maxNames) $s .= ', et al.';
    return $s;
}
function oa_fetch_citations(string $targetWorkId, int $limit = 10000, int $perPage = 200): array {
    $targetWorkId = trim($targetWorkId);
    if ($targetWorkId === '') {
        return ['ok' => false, 'error' => 'Missing targetWorkId'];
    }
    $cursor = '*';
    $items = [];
    $total = null;
    while (count($items) < $limit && $cursor) {
        $url = 'https://api.openalex.org/works'
            . '?filter=' . rawurlencode("cites:$targetWorkId,has_doi:true")
            . '&select=' . rawurlencode('id,doi,display_name,publication_year,authorships,biblio,primary_location')
            . '&per-page=' . $perPage
            . '&cursor=' . rawurlencode($cursor);
        $data = get_json($url);
        if (!$data['meta']) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'OpenAlex error', 'details' => $data];
        }        
        if ($total === null && isset($data['meta']['count'])) $total = (int)$data['meta']['count'];
        $results = $data['results'] ?? [];
        if (!is_array($results) || !$results) break;
        foreach ($results as $w) {
            if (count($items) >= $limit) break;
            $alexId  = oa_strip_id($w['id'] ?? '');
            $doi     = normalize_doi($w['doi'] ?? '');
            if ($doi === '' && isset($w['ids']['doi'])) {
                $doi = normalize_doi($w['ids']['doi']);
            }
            $b = $w['biblio'] ?? [];
            $vol   = trim($b['volume']      ?? '');
            $issue = trim($b['issue']       ?? '');
            $fp    = trim($b['first_page']  ?? '');
            $lp    = trim($b['last_page']   ?? '');
            $out = '';
            if ($vol !== '') {
                $out .= 'vol. ' . $vol;
            }
            if ($issue !== '') {
                $out .= ($out !== '' ? '' : '') . '(' . $issue . ')';
            }
            if ($fp !== '' || $lp !== '') {
                if ($out !== '') $out .= ' ';
                $out .= 'p. ' . $fp;
                if ($lp !== '') {
                    $out .= '-' . $lp;
                }
            }
            $items[] = [
                'alex_id' => $alexId,
                'doi'     => $doi,
                'year'    => (int)($w['publication_year'] ?? 0),
                'title'   => (string)($w['display_name'] ?? ''),
                'journal' => (string)($w['primary_location']['source']['display_name'] ?? ''),
                'biblio'  => (string)($out ?? ''),
                'authors' => oa_compact_authors($w['authorships'] ?? [], 8),
            ];
        }
        $next = $data['meta']['next_cursor'] ?? '';
        $cursor = (is_string($next) && $next !== '') ? $next : '';
    }
    return [
        'ok' => true,
        'total' => $total ?? count($items),
        'truncated' => ($total !== null && $total > count($items)) || (count($items) >= $limit),
        'items' => $items,
    ];
}
function doi_lookup_fetch(string $doi, bool $fast_search = false): ?array {
  $doi = normalize_doi($doi);
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
    'alex_id' => '',
    'alex_refs' => null,
    'alex_citations' => null,
    'raw' => null
  ];
  $cr = get_json("https://api.crossref.org/works/" . rawurlencode($doi));
  if ($cr && isset($cr['message'])) {
    $result['raw'] = ['source' => 'crossref', 'data' => $cr];
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
  if($fast_search && $result['title']) {
    return $result;
  }
  $oa = get_json("https://api.openalex.org/works/doi:" . rawurlencode($doi));
  if ($oa) {
    $alex_id = $oa['id'] ?? '';
    if($alex_id) {
      $result['alex_id'] = preg_replace('~^https?://openalex\.org/~', '', $alex_id);
      if(!$fast_search) {
        $oa_citations = oa_fetch_citations($result['alex_id']);
        if($oa_citations) {
          $result['alex_citations'] = $oa_citations;
        }
      }
    }
    $result['alex_refs'] = $oa;
    if (!$result['title']) {
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
  return $result;
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  $doi = normalize_doi($_GET['doi'] ?? '');
  if (!$doi) out(['error' => 'Missing DOI'], 400);
  $r = doi_lookup_fetch($doi, true);
  if (!$r) out(['error' => 'Metadata not found'], 404);
  out($r);
}
