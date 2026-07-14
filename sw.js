/* FLIXO Escrow — minimal PWA service worker */
const CACHE = 'flixo-shell-v2';
const ASSETS = [
  './assets/css/flixo-app.css',
  './assets/css/style.css',
  './assets/js/app-shell.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(ASSETS).catch(() => {}))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (
    url.pathname.includes('/controllers/') ||
    url.pathname.includes('/api/') ||
    url.pathname.endsWith('.php')
  ) {
    return;
  }
  event.respondWith(
    caches.match(req).then((cached) =>
      cached || fetch(req).then((res) => {
        if (res.ok && (url.pathname.endsWith('.css') || url.pathname.endsWith('.js'))) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(() => cached)
    )
  );
});
