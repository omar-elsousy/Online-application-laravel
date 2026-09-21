<?php

$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Add CORS headers for all requests (Flutter Web and API)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
// Required for Chrome/Edge Private Network Access policy
// (allows requests from localhost to private IPs like 10.1.x.x)
header("Access-Control-Allow-Private-Network: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    // If it's a file, serve it with proper Content-Type and CORS headers
    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $mimeTypes = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'css'  => 'text/css',
        'js'   => 'text/javascript',
        'json' => 'application/json',
        'ico'  => 'image/x-icon',
    ];

    if (isset($mimeTypes[$extension])) {
        header('Content-Type: ' . $mimeTypes[$extension]);
        header('Content-Length: ' . filesize($publicPath.$uri));
        readfile($publicPath.$uri);
        exit;
    }

    return false;
}

require_once $publicPath.'/index.php';
