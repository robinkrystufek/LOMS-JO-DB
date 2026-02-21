<?php
/**
 * export_entry.php
 * 
 * Unified export endpoint for a single JO record.
 * Usage:
 *   export_entry.php?id=123&type=csv
 *   export_entry.php?id=123&type=loms
 *   export_entry.php?id=123&type=citation&format=ris    (default)
 *   export_entry.php?id=123&type=citation&format=bibtex
 *   export_entry.php?id=123&type=citation&format=apa
 */
declare(strict_types=1);
require_once __DIR__ . '/config.inc.php';

function fail(string $msg, int $http = 400): void {
  http_response_code($http);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function as_int($v): ?int {
  if ($v === null) return null;
  if (is_string($v)) $v = trim($v);
  if ($v === '' || $v === null) return null;
  if (!preg_match('/^-?\d+$/', (string)$v)) return null;
  return (int)$v;
}
function host_type_label(?string $v): ?string {
  if ($v === null || $v === '') return null;
  $map = [
    'glass'          => 'Glass (G)',
    'glass_ceramic'  => 'Glass-ceramic (GC)',
    'polycrystal'    => 'Polycrystalline (PC)',
    'single_crystal' => 'Single-crystalline (SC)',
    'vapor'          => 'Vapor (V)',
    'solution'       => 'Solution (S)',
    'melt'           => 'Melt (M)',
    'powder'         => 'Powder (P)',
    'aqua'           => 'Aqueous (A)',
    'other'          => 'Other',
  ];
  return $map[$v] ?? $v;
}
function conc_unit_label(?string $v): ?string {
  if ($v === null || $v === '') return null;
  $map = ['ions/cm3' => 'ions/cm³'];
  return $map[$v] ?? $v;
}
function norm_ws(?string $s): string {
  $s = (string)($s ?? '');
  $s = trim(preg_replace('/\s+/u', ' ', $s));
  return $s;
}
function norm_authors(?string $a): string {
  $a = norm_ws($a);
  if ($a === '') return '';
  if (str_contains($a, ';')) {
    $parts = array_values(array_filter(array_map('trim', explode(';', $a)), fn($x) => $x !== ''));
    return implode(', ', $parts);
  }
  return $a;
}
function doi_url(?string $doi, ?string $url): string {
  $doi = norm_ws($doi);
  if ($doi !== '') {
    $doi = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi);
    return 'https://doi.org/' . $doi;
  }
  return norm_ws($url);
}
function bibtex_key(string $authors, string $year, string $title): string {
  $year = preg_replace('/\D+/', '', $year) ?: 'n.d.';
  $surname = 'key';
  $first = trim(explode(',', $authors)[0] ?? '');
  if ($first === '') $first = trim(explode(' ', $authors)[0] ?? '');
  if ($first !== '') $surname = preg_replace('/[^A-Za-z0-9]+/', '', $first);
  $t = strtolower($title);
  $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
  $t = trim($t);
  $w = explode(' ', $t)[0] ?? 'ref';
  $w = preg_replace('/[^a-z0-9]+/', '', $w);
  return $surname . $year . $w;
}
function escape_kv_value(string $v): string {
  $v = str_replace(["\\", "\""], ["\\\\", "\\\""], $v);
  $v = str_replace(["\r\n", "\r", "\n"], "\\n", $v);
  return $v;
}
function kv_line(string $key, $val): ?string {
  if ($val === null) return null;
  if (is_bool($val)) $val = $val ? '1' : '0';
  if (is_float($val) || is_int($val)) {
    $s = (string)$val;
  } 
  else {
    $s = trim((string)$val);
  }
  if ($s === '') return null;
  return $key . '="' . escape_kv_value($s) . '"';
}
function add_row(array &$rows, string $section, int $record_id, ?int $idx, string $field, $value): void {
  if ($value === null) return;
  if (is_string($value)) {
    $value = trim($value);
    if ($value === '') return;
  }
  $rows[] = [$section, $record_id, $idx, $field, $value];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  fail('GET required.', 405);
}
$id = as_int($_GET['id'] ?? null);
if ($id === null || $id <= 0) fail('Missing/invalid id parameter.', 400);
$type = strtolower(trim((string)($_GET['type'] ?? 'csv')));
if (!in_array($type, ['csv', 'loms', 'citation'], true)) $type = 'csv';

