<?php
/**
 * jo_records.inc.php
 *
 * Shared query and filtering logic for JO records.
 * Builds SQL conditions for publication filters,
 * badge states, JO flags, and advanced composition rules.
 * Used by browse and export endpoints.
 */
declare(strict_types=1);
function extractFilePath(?string $text): ?string {
  if (!$text) return null;
  if (preg_match_all('/\[loms_file_path=([^\]]+)\]/', $text, $m, PREG_SET_ORDER)) {
    $last = end($m);
    return $last[1];
  }
  return null;
}
function stripFilePathTag(?string $text): ?string {
  if (!$text) return null;
  return preg_replace('/\[loms_file_path=[^\]]+\]/', '', $text);
}
function jo_apply_publication_filters(array $get, array &$where): void {
  $pubDoiQ     = trim((string)($get['pub_doi_q'] ?? ''));
  $pubTitleQ   = trim((string)($get['pub_title_q'] ?? ''));
  $pubAuthorsQ = trim((string)($get['pub_authors_q'] ?? ''));
  if ($pubDoiQ !== '')     $where[] = "p.doi LIKE '%" . $pubDoiQ . "%'";
  if ($pubTitleQ !== '')   $where[] = "p.title LIKE '%" . $pubTitleQ . "%'";
  if ($pubAuthorsQ !== '') $where[] = "p.authors LIKE '%" . $pubAuthorsQ . "%'";
}
function jo_apply_badge_filters(array $get, array &$where, array &$params): void {
  $badgeN       = isset($get['badge_n']) ? (int)$get['badge_n'] : -1;
  $badgeCjo     = isset($get['badge_cjo']) ? (int)$get['badge_cjo'] : -1;
  $badgeSigma   = isset($get['badge_sigmafs']) ? (int)$get['badge_sigmafs'] : -1;
  $badgeM1      = isset($get['badge_m1']) ? (int)$get['badge_m1'] : -1;
  $badgeRE      = isset($get['badge_re']) ? (int)$get['badge_re'] : -1;
  $badgeLOMS    = isset($get['badge_loms']) ? (int)$get['badge_loms'] : -1;
  $badgeDensity = isset($get['badge_density']) ? (int)$get['badge_density'] : -1;

  if ($badgeCjo >= 0)      $where[] = "r.combinatorial_jo_option = :b_cjo";
  if ($badgeSigma >= 0)    $where[] = "r.sigma_f_s_option = :b_sigma";
  if ($badgeM1 >= 0)       $where[] = "r.mag_dipole_option = :b_m1";
  if ($badgeRE >= 0)       $where[] = "r.reduced_element_option = :b_re";
  if ($badgeLOMS >= 0)     $where[] = "r.recalculated_loms_option = :b_loms";
  if ($badgeDensity >= 0)  $where[] = "r.has_density = :b_density";

  if ($badgeN >= 0) {
    if ($badgeN === 2) {
      $where[] = "r.refractive_index_option IN (2,3)";
    } else {
      $where[] = "r.refractive_index_option = :b_n";
    }
  }

  if ($badgeCjo >= 0)      $params[':b_cjo']    = $badgeCjo;
  if ($badgeSigma >= 0)    $params[':b_sigma']  = $badgeSigma;
  if ($badgeM1 >= 0)       $params[':b_m1']     = $badgeM1;
  if ($badgeRE >= 0)       $params[':b_re']     = $badgeRE;
  if ($badgeLOMS >= 0)     $params[':b_loms']   = $badgeLOMS;
  if ($badgeDensity >= 0)  $params[':b_density']= $badgeDensity;
  if ($badgeN >= 0 && $badgeN !== 2) $params[':b_n'] = $badgeN;
}
function jo_apply_advanced_composition_rules(array $get, array &$where, array &$params): void {
  $ruleComp = $get['comp_component'] ?? [];
  $ruleOp   = $get['comp_op'] ?? [];
  $ruleVal  = $get['comp_value'] ?? [];
  $ruleUnit = $get['comp_unit'] ?? [];
  $allowedOps = ['>=' => '>=', '<=' => '<=', '=' => '=', '>' => '>', '<' => '<'];
  if (!is_array($ruleComp) || !is_array($ruleOp) || !is_array($ruleVal) || !is_array($ruleUnit)) return;
  $n = min(count($ruleComp), count($ruleOp), count($ruleVal), count($ruleUnit));
  for ($i = 0; $i < $n; $i++) {
    $c = trim((string)$ruleComp[$i]);
    $o = trim((string)$ruleOp[$i]);
    $v = trim((string)$ruleVal[$i]);
    $u = trim((string)$ruleUnit[$i]);
    if ($c === '' || $v === '' || $u === '') continue;
    if (!isset($allowedOps[$o])) continue;
    if(($allowedOps[$o] === '>=' && $v <= 0) || ($allowedOps[$o] === '>' && $v < 0)) {
      continue;
    }
    $pC = ":rc{$i}";
    $pV = ":rv{$i}";
    $params[$pC] = $c;
    $params[$pV] = (float)$v;
    $unitSql = '';
    if ($u != 'any%') {
      $pU = ":ru{$i}";
      $params[$pU] = $u;
      $unitSql = " AND cc.unit = $pU ";
    }
    if ($o === '<=' || $o === '<') {
      $where[] = "(NOT EXISTS (
          SELECT 1 FROM jo_composition_components cc
          WHERE cc.jo_record_id = r.id
            AND cc.component LIKE CONCAT('%', $pC, '%')
            $unitSql
        ) OR EXISTS (
          SELECT 1 FROM jo_composition_components cc
          WHERE cc.jo_record_id = r.id
            AND cc.component LIKE CONCAT('%', $pC, '%')
            $unitSql
            AND cc.value <= $pV
        ))";
    } else {
      $where[] = "EXISTS (
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pC, '%')
          $unitSql
          AND cc.value {$allowedOps[$o]} $pV
      )";
    }
  }
}
function parseComposition($composition): ?array {
    if (!$composition) return null;
    if (is_string($composition)) {
        $composition = json_decode($composition, true);
    }
    if (!is_array($composition) || !$composition) return null;
    $out = [];
    foreach ($composition as $el => $cnt) {
        $el = trim((string)$el);
        if ($el === '') continue;
        $v = (float)$cnt;
        if ($v <= 0) continue;
        $out[$el] = ($out[$el] ?? 0.0) + $v;
    }
    return $out ?: null;
}
function atomCountFromComposition($composition, float $fallbackAtomNumber = 0.0): float {
    $comp = parseComposition($composition);
    if (!$comp) return $fallbackAtomNumber;
    $sum = 0.0;
    foreach ($comp as $cnt) $sum += (float)$cnt;
    return $sum > 0 ? $sum : $fallbackAtomNumber;
}
function normalizeComposition(array &$components): array {
  if (!$components) return [];
  $EPS = 1e-12;
  $moles = [];
  $totalMoles = 0.0;
  foreach ($components as $i => $c) {
    $val  = (float)($c['value'] ?? 0);
    $unit = strtolower(trim((string)($c['unit'] ?? '')));
    $mw   = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atomCountFromComposition($c['composition'] ?? null, $fallbackAtoms);
    if ($val <= 0) { $moles[$i] = 0.0; continue; }
    if ($unit === 'mol%') {
      $n = $val;
    } 
    elseif ($unit === 'wt%') {
      $n = ($mw > $EPS) ? ($val / $mw) : 0.0;
    } 
    elseif ($unit === 'at%') {
      $n = ($atoms > $EPS) ? (($val / 100.0) * 100.0 / $atoms) : 0.0;
    } 
    else {
      $n = 0.0;
    }
    $moles[$i] = $n;
    $totalMoles += $n;
  }
  if ($totalMoles <= $EPS) return [];
  $totalMass = 0.0;
  $totalAtomCount = 0.0;
  foreach ($components as $i => $c) {
    $n = $moles[$i];
    if ($n <= 0) continue;
    $mw = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atomCountFromComposition($c['composition'] ?? null, $fallbackAtoms);
    $totalMass      += $n * $mw;
    $totalAtomCount += $n * $atoms;
  }
  if ($totalMass <= $EPS)      $totalMass = 1.0;
  if ($totalAtomCount <= $EPS) $totalAtomCount = 1.0;
  foreach ($components as $i => &$c) {
    $n = $moles[$i];
    $mw = (float)($c['mw'] ?? 0);
    $fallbackAtoms = (float)($c['atom_number'] ?? 0);
    $atoms = atomCountFromComposition($c['composition'] ?? null, $fallbackAtoms);
    $c['c_mol'] = round(($n / $totalMoles) * 100.0, 6);
    $mass = $n * $mw;
    $c['c_wt']  = round(($mass / $totalMass) * 100.0, 6);
    $atomCnt = $n * $atoms;
    $c['c_at']  = round(($atomCnt / $totalAtomCount) * 100.0, 6);
  }
  unset($c);
  $elemAtoms = [];
  $elemTotal = 0.0;
  foreach ($components as $i => $c) {
    $n = $moles[$i];
    if ($n <= 0) continue;
    $comp = parseComposition($c['composition'] ?? null);
    if (!$comp) continue;
    foreach ($comp as $el => $cnt) {
      $a = $n * (float)$cnt;
      $elemAtoms[$el] = ($elemAtoms[$el] ?? 0.0) + $a;
      $elemTotal += $a;
    }
  }
  if ($elemTotal <= $EPS) return [];
  $out = [];
  $weights = atomic_weights();
  $massTotal = 0.0;
  foreach ($elemAtoms as $el => $a) {
    $out[$el]["c_at"] = round(($a / $elemTotal) * 100.0, 6);
    if (!isset($weights[$el])) {
      $out[$el]["c_wt"] = null;
    } 
    else {
      $mw = $weights[$el];
      $mass = $a * $mw;
      $out[$el]["c_wt"] = $mass;
      $massTotal += $mass;
    }
  }
  if ($massTotal > $EPS) {
    foreach ($out as $el => &$data) {
      if ($data["c_wt"] !== null) {
        $data["c_wt"] = round(($data["c_wt"] / $massTotal) * 100.0, 6);
      }
    }
    unset($data);
  }
  ksort($out);
  return $out;
}
function atomic_weights(): array {
  return [
    'H'=>1.00794,  'He'=>4.002602,
    'Li'=>6.941,   'Be'=>9.012182, 'B'=>10.811,  'C'=>12.0107, 'N'=>14.0067, 'O'=>15.9994, 'F'=>18.9984032, 'Ne'=>20.1797,
    'Na'=>22.98976928,'Mg'=>24.3050,'Al'=>26.9815386,'Si'=>28.0855,'P'=>30.973762,'S'=>32.065,'Cl'=>35.453,'Ar'=>39.948,
    'K'=>39.0983,'Ca'=>40.078,'Sc'=>44.955912,'Ti'=>47.867,'V'=>50.9415,'Cr'=>51.9961,'Mn'=>54.938045,'Fe'=>55.845,'Co'=>58.933195,'Ni'=>58.6934,'Cu'=>63.546,'Zn'=>65.38,'Ga'=>69.723,'Ge'=>72.63,'As'=>74.92160,'Se'=>78.96,'Br'=>79.904,'Kr'=>83.798,
    'Rb'=>85.4678,'Sr'=>87.62,'Y'=>88.90585,'Zr'=>91.224,'Nb'=>92.90638,'Mo'=>95.96,'Tc'=>98.0,'Ru'=>101.07,'Rh'=>102.90550,'Pd'=>106.42,'Ag'=>107.8682,'Cd'=>112.411,'In'=>114.818,'Sn'=>118.710,'Sb'=>121.760,'Te'=>127.60,'I'=>126.90447,'Xe'=>131.293,
    'Cs'=>132.9054519,'Ba'=>137.327,'La'=>138.90547,'Ce'=>140.116,'Pr'=>140.90765,'Nd'=>144.242,'Pm'=>145.0,'Sm'=>150.36,'Eu'=>151.964,'Gd'=>157.25,'Tb'=>158.92535,'Dy'=>162.500,'Ho'=>164.93032,'Er'=>167.259,'Tm'=>168.93421,'Yb'=>173.054,'Lu'=>174.9668,
    'Hf'=>178.49,'Ta'=>180.94788,'W'=>183.84,'Re'=>186.207,'Os'=>190.23,'Ir'=>192.217,'Pt'=>195.084,'Au'=>196.966569,'Hg'=>200.59,'Tl'=>204.3833,'Pb'=>207.2,'Bi'=>208.98040,'Po'=>209.0,'At'=>210.0,'Rn'=>222.0,
    'Fr'=>223.0,'Ra'=>226.0,'Ac'=>227.0,'Th'=>232.03806,'Pa'=>231.03588,'U'=>238.02891,
  ];
}