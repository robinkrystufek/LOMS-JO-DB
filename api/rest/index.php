<?php
declare(strict_types=1);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$restIndex = array_search('rest', $parts);
$segments = array_slice($parts, $restIndex + 1);
$resource = $segments[0] ?? null;
switch ($resource) {
    case 'records':
        // /api/rest/records
        if (!isset($segments[1])) {
            require_once __DIR__ . '/../browse_records.php';
            exit;
        }
        $id = (int)$segments[1];
        // /api/rest/records/123
        if (!isset($segments[2])) {
            $_GET['record_id'] = $id;
            require_once __DIR__ . '/../browse_records.php';
            exit;
        }
        // /api/rest/records/123/citation
        if ($segments[2] === 'citation') {
            $_GET['id'] = $id;
            $_GET['type'] = 'citation';
            $_GET['format'] = $segments[3] ?? $_GET['format'] ?? 'ris';
            require_once __DIR__ . '/../export_entry.php';
            exit;
        }
        // /api/rest/records/123/data
        if ($segments[2] === 'data') {
            $_GET['id'] = $id;
            $_GET['type'] = $segments[3] ?? $_GET['type'] ?? 'loms';
            require_once __DIR__ . '/../export_entry.php';
            exit;
        }
        // /api/rest/records/123/audit-trail
        if ($segments[2] === 'audit-trail') {
            $_GET['id'] = $id;
            require_once __DIR__ . '/../get_audit_trail.php';
            exit;
        }
    case 'publications':
        // /api/rest/publications
        if (!isset($segments[1])) {
            require_once __DIR__ . '/../get_pub_metadata.php';
            exit;
        }
        // /api/rest/publications/10.xxx/yyy
        $doi = implode('/', array_slice($segments, 1));
        $_GET['doi'] = $doi;
        require_once __DIR__ . '/../get_pub_metadata.php';
        exit;    
}
http_response_code(404);
echo json_encode(["error" => "Endpoint not found"]);