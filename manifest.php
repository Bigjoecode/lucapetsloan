<?php
/* Web app manifest, so phones can install the app on the home screen. */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name'             => APP_NAME,
    'short_name'       => APP_NAME,
    'description'      => APP_TAGLINE . ' Digital daily savings and loans, verified in person.',
    'start_url'        => url('dashboard.php') . '?source=app',
    'scope'            => (BASE_URL ?: '') . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#0b4f3c',
    'theme_color'      => '#0b4f3c',
    'icons'            => [
        ['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => url('assets/icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Save today', 'url' => url('savings.php'), 'icons' => [['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192']]],
        ['name' => 'My loan', 'url' => url('loans.php'), 'icons' => [['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
