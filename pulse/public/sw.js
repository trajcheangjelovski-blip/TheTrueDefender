// TheTrueDefender — service worker (web push)
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
