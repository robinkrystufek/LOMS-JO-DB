<?php
/**
 * index.php
 *
 * Main entry point for the REST API. Routes requests to appropriate handlers
 */
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
      require_once __DIR__ . '/../get_records.php';
      exit;
    }
    // /api/rest/records/csv
    if($segments[1] === 'csv') {
      require_once __DIR__ . '/../export_csv.php';
      exit;
    }
    if (!is_numeric($segments[1])) {
      http_response_code(400);
      echo json_encode(["error" => "Invalid record ID"]);
      exit;
    }
    $id = (int)$segments[1];
    // /api/rest/records/123
    if (!isset($segments[2])) {
      $_GET['record_id'] = $id;
      require_once __DIR__ . '/../get_records.php';
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
  case 'components':
    // /api/rest/components
    if (!isset($segments[1])) {
      require_once __DIR__ . '/../get_components.php';
      exit;
    }
    // /api/rest/components/123
    if (is_numeric($segments[1])) {
      $_GET['id'] = (int)$segments[1];
      require_once __DIR__ . '/../get_components.php';
      exit;
    }
    case 'elements':
      // /api/rest/elements
      if (!isset($segments[1])) {
        $_GET['match_records'] = $_GET['match_records'] ?? 1;
        require_once __DIR__ . '/../get_elements.php';
        exit;
      }
      // /api/rest/elements/H
      $_GET['element_q'] = (string)$segments[1];
      require_once __DIR__ . '/../get_records.php';
      exit;

}
http_response_code(404);
echo json_encode(["error" => "Endpoint not found"]);