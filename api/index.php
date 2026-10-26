<?php
// Vercel entry point: every request (except /assets) lands here and is
// dispatched to the matching page script. Only public pages are reachable.
declare(strict_types=1);

$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path === '/admin') {
    header('Location: /admin/');
    exit;
}
if ($path === '' || str_ends_with($path, '/')) {
    $path .= 'index.php';
}
$path = '/' . ltrim($path, '/');

$public = preg_match('#^/(admin/)?[a-z_]+\.php$#', $path) === 1
    && $path !== '/config.php'
    && is_file($root . $path);

if (!$public) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Not found</title>'
        . '<body style="font-family:system-ui;padding:3rem;text-align:center"><h1>Page not found</h1><p><a href="/">Go to the home page</a></p>';
    exit;
}

$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['SCRIPT_FILENAME'] = $root . $path;
chdir(dirname($root . $path));
require $root . $path;
