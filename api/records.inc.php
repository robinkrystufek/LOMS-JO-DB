<?php
/**
 * records.inc.php
 *
 * Shared query and filtering logic for JO records.
 * Builds SQL conditions for publication filters,
 * badge states, JO flags, and advanced composition rules.
 * Used by browse and export endpoints.
 */
declare(strict_types=1);
function extract_file_path(?string $text): ?string {
  if (!$text) return null;
  if (preg_match_all('/\[loms_file_path=([^\]]+)\]/', $text, $m, PREG_SET_ORDER)) {
    $last = end($m);
    return $last[1];
  }
  return null;
}
function strip_db_tags(?string $text): ?string {
  if (!$text) return null;
  return trim(preg_replace('/\[(?:loms|Revision)[^\]]+\]/', '', $text));
}
function apply_filters(array $get, array &$where, array &$params): void {
  apply_publication_filter($get, $where, $params);
  apply_badge_filter($get, $where, $params);
  apply_composition_filter($get, $where, $params);
  apply_advanced_composition_filter($get, $where, $params);
  apply_id_filter($get, $where, $params);
}
function apply_publication_filter(array $get, array &$where, array &$params): void {
  $pubDoiQ     = trim((string)($get['pub_doi_q'] ?? ''));
  $pubTitleQ   = trim((string)($get['pub_title_q'] ?? ''));
  $pubAuthorsQ = trim((string)($get['pub_authors_q'] ?? ''));
  if ($pubDoiQ !== '') {
    $where[] = "p.doi LIKE :pubDoiQ";
    $params[':pubDoiQ'] = "%" . $pubDoiQ . "%";
  }
  if ($pubTitleQ !== '') {
    $where[] = "p.title LIKE :pubTitleQ";
    $params[':pubTitleQ'] = "%" . $pubTitleQ . "%";
  }
  if ($pubAuthorsQ !== '') {
    $where[] = "p.authors LIKE :pubAuthorsQ";
    $params[':pubAuthorsQ'] = "%" . $pubAuthorsQ . "%";
  }
}
function apply_badge_filter(array $get, array &$where, array &$params): void {
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
      $params[':b_n'] = $badgeN;
    }
  }

  if ($badgeCjo >= 0)      $params[':b_cjo']     = $badgeCjo;
  if ($badgeSigma >= 0)    $params[':b_sigma']   = $badgeSigma;
  if ($badgeM1 >= 0)       $params[':b_m1']      = $badgeM1;
  if ($badgeRE >= 0)       $params[':b_re']      = $badgeRE;
  if ($badgeLOMS >= 0)     $params[':b_loms']    = $badgeLOMS;
  if ($badgeDensity >= 0)  $params[':b_density'] = $badgeDensity;
}
function apply_composition_filter(array $get, array &$where, array &$params) {
  $reIon        = trim((string)($get['re_ion'] ?? ''));
  $hostType     = trim((string)($get['host_type'] ?? ''));
  $compositionQ = trim((string)($get['composition_q'] ?? ''));
  $elementQ     = trim((string)($get['element_q'] ?? ''));
  $elementMode = strtolower(trim((string)($get['element_mode'] ?? 'any')));
  if (!in_array($elementMode, ['any', 'all'], true)) $elementMode = 'any';

  if ($reIon !== '') { $where[] = "r.re_ion = :re_ion"; $params[':re_ion'] = $reIon; }
  if ($hostType !== '') { $where[] = "r.host_type = :host_type"; $params[':host_type'] = $hostType; }
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
    $rawEls = preg_split('/\s*,\s*/', trim($elementQ));
    $els = [];
    foreach ($rawEls as $el) {
      $el = ucfirst(strtolower(trim($el)));
      if ($el !== '' && preg_match('/^[A-Z][a-z]?$/', $el)) {
        $els[$el] = true;
      }
    }
    $els = array_keys($els);
    if ($els) {  
      if ($elementMode === 'any') {
        $placeholders = [];
        foreach ($els as $i => $el) {
          $ph = ":element_q_$i";
          $placeholders[] = $ph;
          $params[$ph] = $el;
        }
        $where[] = "EXISTS (
          SELECT 1
          FROM jo_composition_elements ce
          WHERE ce.record_id = r.id
            AND ce.element IN (" . implode(', ', $placeholders) . ")
        )";
      } 
      elseif ($elementMode === 'all') {
        $sub = [];
        foreach ($els as $i => $el) {
          $ph = ":element_q_$i";
          $sub[] = "EXISTS (
            SELECT 1
            FROM jo_composition_elements ce$i
            WHERE ce$i.record_id = r.id
              AND ce$i.element = $ph
          )";
          $params[$ph] = $el;
        }
        $where[] = '(' . implode(' AND ', $sub) . ')';
      }
    }
  }
}
function apply_advanced_composition_filter(array $get, array &$where, array &$params): void {
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
    if (!is_numeric($v)) continue;
    $vNum = (float)$v;
    if (($allowedOps[$o] === '>=' && $vNum <= 0) || ($allowedOps[$o] === '>' && $vNum < 0)) {
      continue;
    }

    $pCC1 = ":rcc1{$i}";
    $pCC2 = ":rcc2{$i}";
    $pCE1 = ":rce1{$i}";
    $pCE2 = ":rce2{$i}";
    $pV1  = ":rv1{$i}";
    $pV2  = ":rv2{$i}";
    $pV3  = ":rv3{$i}";
    $pV4  = ":rv4{$i}";
    $pV5  = ":rv5{$i}";
    $pV6 = ":rv6_{$i}";
    $pRE2 = ":rre2_{$i}";
    $pRE1 = ":rre1_{$i}";
    $pREU = ":rreu_{$i}";
    $params[$pRE1] = $c;
    $params[$pCC1] = $c;
    $params[$pCE1] = $c;
    $params[$pV1]  = $vNum;
    $params[$pV2]  = $vNum;
    $params[$pV6]  = $vNum;
    $existsCompSql = '';
    $matchCompSql  = '';
    $existsElemSql = '';
    $matchElemSql  = '';

    if ($u === 'mol%') {
      $existsCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC2, '%')
          AND cc.calc_mol IS NOT NULL
      ";
      $matchCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC1, '%')
          AND cc.calc_mol IS NOT NULL
          AND cc.calc_mol {$allowedOps[$o]} $pV2
      ";
      $existsElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE2
          AND ce.c_mol IS NOT NULL
      ";
      $matchElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE1
          AND ce.c_mol IS NOT NULL
          AND ce.c_mol {$allowedOps[$o]} $pV1
      ";
      $existsRESql = "
        r.re_ion <> $pRE2
      ";
      $matchRESql = "
        r.re_ion = $pRE1
        AND r.re_conc_value {$allowedOps[$o]} $pV6
        AND r.re_conc_unit = $pREU
      ";      
      $params[$pREU]  = "mol%";
    } 
    elseif ($u === 'wt%') {
      $existsCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC2, '%')
          AND cc.calc_wt IS NOT NULL
      ";
      $matchCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC1, '%')
          AND cc.calc_wt IS NOT NULL
          AND cc.calc_wt {$allowedOps[$o]} $pV2
      ";
      $existsElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE2
          AND ce.c_wt IS NOT NULL
      ";
      $matchElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE1
          AND ce.c_wt IS NOT NULL
          AND ce.c_wt {$allowedOps[$o]} $pV1
      ";
      $existsRESql = "
        r.re_ion <> $pRE2
      ";
      $matchRESql = "
        r.re_ion = $pRE1
        AND r.re_conc_value {$allowedOps[$o]} $pV6
        AND r.re_conc_unit = $pREU
      ";      
      $params[$pREU]  = "wt%";
    } 
    elseif ($u === 'at%') {
      $existsCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC2, '%')
          AND cc.calc_at IS NOT NULL
      ";
      $matchCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC1, '%')
          AND cc.calc_at IS NOT NULL
          AND cc.calc_at {$allowedOps[$o]} $pV2
      ";
      $existsElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE2
          AND ce.c_mol IS NOT NULL
      ";
      $matchElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE1
          AND ce.c_mol IS NOT NULL
          AND ce.c_mol {$allowedOps[$o]} $pV1
      ";
      $existsRESql = "
        r.re_ion <> $pRE2
      ";
      $matchRESql = "
        r.re_ion = $pRE1
        AND r.re_conc_value {$allowedOps[$o]} $pV6
        AND r.re_conc_unit = $pREU
      ";      
      $params[$pREU]  = "at%";
    } 
    elseif ($u === 'any%') {
      $params[$pV3] = $vNum;
      $params[$pV4] = $vNum;
      $params[$pV5] = $vNum;
      $existsCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC2, '%')
          AND (
            cc.calc_mol IS NOT NULL
            OR cc.calc_wt IS NOT NULL
            OR cc.calc_at IS NOT NULL
          )
      ";
      $matchCompSql = "
        SELECT 1 FROM jo_composition_components cc
        WHERE cc.jo_record_id = r.id
          AND cc.component LIKE CONCAT('%', $pCC1, '%')
          AND (
            (cc.calc_mol IS NOT NULL AND cc.calc_mol {$allowedOps[$o]} $pV1)
            OR (cc.calc_wt  IS NOT NULL AND cc.calc_wt  {$allowedOps[$o]} $pV2)
            OR (cc.calc_at  IS NOT NULL AND cc.calc_at  {$allowedOps[$o]} $pV3)
          )
      ";
      $existsElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE2
          AND (
            ce.c_mol IS NOT NULL
            OR ce.c_wt IS NOT NULL
          )
      ";
      $matchElemSql = "
        SELECT 1 FROM jo_composition_elements ce
        WHERE ce.record_id = r.id
          AND ce.element = $pCE1
          AND (
            (ce.c_mol IS NOT NULL AND ce.c_mol {$allowedOps[$o]} $pV4)
            OR (ce.c_wt  IS NOT NULL AND ce.c_wt  {$allowedOps[$o]} $pV5)
          )
      ";
      $existsRESql = "
        r.re_ion <> $pRE2
      ";
      $matchRESql = "
        r.re_ion = $pRE1
        AND r.re_conc_value {$allowedOps[$o]} $pV6
      ";      
    } 
    else {
      continue;
    }
    if ($o === '<=' || $o === '<') {
      $where[] = "(
        (
          NOT EXISTS (
            $existsCompSql
          )
          AND NOT EXISTS (
            $existsElemSql
          )
          AND (
            $existsRESql
          )
        )
        OR EXISTS (
          $matchCompSql
        )
        OR EXISTS (
          $matchElemSql
        )
        OR (
          $matchRESql
        )
      )";
      $params[$pCE2] = $c;
      $params[$pCC2] = $c;
      $params[$pCE2] = $c;
      $params[$pRE2] = $c;
    } 
    else {
      $where[] = "(
        EXISTS (
          $matchCompSql
        )
        OR EXISTS (
          $matchElemSql
        )
        OR (
          $matchRESql
        )
      )";
    }
  }
}
function apply_id_filter(array $get, array &$where, array &$params) {
  $recordId = trim((string)($get['record_id'] ?? ''));
  if ($recordId !== '' && preg_match('/^\d+(,\s*\d+)*$/', $recordId)) {
    $ids = array_filter(array_map('trim', explode(',', $recordId)));
    $ids = array_values(array_filter($ids, function ($id) {
        return ctype_digit($id);
    }));
    if (!empty($ids)) {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $ph = ":record_id_$i";
            $placeholders[] = $ph;
            $params[$ph] = (int)$id;
        }
        $where[] = "r.id IN (" . implode(',', $placeholders) . ")";
    }
  }
  else $where[] = "r.review_status='approved'";
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