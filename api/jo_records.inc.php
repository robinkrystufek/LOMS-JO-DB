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
    $pC = ":rc{$i}";
    $pV = ":rv{$i}";
    $pU = ":ru{$i}";
    $params[$pC] = $c;
    $params[$pV] = (float)$v;
    $params[$pU] = $u;
    if ($o === '<=') {
      $where[] = "(NOT EXISTS (
          SELECT 1 FROM jo_composition_components cc
          WHERE cc.jo_record_id = r.id
            AND cc.component LIKE CONCAT('%', $pC, '%')
            AND cc.unit = $pU
        ) OR EXISTS (
          SELECT 1 FROM jo_composition_components cc
          WHERE cc.jo_record_id = r.id
            AND cc.component LIKE CONCAT('%', $pC, '%')
            AND cc.unit = $pU
            AND cc.value <= $pV
        ))";
    } else {
      $where[] = "EXISTS (
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pC, '%')
          AND cc.unit = $pU
          AND cc.value {$allowedOps[$o]} $pV
      )";
    }
  }
}
