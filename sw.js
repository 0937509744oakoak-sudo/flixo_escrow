/* FLIXO Escrow — minimal PWA service worker */
const CACHE = 'flixo-shell-v3';
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

/**
 * Web Push arrives with no payload (see includes/WebPush.php) — fetch the
 * actual notification content from the app (cookies are sent automatically
 * for same-origin requests) before showing it.
 */
self.addEventListener('push', (event) => {
  event.waitUntil(
    self.registration.scope
      ? fetch(new URL('controllers/NotificationController.php?action=latest_unread', self.registration.scope), {
          credentials: 'include',
        })
          .then((res) => (res.ok ? res.json() : null))
          .then((data) => {
            const notif = data && data.status === 'success' ? data.notification : null;
            const title = notif ? notif.title : 'FLIXO';
            const body = notif ? notif.body : 'คุณมีการแจ้งเตือนใหม่';
            const url = notif && notif.url ? notif.url : new URL('dashboard.php', self.registration.scope).toString();
            return self.registration.showNotification(title, {
              body,
              icon: './assets/icons/icon-192.svg',
              badge: './assets/icons/icon-192.svg',
              data: { url },
            });
          })
          .catch(() => self.registration.showNotification('FLIXO', {
            body: 'คุณมีการแจ้งเตือนใหม่',
            data: { url: new URL('dashboard.php', self.registration.scope).toString() },
          }))
      : Promise.resolve()
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || self.registration.scope;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url === url && 'focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
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
