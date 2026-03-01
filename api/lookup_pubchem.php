<?php
/**
 * pubchem_resolve.php
 *
 * Search Pubchem API for molecular information based on name/formula query.
 * GET:
 *   ?q=salicylic%20acid
 *   ?q=AlPO₄
 *   ?q=H2O
 *
 * Optional:
 *   &idx=1            (1-based result index; default 1 = first hit)
 *   &record=2d|3d     (default 2d; 3d requests 3D record if available)
 *
 * Returns JSON with:
 * - selected: { CID, name, formula, weight, smiles, total_atoms }
 * - structure: PubChem record JSON (PC_Compounds...) for selected CID
 */

header('Content-Type: application/json; charset=utf-8');
include 'config.inc.php';

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$q = trim($q, "\"' \t\r\n");
if ($q === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing parameter: q'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$idx = isset($_GET['idx']) ? (int)$_GET['idx'] : 1;
if ($idx < 1) $idx = 1;
if ($idx > 50) $idx = 50;
$recordType = isset($_GET['record']) ? strtolower(trim((string)$_GET['record'])) : '2d';
if ($recordType !== '2d' && $recordType !== '3d') $recordType = '2d';
$forceRefresh = isset($_GET['cache_refresh']) && $_GET['cache_refresh'] == '1';
$checkOnly = isset($_GET['check']) && $_GET['check'] == '1';

function jo_components_select_by_ui_name(PDO $pdo, string $uiName): ?array {
  $sql = "SELECT * FROM jo_components WHERE ui_name = :q LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':q' => $uiName]);
  $row = $st->fetch();
  return is_array($row) ? $row : null;
}
function jo_components_insert_placeholder(PDO $pdo, string $uiName): void {
  $st = $pdo->prepare("INSERT INTO jo_components (ui_name) VALUES (:q)");
  $st->execute([':q' => $uiName]);
}
function jo_components_update_from_out(PDO $pdo, string $uiName, array $out, array $calculatedProperties, bool $checkOnly = false): int {
  if(!$checkOnly) {
    $cid  = isset($out['selected']['CID']) ? (int)$out['selected']['CID'] : null;
    $name = isset($out['selected']['name']) && is_string($out['selected']['name']) ? $out['selected']['name'] : null;
    $mw   = null;
    if (isset($out['selected']['MolecularWeight']) && is_numeric($out['selected']['MolecularWeight'])) {
      $mw = (float)$out['selected']['MolecularWeight'];
    } 
    elseif (isset($calculatedProperties['molecular_weight']) && is_numeric($calculatedProperties['molecular_weight'])) {
      $mw = (float)$calculatedProperties['molecular_weight'];
    }
    $atoms = null;
    if (isset($out['selected']['total_atoms']) && is_numeric($out['selected']['total_atoms'])) {
      $atoms = (float)$out['selected']['total_atoms'];
    } 
    elseif (isset($calculatedProperties['total_atoms']) && is_numeric($calculatedProperties['total_atoms'])) {
      $atoms = (float)$calculatedProperties['total_atoms'];
    }
    $composition = null;
    if (isset($calculatedProperties['composition']) && is_array($calculatedProperties['composition'])) {
      $composition = $calculatedProperties['composition'];
    }
    $detailsJson = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $compJson    = $composition ? json_encode($composition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $sql = "UPDATE jo_components
            SET
              cid = :cid,
              pubchem_name = :pname,
              pubchem_details = :pdetails,
              mw = :mw,
              atom_number = :atoms,
              composition = :comp
            WHERE ui_name = :q";
    $st = $pdo->prepare($sql);
    $st->bindValue(':cid', $cid, $cid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':pname', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':pdetails', $detailsJson, PDO::PARAM_STR);
    $st->bindValue(':mw', $mw, $mw === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':atoms', $atoms, $atoms === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':comp', $compJson, $compJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':q', $uiName, PDO::PARAM_STR);
    $st->execute();
  }
  $idStmt = $pdo->prepare("SELECT id FROM jo_components WHERE ui_name = :q LIMIT 1");
  $idStmt->bindValue(':q', $uiName, PDO::PARAM_STR);
  $idStmt->execute();
  $id = $idStmt->fetchColumn();
  return $id ? (int)$id : 0;
}
function normalize_subscripts(string $s): string {
  static $map = ['₀'=>'0','₁'=>'1','₂'=>'2','₃'=>'3','₄'=>'4','₅'=>'5','₆'=>'6','₇'=>'7','₈'=>'8','₉'=>'9'];
  return strtr($s, $map);
}
function looks_like_formula(string $raw): bool {
  if (preg_match('/\s/u', $raw)) return false;
  $s = normalize_subscripts($raw);
  if (preg_match('/[,\-\/]/u', $s)) return false;
  if (preg_match('/^(?:[A-Z][a-z]?\d*)+(?:\((?:[A-Z][a-z]?\d*)+\)\d*)*$/u', $s)) return true;
  if (preg_match('/\d/u', $s) && preg_match('/[A-Z][a-z]?/u', $s) && !preg_match('/[a-z]{3,}/u', $s)) return true;
  return false;
}
function atom_count_from_formula(?string $formula): ?int {
  if (!$formula) return null;
  $parts = preg_split('/[·\.]/u', $formula);
  $total = 0;
  foreach ($parts as $part) {
    $part = trim($part);
    if ($part === '') continue;
    $mult = 1;
    if (preg_match('/^(\d+)(.*)$/u', $part, $m)) {
      $mult = (int)$m[1];
      $part = $m[2];
    }
    if ($part === '') continue;
    if (!preg_match_all('/([A-Z][a-z]?)(\d*)/u', $part, $m, PREG_SET_ORDER)) continue;
    $sub = 0;
    foreach ($m as $tok) {
      $n = ($tok[2] === '') ? 1 : (int)$tok[2];
      $sub += $n;
    }
    $total += $mult * $sub;
  }
  return $total > 0 ? $total : null;
}
function http_get_json(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'JO-DB PubChem Resolver/1.2 (+https://loms.cz/jo-db/)',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false || $err) {
    return ['__ok' => false, '__http' => $code, '__error' => $err ?: 'Request failed'];
  }
  $json = json_decode($body, true);
  if (!is_array($json)) {
    return ['__ok' => false, '__http' => $code, '__error' => 'Invalid JSON response', '__raw' => $body];
  }
  $json['__ok'] = ($code >= 200 && $code < 300);
  $json['__http'] = $code;
  return $json;
}
function pubchem_fetch_properties(string $route, string $termRaw, string $propsCsv, int $maxPolls = 18, int $sleepMs = 250): array {
  $base = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound';
  $termPath = rawurlencode($termRaw);
  $url = "{$base}/{$route}/{$termPath}/property/{$propsCsv}/JSON";
  $data = http_get_json($url);
  if (($data['__ok'] ?? false) && isset($data['PropertyTable']['Properties'])) {
    return ['data' => $data, 'final_url' => $url, 'listkey' => null, 'attempts' => 0];
  }
  $listKey = null;
  if (isset($data['Waiting']['ListKey']) && is_string($data['Waiting']['ListKey'])) {
    $listKey = $data['Waiting']['ListKey'];
  }
  elseif (isset($data['ListKey']) && is_string($data['ListKey'])) {
    $listKey = $data['ListKey'];
  }
  if (!$listKey) {
    return ['data' => $data, 'final_url' => $url, 'listkey' => null, 'attempts' => 0];
  }
  $pollUrl = "{$base}/listkey/" . rawurlencode($listKey) . "/property/{$propsCsv}/JSON";
  for ($i = 1; $i <= $maxPolls; $i++) {
    usleep($sleepMs * 1000);
    $polled = http_get_json($pollUrl);
    if (($polled['__ok'] ?? false) && isset($polled['PropertyTable']['Properties'])) {
      return ['data' => $polled, 'final_url' => $pollUrl, 'listkey' => $listKey, 'attempts' => $i];
    }
    if (isset($polled['Fault'])) {
      return ['data' => $polled, 'final_url' => $pollUrl, 'listkey' => $listKey, 'attempts' => $i];
    }
  }
  return ['data' => $data, 'final_url' => $pollUrl, 'listkey' => $listKey, 'attempts' => $maxPolls];
}
function normalize_formula_string(string $s): string {
  $s = normalize_subscripts($s);
  $s = str_replace(["·", "∙", "⋅"], ".", $s);
  $s = preg_replace('/\s+/u', '', $s);
  return $s;
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
class FormulaParser {
  private string $s;
  private int $i = 0;

  public function __construct(string $s) {
    $this->s = $s;
    $this->i = 0;
  }
  private function peek(): ?string {
    if ($this->i >= strlen($this->s)) return null;
    return $this->s[$this->i];
  }
  private function next(): ?string {
    if ($this->i >= strlen($this->s)) return null;
    return $this->s[$this->i++];
  }
  private function consume(string $ch): bool {
    if ($this->peek() === $ch) { $this->i++; return true; }
    return false;
  }
  private function parse_int(): ?int {
    $start = $this->i;
    while (($c = $this->peek()) !== null && $c >= '0' && $c <= '9') $this->i++;
    if ($this->i === $start) return null;
    return (int)substr($this->s, $start, $this->i - $start);
  }
  private function parse_count(): ?float {
    $start = $this->i;
    $int = $this->parse_int();
    if ($int === null) return null;
    if ($this->peek() === '.') {
      $save = $this->i;
      $this->i++;
      $fracStart = $this->i;
      while (($c = $this->peek()) !== null && $c >= '0' && $c <= '9') $this->i++;
      if ($this->i > $fracStart) {
        $after = $this->peek();
        if ($this->is_count_terminator($after)) {
          $num = substr($this->s, $start, $this->i - $start);
          return (float)$num;
        }
      }
      $this->i = $save;
    }
    return (float)$int;
  }  
  private function merge_counts(array &$a, array $b, float $mult = 1.0): void {
    foreach ($b as $el => $n) {
      $a[$el] = ($a[$el] ?? 0.0) + $n * $mult;
    }
  }
  private function is_count_terminator(?string $c): bool {
    if ($c === null) return true;
    if ($c === ')' || $c === ']') return true;
    if ($c === '+' || $c === '·' || $c === '.') return true;
    if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") return true;
    return false;
  }
  private function parse_fragment(): array {
    $mult = $this->parse_int();
    if ($mult === null) $mult = 1;
    $frag = [];
    $parsedAny = false;
    $this->skip_ws();
    while (true) {
      $this->skip_ws();
      $c = $this->peek();
      if ($c === null || $c === '.' || $c === '·' || $c === '+' || $c === ')' || $c === ']') break;
      $group = $this->parse_group();
      $this->merge_counts($frag, $group, 1.0);
      $parsedAny = true;
    }
    if (!$parsedAny) {
      if ($mult !== 1) throw new Exception("Multiplier {$mult} not followed by a formula at position {$this->i}");
      throw new Exception("Expected element or '(' or '[' at position {$this->i}");
    }
    if ($mult !== 1) {
      foreach ($frag as $el => $n) $frag[$el] = $n * $mult;
    }
    return $frag;
  }
  private function parse_group(): array {
    $c = $this->peek();
    if ($c === '(' || $c === '[') {
      $open = $this->next();
      $close = ($open === '(') ? ')' : ']';
      $inner = $this->parse_until_close($close);
      if (!$this->consume($close)) {
        throw new Exception("Unclosed '{$open}' at position {$this->i}");
      }
      $count = $this->parse_count();
      if ($count === null) $count = 1.0;
      foreach ($inner as $el => $n) $inner[$el] = $n * $count;
      return $inner;
    }
    $el = $this->parse_element();
    if ($el === null) throw new Exception("Expected element at position {$this->i}");
    $count = $this->parse_count();
    if ($count === null) $count = 1.0;
    return [$el => $count];
  }
  private function skip_ws(): void {
    while (true) {
      $c = $this->peek();
      if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
        $this->i++;
        continue;
      }
      return;
    }
  }
  private function parse_until_close(string $close): array {
    $sum = [];
    while (true) {
      $this->skip_ws();
      $c = $this->peek();
      if ($c === null || $c === $close) break;
      if ($c === '.' || $c === '·') {
        $this->next();
        continue;
      }
      $grp = $this->parse_group();
      $this->merge_counts($sum, $grp, 1.0);
    }
    return $sum;
  }
  private function parse_element(): ?string {
    $c1 = $this->peek();
    if ($c1 === null) return null;
    if (!($c1 >= 'A' && $c1 <= 'Z')) return null;
    $this->next();
    $c2 = $this->peek();
    if ($c2 !== null && ($c2 >= 'a' && $c2 <= 'z')) {
      $this->next();
      return $c1 . $c2;
    }
    return $c1;
  }
  private function skip_separators(): void {
    while (true) {
      $c = $this->peek();
      if ($c === null) return;
      if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
        $this->i++;
        continue;
      }
      if ($c === '+') {
        $this->i++;
        continue;
      }
      return;
    }
  }

  public function parse_all(): array {
    $total = [];
    $this->skip_separators();
    $this->merge_counts($total, $this->parse_fragment(), 1);
    while (true) {
      $this->skip_separators();
      while (($c = $this->peek()) !== null && ($c === '.' || $c === '·')) {
        $this->next();
        $this->skip_separators();
      }
      if ($this->peek() === null) break;
      $this->merge_counts($total, $this->parse_fragment(), 1);
    }
    $this->skip_separators();
    if ($this->peek() !== null) {
      throw new Exception("Unexpected trailing characters at position {$this->i}: '" . substr($this->s, $this->i, 20) . "'");
    }
    return $total;
  }
}
function calculate_properties($nomalizedFormula) {
  $weights = atomic_weights();
  try {
    $parser = new FormulaParser($nomalizedFormula);
    $comp = $parser->parse_all();
    $mw = 0.0;
    $atoms = 0;
    $unknown = [];
    foreach ($comp as $el => $n) {
      $atoms += (int)$n;
      if (!isset($weights[$el])) {
        $unknown[] = $el;
        continue;
      }
      $mw += $weights[$el] * $n;
    }
    if (!empty($unknown)) {
      return ['ok' => false, 'error' => 'Unknown characters in formula'];
    }
    ksort($comp);
    return [
      'ok' => true,
      'normalized' => $nomalizedFormula,
      'molecular_weight' => $mw,
      'total_atoms' => $atoms,
      'composition' => $comp,
    ];
  } 
  catch (Exception $e) {
      return ['ok' => false, 'error' => 'Failed to parse formula', 'message' => $e->getMessage()];
  }
}
function pubchem_fetch_record(int $cid, string $recordType): array {
  $base = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid';
  $url = "{$base}/{$cid}/record/JSON";
  if ($recordType === '3d') {
    $url .= '?record_type=3d';
  }
  $data = http_get_json($url);
  return ['data' => $data, 'url' => $url];
}

