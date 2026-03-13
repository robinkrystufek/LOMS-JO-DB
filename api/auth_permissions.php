<?php
/**
 * auth_permissions.php
 *
 * Return assigned permissions for the authenticated user.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require 'config.inc.php';
require 'auth_user.inc.php';
try {
    $userInfo = get_firebase_user();
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $uid = $userInfo['user_id'] ?? null;
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    $role = $uid ? get_user_role($uid, $pdo) : "user_unregistered";
    echo json_encode([
        'ok' => true,
        'uid' => $uid,
        'role' => $role,
      ], JSON_UNESCAPED_UNICODE);
}
catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Authentication error: ' . $e->getMessage(),
        'uid' => null,
        'role' => "user_unregistered",
      ], JSON_UNESCAPED_UNICODE);
}
