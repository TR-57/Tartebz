<?php
/*
 | Local development only – mimics the production nginx layout on one port:
 |   php -S localhost:8800 tools/dev-server.php      (run from the repo root)
 | /api/*  -> Laravel (backend/public/index.php)
 | /admin/ -> admin/index.html
 | /shop   -> shop.html (clean URLs)
 | anything else -> the static file in the repo root
 */
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

if (str_starts_with($path, '/api/') || $path === '/api') {
    $_SERVER['SCRIPT_FILENAME'] = $root.'/backend/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($root.'/backend/public');
    require $root.'/backend/public/index.php';
    return true;
}
if (str_contains($path, '..') || preg_match('#/(\.|backend/|tools/|i18n/.*\.json$)#', $path)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
if ($path === '/admin' ) { header('Location: /admin/'); return true; }
if (str_ends_with($path, '/')) {
    $path .= 'index.html';
}
$file = $root.$path;
if (! is_file($file) && is_file($file.'.html')) {
    $file .= '.html';
}
if (! is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'wav' => 'audio/wav', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon', 'pdf' => 'application/pdf'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
header('Cache-Control: no-cache');
readfile($file);
return true;
