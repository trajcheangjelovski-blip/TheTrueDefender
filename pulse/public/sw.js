// TheTrueDefender — service worker (web push + PWA installability)

// Activate immediately so the SW controls the page on first load — a
// requirement for Android to mint a trusted WebAPK (installed app) rather
// than an untrusted browser shortcut.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

// A fetch handler must exist for the browser to consider the site installable.
// Network-first with a cached shell fallback for offline navigations.
const OFFLINE_CACHE = 'ttd-shell-v1';
self.addEventListener('fetch', function (event) {
  const req = event.request;
  if (req.method !== 'GET') return; // never interfere with POST/API/checkout
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then(function (res) {
          const copy = res.clone();
          caches.open(OFFLINE_CACHE).then(function (c) { c.put('/', copy); });
          return res;
        })
        .catch(function () { return caches.match('/'); })
    );
  }
});

self.addEventListener('push', function (event) {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'TheTrueDefender', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || 'TheTrueDefender';
  const options = {
    body: data.body || '',
    // Large icon (the TTD logo) + monochrome status-bar badge, so Android shows
    // our brand instead of its default globe. Fall back to the bundled assets.
    icon: data.icon || '/icon-192.png',
    badge: data.badge || '/icon-badge.png',
    // A distinct tag per story so different posts don't collapse into one,
    // while re-pushing the same post replaces (and re-alerts) its notification.
    tag: data.url || 'ttd-post',
    renotify: true,
    data: { url: data.url || '/' },
  };
  // Big expanded image (the post photo), when available.
  if (data.image) options.image = data.image;

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (const client of list) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
