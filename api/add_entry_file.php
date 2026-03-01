<?php
/**
 * add_entry_file.php
 *
 * Alternative entry endpoint that accepts a structured text file
 * containing JO record data instead of JSON/form fields.
 * Parses the file and inserts the record using the same logic
 * as add_entry.php.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';
include 'add_entry.inc.php';
$UPLOAD_BASE = dirname(__DIR__) . '/uploads/loms';    // uploads/loms/<jo_record_id>/
$MAX_UPLOAD_BYTES = 20 * 1024 * 1024;                 // 20 MB
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_fail('POST required.', 405);
}
if (!isset($_FILES['jo_db_file_file_format']) || !is_array($_FILES['jo_db_file_file_format'])) {
  json_fail('Missing required upload: jo_db_file_file_format');
}
$fEntry = $_FILES['jo_db_file_file_format'];
if (($fEntry['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
  json_fail('Entry file upload error code: ' . (string)($fEntry['error'] ?? 'unknown'), 400);
}
if (!isset($fEntry['tmp_name']) || !is_uploaded_file($fEntry['tmp_name'])) {
  json_fail('Invalid entry file upload.', 400);
}
if ((int)($fEntry['size'] ?? 0) > $MAX_UPLOAD_BYTES) {
  json_fail('Entry file too large.', 400);
}
$entryText = file_get_contents($fEntry['tmp_name']);
if ($entryText === false) json_fail('Failed to read entry file.', 500);
$kv = parse_kv_file($entryText);

$contributor_info = as_trimmed($_POST['contributor_info'] ?? null);
$contributor_email = as_trimmed($_POST['contributor_info_email'] ?? null);
$contributor_name  = as_trimmed($_POST['contributor_info_name'] ?? null);
$contributor_aff   = as_trimmed($_POST['contributor_info_affiliation'] ?? null);
$contributor_orcid = as_trimmed($_POST['contributor_info_orcid'] ?? null);
if ($contributor_info === null) json_fail('Contributor info is required (contributor_info).');
$is_contributor_author = bool01(as_trimmed(kv_get_first($kv, 'is_contributor_author')));

$doi     = as_trimmed(kv_get_first($kv, 'pub_doi'));
$alex_id = as_trimmed(kv_get_first($kv, 'alex_id'));
$title   = as_trimmed(kv_get_first($kv, 'pub_title'));
$journal = as_trimmed(kv_get_first($kv, 'pub_journal'));
$year    = to_int(as_trimmed(kv_get_first($kv, 'pub_year')));
$url     = as_trimmed(kv_get_first($kv, 'pub_url'));
$authors = as_trimmed(kv_get_first($kv, 'pub_authors'));

$article_metadata = '{}';
$alex_refs = '{}';
$alex_citations = '{}';
if ($doi !== null) {
  require_once __DIR__ . '/doi_lookup.php';
  try {
    $doi = normalize_doi($doi);
    $lookup = doi_lookup_fetch($doi);
    if (is_array($lookup)) {
      $article_metadata = json_encode((object)($lookup['raw'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
      $alex_refs = json_encode((object)($lookup['alex_refs'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
      $alex_citations = json_encode((object)($lookup['alex_citations'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
      if ($alex_id === null || $alex_id === '')   $alex_id = as_trimmed($lookup['alex_id'] ?? null);
      if ($title === null || $title === '')       $title   = as_trimmed($lookup['title'] ?? null);
      if ($authors === null || $authors === '')   $authors = as_trimmed($lookup['authors'] ?? null);
      if ($journal === null || $journal === '')   $journal = as_trimmed($lookup['journal'] ?? null);
      if ($url === null || $url === '')           $url     = as_trimmed($lookup['url'] ?? null);
      if (($year === null || $year === 0) && !empty($lookup['year'])) $year = to_int((string)$lookup['year']);
    }
  } catch (Throwable $e) {
      // Non-fatal: if DOI lookup fails, we can still proceed with whatever metadata we have
  }
}

$re_ion = as_trimmed(kv_get_first($kv, 're_ion'));
$re_conc_value = to_float(as_trimmed(kv_get_first($kv, 're_conc_value')));
$re_conc_value_upper = to_float(as_trimmed(kv_get_first($kv, 're_conc_value_upper')));
$re_conc_value_note = as_trimmed(kv_get_first($kv, 're_conc_value_note'));
$re_conc_unit = map_conc_unit(as_trimmed(kv_get_first($kv, 're_conc_unit')));
$sample_label = as_trimmed(kv_get_first($kv, 'sample_label'));
$host_type = map_host_type(as_trimmed(kv_get_first($kv, 'host_type')));
$composition_text = as_trimmed(kv_get_first($kv, 'composition_text'));
$omega2 = to_float(as_trimmed(kv_get_first($kv, 'omega2')));
$omega4 = to_float(as_trimmed(kv_get_first($kv, 'omega4')));
$omega6 = to_float(as_trimmed(kv_get_first($kv, 'omega6')));
$omega2_error = to_float(as_trimmed(kv_get_first($kv, 'omega2_error')));
$omega4_error = to_float(as_trimmed(kv_get_first($kv, 'omega4_error')));
$omega6_error = to_float(as_trimmed(kv_get_first($kv, 'omega6_error')));
$has_density = to_int(as_trimmed(kv_get_first($kv, 'has_density')));
$density_g_cm3 = to_float(as_trimmed(kv_get_first($kv, 'density_g_cm3')));
if ($density_g_cm3 === null) $density_g_cm3 = to_float(as_trimmed(kv_get_first($kv, 'density')));

$refractive_index_option = to_int(as_trimmed(kv_get_first($kv, 'refractive_index_option')));
$combinatorial_jo_option = to_int(as_trimmed(kv_get_first($kv, 'combinatorial_jo_option')));
$sigma_f_s_option        = to_int(as_trimmed(kv_get_first($kv, 'sigma_f_s_option')));
$mag_dipole_option       = to_int(as_trimmed(kv_get_first($kv, 'mag_dipole_option')));
$reduced_element_option  = to_int(as_trimmed(kv_get_first($kv, 'reduced_element_option')));
$recalculated_loms_option = to_int(as_trimmed(kv_get_first($kv, 'recalculated_loms_option')));
$refractive_index_note  = as_trimmed(kv_get_first($kv, 'refractive_index_note'));
$combinatorial_jo_note  = as_trimmed(kv_get_first($kv, 'combinatorial_jo_note'));
$sigma_f_s_note         = as_trimmed(kv_get_first($kv, 'sigma_f_s_note'));
$mag_dipole_note        = as_trimmed(kv_get_first($kv, 'mag_dipole_note'));
$reduced_element_note   = as_trimmed(kv_get_first($kv, 'reduced_element_note'));
$recalculated_loms_note = as_trimmed(kv_get_first($kv, 'recalculated_loms_note'));
$extra_notes = as_trimmed(kv_get_first($kv, 'extra_notes'));
if ($extra_notes === null) $extra_notes = as_trimmed(kv_get_first($kv, 'notes'));

if ($has_density === null) $has_density = 0;
if ($is_contributor_author === null) $is_contributor_author = 0;
if ($refractive_index_option === null) $refractive_index_option = 0;
if ($combinatorial_jo_option === null) $combinatorial_jo_option = 0;
if ($sigma_f_s_option === null) $sigma_f_s_option = 0;
if ($mag_dipole_option === null) $mag_dipole_option = 0;
if ($reduced_element_option === null) $reduced_element_option = 0;
if ($recalculated_loms_option === null) $recalculated_loms_option = 0;
if ($title === null) json_fail('Publication title is required (pub_title).');
if ($re_ion === null) json_fail('RE ion is required (re_ion).');
if ($composition_text === null) json_fail('Composition text is required (composition_text).');
if ($host_type === null) json_fail('Host type is required (host_type).');

$comp_components = kv_get_all($kv, 'comp_component');
$comp_values     = kv_get_all($kv, 'comp_value');
$comp_units      = kv_get_all($kv, 'comp_unit');
$compRows = [];
$tripN = min(count($comp_components), count($comp_values), count($comp_units));
for ($i = 0; $i < $tripN; $i++) {
  $c = as_trimmed($comp_components[$i] ?? null);
  $u = as_trimmed($comp_units[$i] ?? null);
  $v = to_float($comp_values[$i] ?? null);
  if ($c === null || $u === null || $v === null) continue;
  if (!in_array($u, ['mol%','wt%','at%'], true)) continue;
  $compRows[] = ['component' => $c, 'value' => $v, 'unit' => $u];
}
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
  json_fail('Database connection failed.', 500);
}

try {
  $pdo->beginTransaction();
  if ($contributor_email !== null) {
    $sql = "
      INSERT INTO jo_contributors (uid, email, name, affiliation, orcid)
      VALUES (:uid, :email, :name, :affiliation, :orcid)
      ON DUPLICATE KEY UPDATE
        email = VALUES(email),
        name = VALUES(name),
        affiliation = VALUES(affiliation),
        orcid = VALUES(orcid)
    ";
    $stmtC = $pdo->prepare($sql);
    $stmtC->execute([
      ':uid' => $contributor_info,
      ':email' => $contributor_email,
      ':name' => $contributor_name,
      ':affiliation' => $contributor_aff,
      ':orcid' => $contributor_orcid,
    ]);
  }
  $publication_id = null;
  if ($doi !== null) {
    $stmt = $pdo->prepare("SELECT id FROM publications WHERE doi = :doi LIMIT 1");
    $stmt->execute([':doi' => $doi]);
    $row = $stmt->fetch();
    if ($row && isset($row['id'])) {
      $publication_id = (int)$row['id'];
      $upd = $pdo->prepare("
        UPDATE publications
        SET alex_id = :alex_id,
            title = :title,
            journal = :journal,
            year = :year,
            url = :url,
            authors = :authors,
            metadata = :metadata,
            alex_refs = :alex_refs,
            alex_citations = :alex_citations
        WHERE id = :id
      ");
      $upd->execute([
        ':alex_id' => $alex_id,
        ':title' => $title,
        ':journal' => $journal,
        ':year' => $year,
        ':url' => $url,
        ':id' => $publication_id,
        ':authors' => $authors,
        ':metadata' => $article_metadata,
        ':alex_refs' => $alex_refs,
        ':alex_citations' => $alex_citations
      ]);
    }
  }
  if ($publication_id === null) {
    $ins = $pdo->prepare("
      INSERT INTO publications (doi, alex_id, title, journal, year, url, authors, metadata, alex_refs, alex_citations)
      VALUES (:doi, :alex_id, :title, :journal, :year, :url, :authors, :metadata, :alex_refs, :alex_citations)
    ");
    $ins->execute([
      ':doi' => $doi,
      ':alex_id' => $alex_id,
      ':title' => $title,
      ':journal' => $journal,
      ':year' => $year,
      ':url' => $url,
      ':authors' => $authors,
      ':metadata' => $article_metadata,
      ':alex_refs' => $alex_refs,
      ':alex_citations' => $alex_citations
    ]);
    $publication_id = (int)$pdo->lastInsertId();
    if ($publication_id <= 0) json_fail('Failed to create publication.', 500);
  }
  $stmt = $pdo->prepare("
    INSERT INTO jo_records (
      publication_id,
      contributor_info,
      re_ion,
      re_conc_value,
      re_conc_value_upper,
      re_conc_value_note,
      re_conc_unit,
      sample_label,
      host_type,
      composition_text,
      omega2,
      omega4,
      omega6,
      omega2_error,
      omega4_error,
      omega6_error,
      has_density,
      density_g_cm3,
      extra_notes,
      is_contributor_author,
      review_status,
      refractive_index_option,
      combinatorial_jo_option,
      sigma_f_s_option,
      mag_dipole_option,
      reduced_element_option,
      recalculated_loms_option,
      refractive_index_note,
      combinatorial_jo_note,
      sigma_f_s_note,
      mag_dipole_note,
      reduced_element_note,
      recalculated_loms_note
    ) VALUES (
      :publication_id,
      :contributor_info,
      :re_ion,
      :re_conc_value,
      :re_conc_value_upper,
      :re_conc_value_note,
      :re_conc_unit,
      :sample_label,
      :host_type,
      :composition_text,
      :omega2,
      :omega4,
      :omega6,
      :omega2_error,
      :omega4_error,
      :omega6_error,
      :has_density,
      :density_g_cm3,
      :extra_notes,
      :is_contributor_author,
      'pending',
      :refractive_index_option,
      :combinatorial_jo_option,
      :sigma_f_s_option,
      :mag_dipole_option,
      :reduced_element_option,
      :recalculated_loms_option,
      :refractive_index_note,
      :combinatorial_jo_note,
      :sigma_f_s_note,
      :mag_dipole_note,
      :reduced_element_note,
      :recalculated_loms_note
    )
  ");
  $stmt->execute([
    ':publication_id'            => $publication_id,
    ':contributor_info'          => $contributor_info,
    ':re_ion'                    => $re_ion,
    ':re_conc_value'             => $re_conc_value,
    ':re_conc_value_upper'       => $re_conc_value_upper,
    ':re_conc_value_note'        => $re_conc_value_note,
    ':re_conc_unit'              => $re_conc_unit,
    ':sample_label'              => $sample_label,
    ':host_type'                 => $host_type,
    ':composition_text'          => $composition_text,
    ':omega2'                    => $omega2,
    ':omega4'                    => $omega4,
    ':omega6'                    => $omega6,
    ':omega2_error'              => $omega2_error,
    ':omega4_error'              => $omega4_error,
    ':omega6_error'              => $omega6_error,
    ':has_density'               => $has_density,
    ':density_g_cm3'             => $density_g_cm3,
    ':extra_notes'               => $extra_notes,
    ':is_contributor_author'     => $is_contributor_author,
    ':refractive_index_option'   => $refractive_index_option,
    ':combinatorial_jo_option'   => $combinatorial_jo_option,
    ':sigma_f_s_option'          => $sigma_f_s_option,
    ':mag_dipole_option'         => $mag_dipole_option,
    ':reduced_element_option'    => $reduced_element_option,
    ':recalculated_loms_option'  => $recalculated_loms_option,
    ':refractive_index_note'     => $refractive_index_note,
    ':combinatorial_jo_note'     => $combinatorial_jo_note,
    ':sigma_f_s_note'            => $sigma_f_s_note,
    ':mag_dipole_note'           => $mag_dipole_note,
    ':reduced_element_note'      => $reduced_element_note,
    ':recalculated_loms_note'    => $recalculated_loms_note,
  ]);
  $jo_record_id = (int)$pdo->lastInsertId();
  if ($jo_record_id <= 0) json_fail('Failed to create JO record.', 500);
  $compRows = fetchComponentDetails($compRows, $pdo);
  if (!empty($compRows)) {
    $insComp = $pdo->prepare("
      INSERT INTO jo_composition_components (jo_record_id, component, value, unit, component_id)
      VALUES (:jo_record_id, :component, :value, :unit, :component_id)
    ");
    foreach ($compRows as $r) {
      $insComp->execute([
        ':jo_record_id' => $jo_record_id,
        ':component' => $r['component'],
        ':value' => $r['value'],
        ':unit' => $r['unit'],
        ':component_id' => $r['id']
      ]);
    }
  }
  $storedRelPath = null;
  $hasLomsUpload = isset($_FILES['jo_recalc_file_file_format']) && is_array($_FILES['jo_recalc_file_file_format']) && (($_FILES['jo_recalc_file_file_format']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
  if ($hasLomsUpload) {
    $f = $_FILES['jo_recalc_file_file_format'];
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
      json_fail('LOMS upload error code: ' . (string)$f['error'], 400);
    }
    if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
      json_fail('Invalid LOMS uploaded file.', 400);
    }
    if ((int)($f['size'] ?? 0) > $MAX_UPLOAD_BYTES) {
      json_fail('LOMS upload too large.', 400);
    }
    $dir = rtrim($UPLOAD_BASE, '/\\') . DIRECTORY_SEPARATOR . (string)$jo_record_id;
    ensure_dir($dir);
    $original = safe_filename((string)($f['name'] ?? 'upload.dat'));
    $targetAbs = $dir . DIRECTORY_SEPARATOR . $original;
    if (!move_uploaded_file($f['tmp_name'], $targetAbs)) {
      json_fail('Failed to store uploaded LOMS file.', 500);
    }
    $storedRelPath = 'uploads/loms/' . $jo_record_id . '/' . $original;
    $noteLine = "[loms_file_path={$storedRelPath}]";
    $newExtra = ($extra_notes === null) ? $noteLine : ($extra_notes . "\n" . $noteLine);
    $upd = $pdo->prepare("UPDATE jo_records SET extra_notes = :extra_notes WHERE id = :id");
    $upd->execute([':extra_notes' => $newExtra, ':id' => $jo_record_id]);
  } 
  else {
    if ($recalculated_loms_option === 2) {
      json_fail('Positive recalculated by LOMS option requires uploading JO LOMS calculation file.');
    }
  }
  $pdo->commit();
  echo json_encode([
    'ok' => true,
    'publication_id' => $publication_id,
    'jo_record_id' => $jo_record_id,
    'loms_file_path' => $storedRelPath,
  ], JSON_UNESCAPED_UNICODE);
} 
catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_fail(print_r([
    'error' => $e->getMessage(),
    'file'  => $e->getFile(),
    'line'  => $e->getLine(),
  ], true), 500);
}