$raw  = $q;
$norm = normalize_subscripts($raw);
$__jo_pdo = null;
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
  $__jo_pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Database connection failed', 'error_message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;    
}

$__jo_cache_ui = $raw; 
if ($__jo_pdo) {
  try {
    $row = jo_components_select_by_ui_name($__jo_pdo, $__jo_cache_ui);
    if (!$row && $norm !== $raw) {
      $row = jo_components_select_by_ui_name($__jo_pdo, $norm);
      if ($row) $__jo_cache_ui = $norm;
    }
    if ($row) {
      if (isset($row['pubchem_details']) && $row['pubchem_details'] !== null && $row['pubchem_details'] !== '' && !$forceRefresh) {
        $cached = json_decode((string)$row['pubchem_details'], true);
        $cached['cached'] = true;
        $cached['component_id'] = $row['id'];
        if (is_array($cached)) {
          echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
          exit;
        }
      }
    } 
    else {
      if(!$checkOnly) jo_components_insert_placeholder($__jo_pdo, $__jo_cache_ui);
    }
  } 
  catch (Throwable $e) {
    $__jo_pdo = null;
  }
}
else {
  echo json_encode(['ok' => false, 'error' => 'Database connection failed; cache unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

$route = looks_like_formula($raw) ? 'formula' : 'name';
$props = 'MolecularFormula,MolecularWeight,CanonicalSMILES,IUPACName,Title';
$termForRoute = ($route === 'formula') ? $norm : $raw;
$res = pubchem_fetch_properties($route, $termForRoute, $props);
$data = $res['data'];
$finalUrl = $res['final_url'];
$listKey = $res['listkey'];
$attempts = $res['attempts'];
if (!(isset($data['PropertyTable']['Properties']) && is_array($data['PropertyTable']['Properties'])) && $route === 'formula') {
  $fallback = pubchem_fetch_properties('name', $raw, $props);
  $fbData = $fallback['data'];
  if (isset($fbData['PropertyTable']['Properties']) && is_array($fbData['PropertyTable']['Properties'])) {
    $route = 'name_fallback';
    $data = $fbData;
    $finalUrl = $fallback['final_url'];
    $listKey = $fallback['listkey'];
    $attempts = $fallback['attempts'];
  }
}

$propsArr = $data['PropertyTable']['Properties'] ?? null;
if (!is_array($propsArr) || !count($propsArr)) {
  $calculatedProperties = calculate_properties(normalize_formula_string($raw));
  $out = [
    'ok' => false,
    'query' => $raw,
    'normalized' => $norm,
    'route' => $route,
    'idx' => $idx,
    'record' => $recordType,
    'pubchem_url' => $finalUrl,
    'listkey' => $listKey,
    'poll_attempts' => $attempts,
    'calculated_properties' => $calculatedProperties,
    'error' => isset($data['Fault']) ? 'PubChem Fault' : 'No results from PubChem (or async job not ready within poll window)',
  ];
  if (isset($data['Fault'])) $out['pubchem_fault'] = $data['Fault'];
  if ($__jo_pdo) {
    try { 
      jo_components_update_from_out($__jo_pdo, $__jo_cache_ui, $out, $calculatedProperties, $checkOnly); 
    } 
    catch (Throwable $e) {
      echo json_encode(['ok' => false, 'error' => 'Database record failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      exit;     
    }
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

$selIndex0 = $idx - 1;
if ($selIndex0 >= count($propsArr)) $selIndex0 = count($propsArr) - 1;
if ($selIndex0 < 0) $selIndex0 = 0;
$sel = $propsArr[$selIndex0];
$cid = isset($sel['CID']) ? (int)$sel['CID'] : 0;
$formula = isset($sel['MolecularFormula']) && is_string($sel['MolecularFormula']) ? $sel['MolecularFormula'] : null;
$name = null;
if (isset($sel['IUPACName']) && is_string($sel['IUPACName']) && trim($sel['IUPACName']) !== '') {
  $name = $sel['IUPACName'];
} 
elseif (isset($sel['Title']) && is_string($sel['Title']) && trim($sel['Title']) !== '') {
  $name = $sel['Title'];
}

$selected = [
  'CID' => $cid ?: null,
  'name' => $name,
  'MolecularFormula' => $formula,
  'MolecularWeight' => isset($sel['MolecularWeight']) ? (float)$sel['MolecularWeight'] : null,
  'CanonicalSMILES' => isset($sel['CanonicalSMILES']) && is_string($sel['CanonicalSMILES']) ? $sel['CanonicalSMILES'] : null,
  'total_atoms' => atom_count_from_formula($formula),
  'result_index' => $selIndex0 + 1,
  'result_count' => count($propsArr),
];

$structure = null;
$structureUrl = null;
if ($cid > 0) {
  $rec = pubchem_fetch_record($cid, $recordType);
  $structureUrl = $rec['url'];
  if (($rec['data']['__ok'] ?? false) && isset($rec['data']['PC_Compounds'])) {
    $tmp = $rec['data'];
    unset($tmp['__ok'], $tmp['__http']);
    $structure = $tmp;
  } else {
    $structure = $rec['data'];
  }
}

$hits = [];
foreach ($propsArr as $p) {
  $hits[] = [
    'CID' => $p['CID'] ?? null,
    'name' => $p['IUPACName'] ?? ($p['Title'] ?? null),
    'MolecularFormula' => $p['MolecularFormula'] ?? null,
    'MolecularWeight' => isset($p['MolecularWeight']) ? (float)$p['MolecularWeight'] : null,
    'CanonicalSMILES' => $p['CanonicalSMILES'] ?? null,
  ];
  if (count($hits) >= 10) break;
}

if($route == 'formula') $norm = normalize_formula_string($raw);
elseif ($formula ?? null) $norm = normalize_formula_string($formula);
else $norm = normalize_formula_string($raw);
$calculatedProperties = calculate_properties($norm);

$out = [
  'ok' => ($cid > 0),
  'query' => $raw,
  'normalized' => $norm,
  'route' => $route,
  'idx' => $idx,
  'record' => $recordType,
  'pubchem_properties_url' => $finalUrl,
  'pubchem_structure_url' => $structureUrl,
  'listkey' => $listKey,
  'poll_attempts' => $attempts,
  'selected' => $selected,
  'calculated_properties' => $calculatedProperties,
  'structure' => $structure,
  'hits_preview' => $hits,
  'cached' => false
];
if (isset($data['Fault'])) {
  $out['ok'] = false;
  $out['pubchem_fault'] = $data['Fault'];
}
if ($__jo_pdo) {
  try { 
    $out['component_id'] = jo_components_update_from_out($__jo_pdo, $__jo_cache_ui, $out, $calculatedProperties, $checkOnly); 
  } 
  catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database record failed', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;     
  }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);