$format = strtolower(trim((string)($_GET['format'] ?? 'ris')));
if (!in_array($format, ['apa', 'bibtex', 'ris'], true)) $format = 'ris';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} 
catch (Throwable $e) {
  fail('Database connection failed.', 500);
}

try {
  $st = $pdo->prepare('SELECT * FROM jo_records WHERE id = :id LIMIT 1');
  $st->execute([':id' => $id]);
  $jo = $st->fetch();
  if (!$jo) fail('Record not found.', 404);
  $pub = null;
  $pub_id = (int)($jo['publication_id'] ?? 0);
  if ($pub_id > 0) {
    $stp = $pdo->prepare('SELECT * FROM publications WHERE id = :id LIMIT 1');
    $stp->execute([':id' => $pub_id]);
    $pub = $stp->fetch() ?: null;
  }
  $stc = $pdo->prepare('SELECT component, value, unit FROM jo_composition_components WHERE jo_record_id = :id ORDER BY id ASC');
  $stc->execute([':id' => $id]);
  $comp = $stc->fetchAll() ?: [];

  if ($type === 'citation') {
    if (!$pub || $pub_id <= 0) fail('No publication linked to this record.', 404);
    $authors = norm_authors($pub['authors'] ?? '');
    $year    = norm_ws((string)($pub['year'] ?? ''));
    $title   = norm_ws($pub['title'] ?? '');
    $journal = norm_ws($pub['journal'] ?? '');
    $doi     = norm_ws($pub['doi'] ?? '');
    $url     = doi_url($doi, $pub['url'] ?? '');
    $body = '';
    $ext  = 'txt';
    $ctype = 'text/plain; charset=utf-8';

    if ($format === 'apa') {
      $parts = [];
      if ($authors !== '') $parts[] = $authors;
      if ($year !== '') $parts[] = "($year).";
      if ($title !== '') $parts[] = $title . '.';
      if ($journal !== '') $parts[] = $journal . '.';
      if ($url !== '') $parts[] = $url;
      $body = trim(implode(' ', $parts));
      if ($body === '') $body = '(missing publication metadata)';
    } 
    elseif ($format === 'bibtex') {
      $key = bibtex_key($authors, $year, $title);
      $body =
        "@article{" . $key . ",\n" .
        ($authors !== '' ? "  author = {" . $authors . "},\n" : '') .
        ($title   !== '' ? "  title = {"  . $title   . "},\n" : '') .
        ($journal !== '' ? "  journal = {" . $journal . "},\n" : '') .
        ($year    !== '' ? "  year = {"   . $year    . "},\n" : '') .
        ($doi     !== '' ? "  doi = {"    . preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi) . "},\n" : '') .
        ($url     !== '' ? "  url = {"    . $url . "},\n" : '') .
        "}\n";
    } 
    else {
      $ext = 'ris';
      $ctype = 'application/x-research-info-systems; charset=utf-8';
      $body = "TY  - JOUR\n";
      if ($title !== '')   $body .= "TI  - {$title}\n";
      if ($journal !== '') $body .= "JO  - {$journal}\n";
      if ($year !== '')    $body .= "PY  - {$year}\n";
      if ($doi !== '') {
        $d = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi);
        $body .= "DO  - {$d}\n";
      }
      if ($url !== '')     $body .= "UR  - {$url}\n";
      if ($authors !== '') {
        $alist = str_contains($authors, ',') ? array_map('trim', explode(',', $authors)) : [$authors];
        foreach ($alist as $a) {
          if ($a !== '') $body .= "AU  - {$a}\n";
        }
      }
      $body .= "ER  - \n";
    }
    $fname = 'citation_pub_' . $pub_id . '_' . $format . '.' . $ext;
    header('Content-Type: ' . $ctype);
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
  }
  if ($type === 'loms') {
    $lines = [];
    $lines[] = '# LOMS JO DB export';
    $lines[] = '# Generated: ' . gmdate('Y-m-d\TH:i:s\Z');
    $lines[] = '# jo_record_id=' . $id;
    $lines[] = '';
    if ($pub) {
      if ($l = kv_line('pub_doi', $pub['doi'] ?? null)) $lines[] = $l;
      if ($l = kv_line('pub_title', $pub['title'] ?? null)) $lines[] = $l;
      if ($l = kv_line('pub_journal', $pub['journal'] ?? null)) $lines[] = $l;
      if ($l = kv_line('pub_year', $pub['year'] ?? null)) $lines[] = $l;
      if ($l = kv_line('pub_url', $pub['url'] ?? null)) $lines[] = $l;
      if ($l = kv_line('pub_authors', $pub['authors'] ?? null)) $lines[] = $l;
    }
    if ($l = kv_line('re_ion', $jo['re_ion'] ?? null)) $lines[] = $l;
    if ($l = kv_line('re_conc_value', $jo['re_conc_value'] ?? null)) $lines[] = $l;
    if ($l = kv_line('re_conc_value_upper', $jo['re_conc_value_upper'] ?? null)) $lines[] = $l;
    if ($l = kv_line('re_conc_value_note', $jo['re_conc_value_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('re_conc_unit', conc_unit_label($jo['re_conc_unit'] ?? null))) $lines[] = $l;
    if ($l = kv_line('sample_label', $jo['sample_label'] ?? null)) $lines[] = $l;
    if ($l = kv_line('host_type', host_type_label($jo['host_type'] ?? null))) $lines[] = $l;
    if ($l = kv_line('composition_text', $jo['composition_text'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega2', $jo['omega2'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega4', $jo['omega4'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega6', $jo['omega6'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega2_error', $jo['omega2_error'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega4_error', $jo['omega4_error'] ?? null)) $lines[] = $l;
    if ($l = kv_line('omega6_error', $jo['omega6_error'] ?? null)) $lines[] = $l;
    if ($l = kv_line('has_density', $jo['has_density'] ?? null)) $lines[] = $l;
    if ($l = kv_line('density_g_cm3', $jo['density_g_cm3'] ?? null)) $lines[] = $l;
    if ($l = kv_line('is_contributor_author', $jo['is_contributor_author'] ?? null)) $lines[] = $l;
    if ($l = kv_line('refractive_index_option', $jo['refractive_index_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('combinatorial_jo_option', $jo['combinatorial_jo_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('sigma_f_s_option', $jo['sigma_f_s_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('mag_dipole_option', $jo['mag_dipole_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('reduced_element_option', $jo['reduced_element_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('recalculated_loms_option', $jo['recalculated_loms_option'] ?? null)) $lines[] = $l;
    if ($l = kv_line('refractive_index_note', $jo['refractive_index_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('combinatorial_jo_note', $jo['combinatorial_jo_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('sigma_f_s_note', $jo['sigma_f_s_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('mag_dipole_note', $jo['mag_dipole_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('reduced_element_note', $jo['reduced_element_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('recalculated_loms_note', $jo['recalculated_loms_note'] ?? null)) $lines[] = $l;
    if ($l = kv_line('extra_notes', $jo['extra_notes'] ?? null)) $lines[] = $l;
    if (!empty($comp)) {
      $lines[] = '';
      $lines[] = '# Normalized composition';
      foreach ($comp as $r) {
        if ($l = kv_line('comp_component', $r['component'] ?? null)) $lines[] = $l;
        if ($l = kv_line('comp_value', $r['value'] ?? null)) $lines[] = $l;
        if ($l = kv_line('comp_unit', $r['unit'] ?? null)) $lines[] = $l;
      }
    }
    $txt = implode("\n", $lines) . "\n";
    $fname = 'jo_record_' . $id . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('X-Content-Type-Options: nosniff');
    echo $txt;
    exit;
  }

  $fname = 'jo_record_' . $id . '.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('X-Content-Type-Options: nosniff');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'wb');
  if (!$out) fail('Unable to open output stream.', 500);
  fputcsv($out, ['section', 'jo_record_id', 'item_index', 'field', 'value']);
  $rows = [];
  if ($pub) {
    add_row($rows, 'publication', $id, null, 'pub_doi', $pub['doi'] ?? null);
    add_row($rows, 'publication', $id, null, 'pub_title', $pub['title'] ?? null);
    add_row($rows, 'publication', $id, null, 'pub_authors', $pub['authors'] ?? null);
    add_row($rows, 'publication', $id, null, 'pub_journal', $pub['journal'] ?? null);
    add_row($rows, 'publication', $id, null, 'pub_year', $pub['year'] ?? null);
    add_row($rows, 'publication', $id, null, 'pub_url', $pub['url'] ?? null);
  }
  add_row($rows, 'jo_record', $id, null, 're_ion', $jo['re_ion'] ?? null);
  add_row($rows, 'jo_record', $id, null, 're_conc_value', $jo['re_conc_value'] ?? null);
  add_row($rows, 'jo_record', $id, null, 're_conc_value_upper', $jo['re_conc_value_upper'] ?? null);
  add_row($rows, 'jo_record', $id, null, 're_conc_value_note', $jo['re_conc_value_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 're_conc_unit', conc_unit_label($jo['re_conc_unit'] ?? null));
  add_row($rows, 'jo_record', $id, null, 'sample_label', $jo['sample_label'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'host_type', host_type_label($jo['host_type'] ?? null));
  add_row($rows, 'jo_record', $id, null, 'composition_text', $jo['composition_text'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega2', $jo['omega2'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega4', $jo['omega4'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega6', $jo['omega6'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega2_error', $jo['omega2_error'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega4_error', $jo['omega4_error'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'omega6_error', $jo['omega6_error'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'has_density', $jo['has_density'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'density_g_cm3', $jo['density_g_cm3'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'is_contributor_author', $jo['is_contributor_author'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'refractive_index_option', $jo['refractive_index_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'combinatorial_jo_option', $jo['combinatorial_jo_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'sigma_f_s_option', $jo['sigma_f_s_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'mag_dipole_option', $jo['mag_dipole_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'reduced_element_option', $jo['reduced_element_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'recalculated_loms_option', $jo['recalculated_loms_option'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'refractive_index_note', $jo['refractive_index_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'combinatorial_jo_note', $jo['combinatorial_jo_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'sigma_f_s_note', $jo['sigma_f_s_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'mag_dipole_note', $jo['mag_dipole_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'reduced_element_note', $jo['reduced_element_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'recalculated_loms_note', $jo['recalculated_loms_note'] ?? null);
  add_row($rows, 'jo_record', $id, null, 'extra_notes', $jo['extra_notes'] ?? null);
  $i = 0;
  foreach ($comp as $r) {
    $i++;
    add_row($rows, 'component', $id, $i, 'component', $r['component'] ?? null);
    add_row($rows, 'component', $id, $i, 'value', $r['value'] ?? null);
    add_row($rows, 'component', $id, $i, 'unit', $r['unit'] ?? null);
  }
  foreach ($rows as $r) {
    fputcsv($out, $r);
  }
  fclose($out);
  exit;
} catch (Throwable $e) {
  fail('Server error: ' . $e->getMessage(), 500);
}
