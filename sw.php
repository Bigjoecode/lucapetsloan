<?php
/*
 * Service worker. Served from the site root so it covers every page.
 * Only static files and the offline page are cached; account pages never are.
 */
require __DIR__ . '/config.php';

header('Content-Type: application/javascript');
header('Cache-Control: no-cache');
$base = BASE_URL;
?>
const VERSION = 'lpl-v1';
const BASE = <?= json_encode($base) ?>;
const OFFLINE = BASE + '/assets/offline.html';
const PRECACHE = [OFFLINE, BASE + '/assets/css/style.css?v=3', BASE + '/assets/js/app.js?v=5', BASE + '/assets/icons/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (req.mode === 'navigate') {
    // Always fetch pages live; show the offline page only when there's no connection.
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    return;
  }
  if (url.pathname.startsWith(BASE + '/assets/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); }
      return res;
    })));
  }
});
