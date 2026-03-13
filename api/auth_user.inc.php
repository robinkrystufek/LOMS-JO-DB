<?php
declare(strict_types=1);
session_start();
const FIREBASE_PROJECT_ID = 'loms-f2eaf';

function get_authorization_header(): string {
  if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return (string)$_SERVER['HTTP_AUTHORIZATION'];
  if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
      if (strtolower((string)$k) === 'authorization') return (string)$v;
    }
  }
  return '';
}
function get_bearer_token(): ?string {
  $auth = get_authorization_header();
  if ($auth && preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $auth, $m)) return $m[1];
  return null;
}
function b64url_decode(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  if ($out === false) throw new RuntimeException('Invalid base64url');
  return $out;
}
function jwt_decode_parts(string $jwt): array {
  $parts = explode('.', $jwt);
  if (count($parts) !== 3) throw new RuntimeException('JWT must have 3 parts');
  [$h64, $p64, $s64] = $parts;
  $header = json_decode(b64url_decode($h64), true);
  $payload = json_decode(b64url_decode($p64), true);
  $sig = b64url_decode($s64);
  if (!is_array($header) || !is_array($payload)) throw new RuntimeException('JWT header/payload not JSON');
  return [
    'header' => $header,
    'payload' => $payload,
    'sig' => $sig,
    'signed' => $h64 . '.' . $p64,
  ];
}
function fetch_firebase_certs(): array {
  $cacheFile = sys_get_temp_dir() . '/firebase_securetoken_certs.json';
  $cacheTtl = 3600;
  if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached) && count($cached) > 0) return $cached;
  }
  $url = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $body = curl_exec($ch);
  if ($body === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('Failed to fetch certs: ' . $err);
  }
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($status < 200 || $status >= 300) {
    throw new RuntimeException('Failed to fetch certs, HTTP ' . $status . ': ' . $body);
  }
  $certs = json_decode($body, true);
  if (!is_array($certs) || count($certs) === 0) {
    throw new RuntimeException('Certs response not JSON object');
  }
  @file_put_contents($cacheFile, json_encode($certs));
  return $certs;
}
function verify_firebase_id_token(string $jwt, string $projectId): array {
  $parsed = jwt_decode_parts($jwt);
  $header = $parsed['header'];
  $payload = $parsed['payload'];
  $sig = $parsed['sig'];
  $signed = $parsed['signed'];
  if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('JWT alg must be RS256');
  $kid = $header['kid'] ?? null;
  if (!$kid) throw new RuntimeException('JWT missing kid');
  $issExpected = 'https://securetoken.google.com/' . $projectId;
  if (($payload['aud'] ?? null) !== $projectId) throw new RuntimeException('Invalid aud');
  if (($payload['iss'] ?? null) !== $issExpected) throw new RuntimeException('Invalid iss');
  if (empty($payload['sub']) || !is_string($payload['sub'])) throw new RuntimeException('Missing sub');
  $now = time();
  $exp = (int)($payload['exp'] ?? 0);
  $iat = (int)($payload['iat'] ?? 0);
  $skew = 60;
  if ($exp && $now > ($exp + $skew)) throw new RuntimeException('Token expired');
  if ($iat && $now < ($iat - $skew)) throw new RuntimeException('Token used before iat');
  $certs = fetch_firebase_certs();
  $pem = $certs[$kid] ?? null;
  if (!$pem) throw new RuntimeException('No matching cert for kid');
  $ok = openssl_verify($signed, $sig, $pem, OPENSSL_ALGO_SHA256);
  if ($ok !== 1) throw new RuntimeException('Invalid signature');
  return $payload;
}
function require_firebase_user(): array {
  $idToken = get_bearer_token();
  if (!$idToken) json_fail(['error' => 'Missing Authorization: Bearer <idToken>'], 401);
  try {
    $claims = verify_firebase_id_token($idToken, FIREBASE_PROJECT_ID);
  } 
  catch (Throwable $e) {
    json_fail(['error' => 'Invalid token', 'details' => $e->getMessage()], 401);
  }
  $uid = $claims['user_id'] ?? $claims['sub'] ?? null;
  if (!$uid) json_fail(['error' => 'Token missing uid'], 401);
  return $claims;
}
function get_firebase_user(): ?array {
  $idToken = get_bearer_token();
  if (!$idToken) return null;
  try {
    $claims = verify_firebase_id_token($idToken, FIREBASE_PROJECT_ID);
  } 
  catch (Throwable $e) {
    return null;
  }
  $uid = $claims['user_id'] ?? $claims['sub'] ?? null;
  if (!$uid) return null;
  return $claims;
}
function json_fail($msg, int $http = 400): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($http);
  $payload = ['ok' => false, 'error' => $msg];
  $json = json_encode($payload,
    JSON_UNESCAPED_UNICODE
    | JSON_INVALID_UTF8_SUBSTITUTE
  );
  if ($json === false) {
    $json = '{"ok":false,"error":"JSON encoding failed"}';
  }
  echo $json;
  exit;
}
function require_depositor_role(string $uid, PDO $pdo): void {
  $role = get_user_role($uid, $pdo);
  if ($role && in_array($role, ['depositor', 'reviewer', 'admin'], true)) {
    return;
  }
  json_fail(['error' => 'Insufficient permissions', 'details' => 'User lacks the depositor role or higher'], 403);
}
function get_user_role(string $uid, PDO $pdo): ?string {
  try {
    $st = $pdo->prepare("SELECT role FROM jo_contributors WHERE uid = :uid");
    $st->execute([':uid' => $uid]);
    $row = $st->fetch();
    if (is_array($row) && isset($row['role'])) {
      return (string)$row['role'];
    }
    else {
      return "user_unregistered";
    }
  } 
  catch (Exception $e) {
    json_fail(['error' => 'Database error', 'details' => $e->getMessage()], 500);
  }
  return null;
}
function update_user_info(PDO $pdo, array $userInfo): array {
  $contributor_aff   = isset($userInfo['picture']) ? explode(';', $userInfo['picture'])[0] : null;
  $contributor_orcid = isset($userInfo['picture']) ? (explode(';', $userInfo['picture'] ?? '', 2)[1] ?? null) : null;
  if ($userInfo['user_id'] === null) json_fail('Contributor info is required: Authentication error');
  try {
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
      ':uid' => $userInfo['user_id'],
      ':email' => $userInfo['email'],
      ':name' => $userInfo['name'] ?? null,
      ':affiliation' => $contributor_aff,
      ':orcid' => $contributor_orcid,
    ]);
  }
  catch (Exception $e) {
    json_fail(['error' => 'Database error', 'details' => $e->getMessage()], 500);
  }
  return [
      $userInfo['user_id'],
      $userInfo['email'],
      $userInfo['name'] ?? null,
      $contributor_aff ?: null,
      $contributor_orcid ?: null,
  ];
}