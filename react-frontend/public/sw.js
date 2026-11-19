/*
 * Clinique+ service worker.
 * Only the application shell (pages and icons) is cached, so the app opens without network.
 * Medical data is NEVER cached: every API call goes to the network, and is not stored.
 */
const CACHE = 'clinique-plus-shell-v1';
const SHELL = ['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  // Never cache anything from the API or from another origin (patient data, payments).
  if (url.origin !== self.location.origin || url.pathname.includes('/api/')) return;

  if (request.mode === 'navigate') {
    // Pages: network first (fresh data and the current session), the cached shell offline.
    event.respondWith(
      fetch(request).catch(() => caches.match('/').then((cached) => cached || Response.error())),
    );
    return;
  }

  // Static assets: cache first, then network.
  event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
});
