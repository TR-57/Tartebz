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

$laravel = function () use ($root) {
    $_SERVER['SCRIPT_FILENAME'] = $root.'/backend/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($root.'/backend/public');
    require $root.'/backend/public/index.php';
    return true;
};
// Server-built pages: product pages (link previews, Google), sitemap, robots.
if (preg_match('#^/product/.+#', $path) || $path === '/sitemap.xml' || $path === '/robots.txt' || $path === '/.well-known/apple-developer-merchantid-domain-association') {
    return $laravel();
}
// Link-preview pictures (1200×630 JPEG).
if (preg_match('#^/img/og/((?:uploads|assets)/.+)$#', $path, $m)) {
    $copy = $root.'/img/og/'.$m[1].'.jpg';
    if (is_file($copy)) {
        header('Content-Type: image/jpeg');
        readfile($copy);
        return true;
    }
    return $laravel();
}
if (str_starts_with($path, '/api/') || $path === '/api') {
    $_SERVER['SCRIPT_FILENAME'] = $root.'/backend/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($root.'/backend/public');
    require $root.'/backend/public/index.php';
    return true;
}
// Resized images: serve the copy if made, else Laravel makes it (same as the Hostinger .htaccess).
if (preg_match('#^/img/(\d+/(?:uploads|assets)/.+)$#', $path, $m)) {
    $copy = $root.'/img/'.$m[1].'.webp';
    if (is_file($copy)) {
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=2592000');
        readfile($copy);
        return true;
    }
    $_SERVER['SCRIPT_FILENAME'] = $root.'/backend/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($root.'/backend/public');
    require $root.'/backend/public/index.php';
    return true;
}
if (preg_match('#^/wp-content/uploads/(.+)$#', $path, $m)) {
    header('Location: /uploads/wp/'.$m[1], true, 301);
    return true;
}
if (str_contains($path, '..') || preg_match('#/(\.|backend/|tools/|deploy/|docs/|i18n/.*\.json$)#', $path)) {
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
if ($ext === 'html') {
    echo str_replace('__SITE__', 'http://'.($_SERVER['HTTP_HOST'] ?? 'localhost:8800'), file_get_contents($file));
    return true;
}
readfile($file);
return true;
