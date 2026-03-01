<?php
/**
 * add_entry.php
 *
 * Main endpoint for creating a new JO record via form/ JSON submission.
 * Handles request validation, file uploads (LOMS), and delegates
 * database insertion logic to add_entry.inc.php.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';
include 'add_entry.inc.php';
$UPLOAD_BASE = dirname(__DIR__) . '/uploads/loms';    // uploads/loms/<jo_record_id>/
$MAX_UPLOAD_BYTES = 20 * 1024 * 1024;                 // 20 MB
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$payload = [];
if (stripos($contentType, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw ?: '', true);
  if (!is_array($decoded)) json_fail('Invalid JSON body.');
  $payload = $decoded;
} else {
  $payload = $_POST;
}
$get = function(string $key) use ($payload) {
  return $payload[$key] ?? null;
};

$doi     = as_trimmed($get('pub_doi'));
$title   = as_trimmed($get('pub_title'));
$journal = as_trimmed($get('pub_journal'));
$url     = as_trimmed($get('pub_url'));
$authors = as_trimmed($get('pub_authors'));
$year    = to_int($get('pub_year'));

$article_metadata = parse_JSON_POST($_POST['article_metadata']);
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
      $alex_id = as_trimmed($lookup['alex_id'] ?? null);
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

$contributor_info = as_trimmed($get('contributor_info')); 
$is_contributor_author = bool01($get('is_contributor_author'));
$re_ion = as_trimmed($get('re_ion'));
$re_conc_value = to_float($get('re_conc_value'));
$re_conc_value_upper = to_float($get('re_conc_value_upper'));
if($re_conc_value_upper < $re_conc_value && ($re_conc_value_upper !== null || $re_conc_value_upper != 0)) {
  [$re_conc_value_upper, $re_conc_value] = [$re_conc_value, $re_conc_value_upper];
}
$re_conc_value_note = as_trimmed($get('re_conc_value_note'));
$re_conc_unit  = map_conc_unit(as_trimmed($get('re_conc_unit')));
$sample_label = as_trimmed($get('sample_label'));
$host_type   = map_host_type(as_trimmed($get('host_type')));
$composition_text = as_trimmed($get('composition_text'));
$omega2 = to_float($get('omega2'));
$omega4 = to_float($get('omega4'));
$omega6 = to_float($get('omega6'));
$omega2_error = to_float($get('omega2_error'));
$omega4_error = to_float($get('omega4_error'));
$omega6_error = to_float($get('omega6_error'));

if(as_trimmed($get('jo_source')) == "original") {
  $recalculated_loms_option	= 0;
}
elseif(as_trimmed($get('jo_source')) == "unknown") {
  $recalculated_loms_option	= 1;
}
else {
  $recalculated_loms_option	= 2;
}
$ri = as_trimmed($get('refractive_index_option'));
if ($ri == "no") {
  $refractive_index_option = 0;
}
elseif ($ri == "unknown") {
  $refractive_index_option = 1;
}
elseif ($ri == "single-value") {
  $refractive_index_option = 2;
}
else {
  $refractive_index_option = 3;
}
$cjo = as_trimmed($get('combinatorial_jo_option'));
if ($cjo == "no") {
  $combinatorial_jo_option = 0;
}
elseif ($cjo == "unknown") {
  $combinatorial_jo_option = 1;
}
else {
  $combinatorial_jo_option = 2;
}
$sfs = as_trimmed($get('sigma_f_s_option'));
if ($sfs == "no") {
  $sigma_f_s_option = 0;
}
elseif ($sfs == "unknown") {
  $sigma_f_s_option = 1;
}
else {
  $sigma_f_s_option = 2;
}
$md = as_trimmed($get('mag_dipole_option'));
if ($md == "no") {
  $mag_dipole_option = 0;
}
elseif ($md == "unknown") {
  $mag_dipole_option = 1;
}
else {
  $mag_dipole_option = 2;
}
$re = as_trimmed($get('reduced_element_option'));
if ($re == "no") {
  $reduced_element_option = 0;
}
elseif ($re == "unknown") {
  $reduced_element_option = 1;
}
else {
  $reduced_element_option = 2;
}
$dens = as_trimmed($get('has_density'));
if ($dens == "no") {
  $has_density = 0;
}
elseif ($dens == "unknown") {
  $has_density = 1;
}
else {
  $has_density = 2;
}
$src = as_trimmed($get('jo_source'));
if ($src == "original") {
  $recalculated_loms_option = 0;
}
elseif ($src == "unknown") {
  $recalculated_loms_option = 1;
}
else {
  $recalculated_loms_option = 2;
}
$density_g_cm3 = to_float($get('density_g_cm3'));
if ($density_g_cm3 === null) $density_g_cm3 = to_float($get('density'));
$refractive_index_note  = as_trimmed($get('refractive_index_note'));
$combinatorial_jo_note  = as_trimmed($get('combinatorial_jo_note'));
$sigma_f_s_note         = as_trimmed($get('sigma_f_s_note'));
$mag_dipole_note        = as_trimmed($get('mag_dipole_note'));
$reduced_element_note   = as_trimmed($get('reduced_element_note'));
$recalculated_loms_note = as_trimmed($get('jo_source_note'));
$extra_notes = as_trimmed($get('extra_notes'));
$is_revision_of_id = as_trimmed($get('is_revision_of_id'));
$submission_status = 'pending';
if($is_revision_of_id !== null) {
  $is_revision_of_id = to_int($is_revision_of_id);
  if ($is_revision_of_id !== null && $is_revision_of_id > 0) $submission_status = 'pending_revision';
}
if ($extra_notes === null) $extra_notes = as_trimmed($get('notes'));

if ($contributor_info == "" || $contributor_info === null) json_fail('Contributor info is required.');
if ($title === null) json_fail('Publication title is required (pub_title).');
if ($re_ion === null) json_fail('RE ion is required (re_ion).');
if ($composition_text === null) json_fail('Composition text is required (composition_text).');
if ($host_type === null) json_fail('Host type is required (host_type).');

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
  json_fail('Database connection failed.', 500);
}
try {
  $pdo->beginTransaction();
  $contributor_uid  = as_trimmed($get('contributor_info'));
  $contributor_email = as_trimmed($get('contributor_info_email'));
  $contributor_name  = as_trimmed($get('contributor_info_name'));
  $contributor_aff   = as_trimmed($get('contributor_info_affiliation'));
  $contributor_orcid = as_trimmed($get('contributor_info_orcid'));
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
      ':uid' => $contributor_uid, 
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
        ':alex_citations' => $alex_citations,
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
      recalculated_loms_note,
      is_revision_of_id,
      review_status
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
      :recalculated_loms_note,
      :is_revision_of_id,
      :review_status
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
    ':is_revision_of_id'         => $is_revision_of_id,
    ':review_status'             => $submission_status,
  ]);
  $jo_record_id = (int)$pdo->lastInsertId();
  if ($jo_record_id <= 0) json_fail('Failed to create JO record.', 500);

  $compRows = [];
  $compJson = $get('comp_json');
  if ($compJson !== null && is_string($compJson)) {
    $decoded = json_decode($compJson, true);
    if (is_array($decoded)) {
      foreach ($decoded as $row) {
        $c = as_trimmed($row['component'] ?? null);
        $u = as_trimmed($row['unit'] ?? null);
        $v = to_float($row['value'] ?? null);
        if ($c === null || $u === null || $v === null) continue;
        if (!in_array($u, ['mol%','wt%','at%'], true)) continue;
        $compRows[] = ['component'=>$c, 'value'=>$v, 'unit'=>$u];
      }
    }
  } 
  else {
    $components = $get('comp_component');
    $values     = $get('comp_value');
    $units      = $get('comp_unit');
    if (is_array($components) && is_array($values) && is_array($units)) {
      $n = min(count($components), count($values), count($units));
      for ($i=0; $i<$n; $i++) {
        $c = as_trimmed((string)$components[$i]);
        $u = as_trimmed((string)$units[$i]);
        $v = to_float($values[$i]);
        if ($c === null || $u === null || $v === null) continue;
        if (!in_array($u, ['mol%','wt%','at%'], true)) continue;
        $compRows[] = ['component'=>$c, 'value'=>$v, 'unit'=>$u];
      }
    }
  }
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
  $hasUpload = isset($_FILES['jo_recalc_file']) && is_array($_FILES['jo_recalc_file']) && ($_FILES['jo_recalc_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
  if ($hasUpload) {
    $f = $_FILES['jo_recalc_file'];
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
      json_fail('Upload error code: ' . (string)$f['error'], 400);
    }
    if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
      json_fail('Invalid uploaded file.', 400);
    }
    if ((int)($f['size'] ?? 0) > $MAX_UPLOAD_BYTES) {
      json_fail('Upload too large.', 400);
    }
    $dir = rtrim($UPLOAD_BASE, '/\\') . DIRECTORY_SEPARATOR . (string)$jo_record_id;
    ensure_dir($dir);
    $original = safe_filename((string)($f['name'] ?? 'upload.dat'));
    $targetAbs = $dir . DIRECTORY_SEPARATOR . $original;
    if (!move_uploaded_file($f['tmp_name'], $targetAbs)) {
      json_fail('Failed to store uploaded file.', 500);
    }
    $storedRelPath = 'uploads/loms/' . $jo_record_id . '/' . $original;
    $noteLine = "[loms_file_path={$storedRelPath}]";
    $newExtra = ($extra_notes === null) ? $noteLine : ($extra_notes . "\n" . $noteLine);
    $upd = $pdo->prepare("UPDATE jo_records SET extra_notes = :extra_notes WHERE id = :id");
    $upd->execute([':extra_notes' => $newExtra, ':id' => $jo_record_id]);
  } 
  else {
    if ($jo_recalc_by_loms === 1) {
      json_fail('Recalculated by LOMS requires uploading the LOMS file (jo_recalc_file).');
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
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_fail(print_r([
    'error' => $e->getMessage(),
    'file'  => $e->getFile(),
    'line'  => $e->getLine(),
    'trace' => $e->getTraceAsString()
  ]), 500);
}
