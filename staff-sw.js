/* Tartebz orders board – service worker.
   Shows a notification for each new order, even when the board is closed or the screen is asleep
   (Android, Windows, Mac; iPhone/iPad when the board is added to the Home Screen). Nothing is cached. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

function lang() {
  return caches.open('tz-staff').then(function (c) { return c.match('/staff-lang'); })
    .then(function (r) { return r ? r.text() : 'ar'; }).catch(function () { return 'ar'; });
}

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { title: e.data ? e.data.text() : 'New order' }; }
  e.waitUntil(lang().then(function (l) {
    var ar = l !== 'en';
    return self.registration.showNotification((ar && d.titleAr) || d.title || 'New order', {
      body: (ar && d.bodyAr) || d.body || '',
      tag: d.tag || 'order', renotify: true, requireInteraction: true,
      icon: '/assets/img/staff-icon-192.png', badge: '/assets/img/staff-icon-192.png',
      vibrate: [300, 120, 300, 120, 600], lang: ar ? 'ar' : 'en', dir: ar ? 'rtl' : 'ltr',
      data: { url: d.url || '/staff' }
    });
  }).then(function () {
    // An open board plays its sound and refreshes the list at once.
    return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      list.forEach(function (c) { c.postMessage({ type: 'new-order', orderId: d.orderId || null }); });
    });
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/staff';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf('/staff') !== -1 && 'focus' in list[i]) { list[i].postMessage({ type: 'open', url: url }); return list[i].focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
