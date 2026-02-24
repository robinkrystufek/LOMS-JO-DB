<?php
/**
 * browse_records.php
 *
 * Search/browse endpoint for JO records.
 * Applies filters (publication, composition, badges, advanced rules)
 * and returns paginated results as JSON for the frontend.
 * Shared filtering logic lives in jo_records.inc.php.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';
require_once __DIR__ . '/jo_records.inc.php';

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$perPage = min(50, max(1, $perPage));
$offset = ($page - 1) * $perPage;
$sortByReq  = trim((string)($_GET['sort_by'] ?? 'id'));
$sortDirReq = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
$sortMap = [
  'id'            => 'r.id',
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
  if ($reIon !== '') { 
    $where[] = "r.re_ion LIKE '%".$reIon."%'"; 
  }
  if ($hostType !== '') { $where[] = "r.host_type = :host_type"; $params[':host_type'] = $hostType; }
  if ($hostFamily !== '') { $where[] = "r.host_family = :host_family"; $params[':host_family'] = $hostFamily; }
  if ($compositionQ !== '') {
    $where[] = "(
      r.re_ion LIKE :composition_q_re_ion OR 
      r.composition_text LIKE :composition_q_text OR 
      EXISTS (
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE :composition_q_component
      ))";
    $params[':composition_q_re_ion'] = '%' . $compositionQ . '%';
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
      r.temperature_k,
      r.lambda_min_nm,
      r.lambda_max_nm,
      r.method,
      r.omega2, r.omega4, r.omega6,
      r.omega2_error,
      r.omega4_error,
      r.omega6_error,
      r.omega_unit,
      r.jo_original_paper,
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

      r.has_absorption_spec,
      r.has_emission_spec,
      r.has_n_spectrum,
      r.has_n_parameters,
      r.has_density,
      r.has_lifetime,
      r.has_branching_ratios,
      r.has_transmission_spec,

      r.density_g_cm3,
      r.n_546nm,
      r.n_633nm,
      r.dispersion_model,
      r.dispersion_a,
      r.dispersion_b,
      r.dispersion_c,
      r.extra_notes,

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
  if ($recordIds) {
    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $stC = $pdo->prepare("
      SELECT jo_record_id, component, value, unit
      FROM jo_composition_components
      WHERE jo_record_id IN ($placeholders)
      ORDER BY jo_record_id ASC, id ASC
    ");
    $stC->execute($recordIds);
    while ($cc = $stC->fetch()) {
      $rid = (int)$cc['jo_record_id'];
      if (!isset($componentsByRecord[$rid])) $componentsByRecord[$rid] = [];
      $componentsByRecord[$rid][] = [
        'component' => $cc['component'],
        'value' => $cc['value'],
        'unit' => $cc['unit'],
      ];
    }
  }

  $hostTypeLabel = [
    'glass' => 'Glass (G)',
    'single_crystal' => 'Single-crystalline (SC)',
    'polycrystal' => 'Polycrystalline (PC)',
    'glass_ceramic' => 'Glass-ceramic (GC)',
    'vapor' => 'Vapor (V)',
    'solution' => 'Solution (S)',
    'melt' => 'Melt (M)',
    'powder' => 'Powder (P)',
    'aqua' => 'Aqueous (A)',
    'other' => 'Other',
  ];

  $items = array_map(function(array $r) use ($hostTypeLabel, $methodLabel, $componentsByRecord) {
    $host_type = $r['host_type'] ?? 'other';
    $hostDetails = trim(
      ucfirst($hostTypeLabel[$host_type] ?? $host_type)
    );
    $conc = '';
    if ($r['re_conc_value'] !== null && $r['re_conc_value'] !== '') {
      $v = (string)$r['re_conc_value'];
      if (str_contains($v, '.')) $v = rtrim(rtrim($v, '0'), '.');
      $range = $v;
      if ($r['re_conc_value_upper'] !== null && $r['re_conc_value_upper'] !== '') {
        $vu = (string)$r['re_conc_value_upper'];
        if (str_contains($vu, '.')) $vu = rtrim(rtrim($vu, '0'), '.');
        $range .= '–' . $vu;
      }
      $conc = trim($range . ' ' . ($r['re_conc_unit'] ?? ''));
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

    $lomsPath = extractFilePath((string)($r['extra_notes'] ?? ''));
    $lomsUrl = ($lomsPath && $r['recalculated_loms_option']==2) ? ("https://www.loms.cz/jo-db/" . $lomsPath) : null;

    $pubLine = '';
    if (!empty($r['pub_journal'])) $pubLine = $r['pub_journal'];
    if (!empty($r['pub_year'])) $pubLine .= " ({$r['pub_year']})";
    if (!empty($r['pub_doi'])) $pubLine .= ", DOI <a href='https://doi.org/" . $r['pub_doi']. "' target='_blank'>" . $r['pub_doi'] . "</a>";
    $pubLine = trim($pubLine, " ,");
    $omegaUnit = $r['omega_unit'] ?: '10⁻²⁰ cm²';
    $extras = [];
    if (!empty($r['has_density']) && $r['density_g_cm3'] !== null) $extras[] = "ρ = {$r['density_g_cm3']} g/cm³";
    if (!empty($r['extra_notes'])) $extras[] = trim((string)$r['extra_notes']);
    $rid = (int)$r['jo_record_id'];

    return [
      'jo_record_id' => $rid,
      'publication_id' => (int)$r['publication_id'],

      // table row fields
      're_ion' => $r['re_ion'],
      'host_short' => $hostDetails,
      'composition_short' => $r['composition_text'],
      'omega2' => $r['omega2'],
      'omega4' => $r['omega4'],
      'omega6' => $r['omega6'],
      'omega2_error' => $r['omega2_error'],
      'omega4_error' => $r['omega4_error'],
      'omega6_error' => $r['omega6_error'],
      'concentration' => $conc,
      'concentration_note' => $r['re_conc_value_note'] ?? '',
      'badges' => $badges,
      'badges_notes' => $badges_notes,
      'badges_states' => $badges_states,
      'has_density' => $r['has_density'],
      'density' => $r['density_g_cm3'],
      // normalized composition
      'composition_components' => $componentsByRecord[$rid] ?? [],
      'sample_label' => $r['sample_label'] ?? '',
      'notes' => stripFilePathTag($r['extra_notes']) ?? '',
      'doi' => $r['pub_doi'] ?? '',
      'pub_title' => $r['pub_title'] ?? '',
      'pub_authors' => $r['pub_authors'] ?? '',
      'pub_year' => $r['pub_year'] ?? '',
      'pub_journal' => $r['pub_journal'] ?? '',
      'pub_url' => $r['pub_url'] ?? '',
      // details
      'details' => [
        'publication' => $pubLine,
        'contributor' => $r['contributor_info'] ?? '',
        'host' => $hostDetails,
        'composition' => $r['composition_text'],
        'composition_components' => $componentsByRecord[$rid] ?? [],
        'jo_parameters' => [
          'omega2' => $r['omega2'],
          'omega4' => $r['omega4'],
          'omega6' => $r['omega6'],
          'unit' => $omegaUnit,
        ],
        'extras' => implode('; ', array_filter($extras)),
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
