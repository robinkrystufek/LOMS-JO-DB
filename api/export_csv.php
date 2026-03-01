<?php
/**
 * export_csv.php
 *
 * Bulk export endpoint for JO records.
 * Applies the same filtering logic as browse_records.php
 * without paging and outputs matching records as a CSV file.
 */
declare(strict_types=1);
include 'config.inc.php';
require_once __DIR__ . '/jo_records.inc.php';
function csv_cell($v): string {
  if ($v === null) return '';
  $s = (string)$v;
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  if (strpbrk($s, "\"\n,") !== false) {
    $s = '"' . str_replace('"', '""', $s) . '"';
  }
  return $s;
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
  $map = [
    'ions/cm3' => 'ions/cm³',
  ];
  return $map[$v] ?? $v;
}

$reIon        = trim((string)($_GET['re_ion'] ?? ''));
$hostType     = trim((string)($_GET['host_type'] ?? ''));
$hostFamily   = trim((string)($_GET['host_family'] ?? ''));
$compositionQ = trim((string)($_GET['composition_q'] ?? ''));
$elementQ     = trim((string)($_GET['element_q'] ?? ''));

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
  $where = [];
  $params = [];

  if ($reIon !== '') { $where[] = "r.re_ion = :re_ion"; $params[':re_ion'] = $reIon; }
  if ($hostType !== '') { $where[] = "r.host_type = :host_type"; $params[':host_type'] = $hostType; }
  if ($hostFamily !== '') { $where[] = "r.host_family = :host_family"; $params[':host_family'] = $hostFamily; }
  if ($compositionQ !== '') {
    $where[] = "(
      r.re_ion LIKE :composition_q_re_ion OR 
      r.sample_label LIKE :composition_q_label OR 
      r.composition_text LIKE :composition_q_text OR 
      EXISTS (
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE :composition_q_component
      ))";
    $params[':composition_q_re_ion'] = '%' . $compositionQ . '%';
    $params[':composition_q_label'] = '%' . $compositionQ . '%';
    $params[':composition_q_text'] = '%' . $compositionQ . '%';
    $params[':composition_q_component'] = '%' . $compositionQ . '%';
  }
  if ($elementQ !== '') {
    $elementQ = ucfirst(strtolower(trim($elementQ)));
    if (preg_match('/^[A-Z][a-z]?$/', $elementQ)) {
      $where[] = "(
        REGEXP_LIKE(r.re_ion, :composition_regex, 'c')
        OR EXISTS (
          SELECT 1 FROM jo_composition_components cc
          WHERE cc.jo_record_id = r.id
            AND REGEXP_LIKE(cc.component, :component_regex, 'c')
        ))";
      $params[':composition_regex'] = $elementQ . '(?![a-z])';
      $params[':component_regex']   = $elementQ . '(?![a-z])';
    }
  }
  jo_apply_badge_filters($_GET, $where, $params);
  jo_apply_publication_filters($_GET, $where);
  jo_apply_advanced_composition_rules($_GET, $where, $params);
  $where[] = "r.review_status='approved'";
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  $sql = "
    SELECT
      r.id AS jo_record_id,
      r.publication_id,
      r.re_ion,
      r.re_conc_value,
      r.re_conc_value_upper,
      r.re_conc_value_note,
      r.re_conc_unit,
      r.sample_label,
      r.host_type,
      r.composition_text,
      r.omega2,
      r.omega4,
      r.omega6,
      r.omega2_error,
      r.omega4_error,
      r.omega6_error,
      r.has_density,
      r.density_g_cm3,
      r.is_contributor_author,
      r.refractive_index_option,
      r.combinatorial_jo_option,
      r.sigma_f_s_option,
      r.mag_dipole_option,
      r.reduced_element_option,
      r.recalculated_loms_option,
      r.refractive_index_note,
      r.combinatorial_jo_note,
      r.sigma_f_s_note,
      r.mag_dipole_note,
      r.reduced_element_note,
      r.recalculated_loms_note,
      r.extra_notes,
      p.doi AS pub_doi,
      p.title AS pub_title,
      p.journal AS pub_journal,
      p.year AS pub_year,
      p.url AS pub_url,
      p.authors AS pub_authors,
      (
        SELECT GROUP_CONCAT(
          CONCAT(cc.component, '=', cc.value, ' ', cc.unit)
          ORDER BY cc.id ASC SEPARATOR ' | '
        )
        FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
      ) AS components
    FROM jo_records r
    JOIN publications p ON p.id = r.publication_id
    $whereSql
    ORDER BY r.id DESC
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->execute();

  $filename = "jo_export_" . date('Y-m-d_His') . ".csv";
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header('Pragma: no-cache');
  header('Expires: 0');
  echo "\xEF\xBB\xBF";
  $cols = [
    'jo_record_id',
    'pub_doi','pub_title','pub_journal','pub_year','pub_url','pub_authors',
    're_ion',
    're_conc_value','re_conc_value_upper','re_conc_value_note','re_conc_unit',
    'sample_label',
    'host_type',
    'composition_text',
    'omega2','omega4','omega6',
    'omega2_error','omega4_error','omega6_error',
    'has_density','density_g_cm3',
    'is_contributor_author',
    'refractive_index_option','refractive_index_note',
    'combinatorial_jo_option','combinatorial_jo_note',
    'sigma_f_s_option','sigma_f_s_note',
    'mag_dipole_option','mag_dipole_note',
    'reduced_element_option','reduced_element_note',
    'recalculated_loms_option','recalculated_loms_note',
    'components',
    'extra_notes'
  ];
  echo implode(',', array_map('csv_cell', $cols)) . "\n";
  while ($r = $st->fetch()) {
    $row = [
      $r['jo_record_id'],
      $r['pub_doi'] ?? null,
      $r['pub_title'] ?? null,
      $r['pub_journal'] ?? null,
      $r['pub_year'] ?? null,
      $r['pub_url'] ?? null,
      $r['pub_authors'] ?? null,
      $r['re_ion'],
      $r['re_conc_value'],
      $r['re_conc_value_upper'],
      $r['re_conc_value_note'],
      conc_unit_label($r['re_conc_unit'] ?? null),
      $r['sample_label'],
      host_type_label($r['host_type'] ?? null),
      $r['composition_text'],
      $r['omega2'],
      $r['omega4'],
      $r['omega6'],
      $r['omega2_error'],
      $r['omega4_error'],
      $r['omega6_error'],
      $r['has_density'],
      $r['density_g_cm3'],
      $r['is_contributor_author'],
      $r['refractive_index_option'],
      $r['refractive_index_note'],
      $r['combinatorial_jo_option'],
      $r['combinatorial_jo_note'],
      $r['sigma_f_s_option'],
      $r['sigma_f_s_note'],
      $r['mag_dipole_option'],
      $r['mag_dipole_note'],
      $r['reduced_element_option'],
      $r['reduced_element_note'],
      $r['recalculated_loms_option'],
      $r['recalculated_loms_note'],
      $r['components'] ?? null,
      str_replace(["\r\n", "\r", "\n"], "\\n", (string)($r['extra_notes'] ?? '')),
    ];
    echo implode(',', array_map('csv_cell', $row)) . "\n";
  }
  exit;
} 
catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
?>