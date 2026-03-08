<?php
/**
 * get_records.php
 *
 * Search/browse endpoint for JO records.
 * Applies filters (publication, composition, badges, advanced rules)
 * and returns paginated results as JSON for the frontend.
 * Shared filtering logic lives in jo_records.inc.php.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require 'config.inc.php';
require 'records.inc.php';
function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function format_value($val) {
  if (!is_numeric($val)) return $val;
  $map_to_superscript = ['0'=>'⁰','1'=>'¹','2'=>'²','3'=>'³','4'=>'⁴','5'=>'⁵','6'=>'⁶','7'=>'⁷','8'=>'⁸','9'=>'⁹','-'=>'⁻'];
  $val = (float)$val;
  if (abs($val) >= 1000) {
    $exp = floor(log10(abs($val)));
    $mant = $val / (10 ** $exp);
    $mantStr = rtrim(rtrim(number_format($mant, 3, '.', ''), '0'), '.');
    return $mantStr . ' × 10' . strtr((string)$exp, $map_to_superscript);
  }
  $v = (string)$val;
  if (str_contains($v, '.')) $v = rtrim(rtrim($v, '0'), '.');
  return $v;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$perPage = min(50, max(1, $perPage));
$offset = ($page - 1) * $perPage;
$sortByReq  = trim((string)($_GET['sort_by'] ?? 'id'));
$sortDirReq = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
$sortMap = [
  'id'             => 'r.id',
  're_ion'         => 'r.re_ion',
  'concentration'  => 'r.re_conc_value',
  'composition'    => 'r.composition_text',
  'host'           => 'r.host_type',
  'omega2'         => 'r.omega2',
  'omega4'         => 'r.omega4',
  'omega6'         => 'r.omega6',
  'pub_year'       => 'p.year',
];
$orderExpr = $sortMap[$sortByReq] ?? 'r.id';
$orderDir  = ($sortDirReq === 'asc') ? 'ASC' : 'DESC';

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
  apply_filters($_GET, $where, $params);
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  $stCount = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM jo_records r
    JOIN publications p ON p.id = r.publication_id
    $whereSql
  ");
  $stCount->execute($params);
  $total = (int)($stCount->fetch()['c'] ?? 0);
  $totalPages = max(1, (int)ceil($total / $perPage));
  if ($page > $totalPages) { 
    $page = $totalPages; 
    $offset = ($page - 1) * $perPage; 
  }

  $sql = "
    SELECT
      r.id AS jo_record_id,
      r.publication_id,
      r.contributor_info,
      r.re_ion,
      r.re_conc_value,
      r.re_conc_value_upper,
      r.re_conc_value_note,
      r.re_conc_unit,
      r.host_type,
      r.composition_text,
      r.omega2, r.omega4, r.omega6,
      r.omega2_error,
      r.omega4_error,
      r.omega6_error,
      r.jo_recalc_by_loms,
      r.sample_label,
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
      r.has_density,
      r.density_g_cm3,
      r.extra_notes,
      r.review_status,
      p.doi AS pub_doi,
      p.title AS pub_title,
      p.journal AS pub_journal,
      p.authors AS pub_authors,
      p.year AS pub_year,
      p.url AS pub_url
    FROM jo_records r
    JOIN publications p ON p.id = r.publication_id
    $whereSql
    ORDER BY $orderExpr $orderDir, r.id DESC
    LIMIT :lim OFFSET :off
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();

  $recordIds = array_map(fn($r) => (int)$r['jo_record_id'], $rows);
  $componentsByRecord = [];
  $elementalCompositionByRecord = [];
  if ($recordIds) {
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $stC = $pdo->prepare("
      SELECT 
        cc.jo_record_id AS jo_record_id,
        cc.component AS component,
        cc.value AS value,
        cc.unit AS unit,
        cc.calc_mol AS calc_mol,
        cc.calc_wt AS calc_wt,
        cc.calc_at AS calc_at,
        jc.cid AS cid,
        jc.pubchem_name AS pubchem_name,
        jc.mw AS mw,
        jc.atom_number AS atom_number,
        jc.composition AS composition,
        jc.pubchem_details AS pubchem_details
      FROM jo_composition_components cc
      LEFT JOIN jo_components jc 
        ON jc.id = cc.component_id
      WHERE cc.jo_record_id IN ($placeholders)
      ORDER BY cc.jo_record_id ASC, cc.id ASC
    ");
    $stC->execute($recordIds);
    while ($cc = $stC->fetch()) {
      $rid = (int)$cc['jo_record_id'];
      if (!isset($componentsByRecord[$rid])) $componentsByRecord[$rid] = [];
      $componentsByRecord[$rid][] = [
        'component'       => $cc['component'],
        'value'           => $cc['value'],
        'unit'            => $cc['unit'],
        'c_mol'           => $cc['calc_mol'],
        'c_wt'            => $cc['calc_wt'],
        'c_at'            => $cc['calc_at'],
        'cid'             => $cc['cid'],
        'pubchem_name'    => $cc['pubchem_name'],
        'mw'              => $cc['mw'],
        'atom_number'     => $cc['atom_number'],
        'composition'     => $cc['composition'],
        'pubchem_details' => json_decode($cc['pubchem_details']),
      ];
    }
    $stE = $pdo->prepare("
      SELECT
        record_id,
        element,
        c_mol,
        c_wt,
        re_c,
        re_c_unit
      FROM jo_composition_elements
      WHERE record_id IN ($placeholders)
      ORDER BY record_id ASC, element ASC
    ");
    $stE->execute($recordIds);
    while ($er = $stE->fetch()) {
      $rid = (int)$er['record_id'];
      if (!isset($elementalCompositionByRecord[$rid])) {
        $elementalCompositionByRecord[$rid] = [];
      }
      $elementalCompositionByRecord[$rid][$er['element']] = [
        'c_mol'     => $er['c_mol'],
        'c_wt'      => $er['c_wt'],
        'c_at'      => $er['c_mol'],
        're_c'      => $er['re_c'],
        're_c_unit' => $er['re_c_unit'],
      ];
    }
  }

  $items = array_map(function(array $r) use ($componentsByRecord, $elementalCompositionByRecord) {
    $host_type = $r['host_type'] ?? 'other';
    $hostDetails = trim(
      ucfirst(host_type_label($host_type) ?? $host_type)
    );
    $conc = '';
    if ($r['re_conc_value'] !== null && $r['re_conc_value'] !== '') {
      $v = format_value($r['re_conc_value']);
      $range = $v;
      if ($r['re_conc_value_upper'] !== null && $r['re_conc_value_upper'] !== '') {
        $vu = format_value($r['re_conc_value_upper']);
        $range .= '–' . $vu;
      }
      $conc = trim($range . ' ' . conc_unit_label($r['re_conc_unit']));
    }

    $badges = ["n","C-JO","σFS","MD","RME","LOMS","ρ"];
    $badges_states = [1,1,1,1,1,1,1];
    $badges_notes = ["","","","","","",""];

    if (isset($r['refractive_index_option'])) $badges_states[0] = $r['refractive_index_option'];
    if (!empty($r['refractive_index_note'])) $badges_notes[0] = $r['refractive_index_note'];
    if (isset($r['combinatorial_jo_option'])) $badges_states[1] = $r['combinatorial_jo_option'];
    if (!empty($r['combinatorial_jo_note'])) $badges_notes[1] = $r['combinatorial_jo_note'];
    if (isset($r['sigma_f_s_option'])) $badges_states[2] = $r['sigma_f_s_option'];
    if (!empty($r['sigma_f_s_note'])) $badges_notes[2] = $r['sigma_f_s_note'];
    if (isset($r['mag_dipole_option'])) $badges_states[3] = $r['mag_dipole_option'];
    if (!empty($r['mag_dipole_note'])) $badges_notes[3] = $r['mag_dipole_note'];
    if (isset($r['reduced_element_option'])) $badges_states[4] = $r['reduced_element_option'];
    if (!empty($r['reduced_element_note'])) $badges_notes[4] = $r['reduced_element_note'];
    if (isset($r['recalculated_loms_option'])) $badges_states[5] = $r['recalculated_loms_option'];
    if (!empty($r['recalculated_loms_note'])) $badges_notes[5] = $r['recalculated_loms_note'];
    if (isset($r['has_density'])) $badges_states[6] = $r['has_density'];
    if (!empty($r['density_g_cm3'])) $badges_notes[6] = $r['density_g_cm3']. " g/cm³";

    $lomsPath = extract_file_path((string)($r['extra_notes'] ?? ''));
    $lomsUrl = ($lomsPath && $r['recalculated_loms_option']==2) ? ("https://www.loms.cz/jo-db/" . $lomsPath) : null;
    $rid = (int)$r['jo_record_id'];

    return [
      'jo_record_id' => $rid,
      'publication_id' => (int)$r['publication_id'],
      're_ion' => $r['re_ion'],
      'omega2' => $r['omega2'],
      'omega4' => $r['omega4'],
      'omega6' => $r['omega6'],
      'omega2_error' => $r['omega2_error'],
      'omega4_error' => $r['omega4_error'],
      'omega6_error' => $r['omega6_error'],
      'concentration' => $conc,
      'concentration_lower' => $r['re_conc_value'] ?? '',
      'concentration_upper' => $r['re_conc_value_upper'] ?? '',
      'concentration_unit' => $r['re_conc_unit'] ?? '',
      'concentration_note' => $r['re_conc_value_note'] ?? '',
      'host' => $hostDetails,
      'composition' => $r['composition_text'],
      'badges' => $badges,
      'badges_notes' => $badges_notes,
      'badges_states' => $badges_states,
      'has_density' => $r['has_density'],
      'density' => $r['density_g_cm3'],
      'sample_label' => $r['sample_label'] ?? '',
      'notes' => strip_db_tags($r['extra_notes']) ?? '',
      'doi' => $r['pub_doi'] ?? '',
      'pub_title' => $r['pub_title'] ?? '',
      'pub_authors' => $r['pub_authors'] ?? '',
      'pub_year' => $r['pub_year'] ?? '',
      'pub_journal' => $r['pub_journal'] ?? '',
      'pub_url' => $r['pub_url'] ?? '',
      'review_status' => $r['review_status'] ?? '',
      'details' => [
        'contributor' => $r['contributor_info'] ?? '',
        'composition_components' => $componentsByRecord[$rid] ?? [],
        'elemental_composition' => $elementalCompositionByRecord[$rid] ?? [],
        'loms_file_url' => $lomsUrl,
      ],
    ];
  }, $rows);
  respond(200, [
    'ok' => true,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'total_pages' => $totalPages,
    'items' => $items,
  ]);
} 
catch (Throwable $e) {
  respond(500, ['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}
