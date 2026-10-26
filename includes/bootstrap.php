<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/layout.php';

// Never show stack traces to visitors on the live site; log them instead.
if (IS_VERCEL) {
    ini_set('display_errors', '0');
}
set_exception_handler(function (Throwable $e): void {
    error_log('[lucapetloan] ' . $e);
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = IS_VERCEL ? '' : '<pre style="white-space:pre-wrap;text-align:left;font-size:12px;color:#b42318">' . e($e->getMessage()) . '</pre>';
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable</title>'
        . '<body style="font-family:system-ui;background:#f3f5f2;color:#13201b;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px">'
        . '<div style="max-width:440px;text-align:center"><h1 style="font-size:1.4rem">We\'ll be right back</h1>'
        . '<p style="color:#5d6b65">' . e(APP_NAME) . ' can\'t reach its database at the moment. Please try again in a few minutes.</p>' . $detail . '</div>';
});

if (session_status() === PHP_SESSION_NONE) {
    if (SESSION_DRIVER === 'db') {
        session_set_save_handler(new DbSessionHandler(), true);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
        ini_set('session.gc_maxlifetime', '86400');
    }
    session_name('lpl_session');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_start();
}

// Send visitors to the installer until the app has been set up.
if (!defined('SKIP_INSTALL_CHECK') && !app_installed()) {
    redirect('install.php');
}
