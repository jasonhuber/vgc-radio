/* Migration worker: the former N76 app lived at /. Its new offline worker is
   scoped to /n76/. This root worker replaces the old navigation handler and
   deliberately has no fetch handler, so the hub and both tools stay distinct. */
self.addEventListener("install", event => event.waitUntil(self.skipWaiting()));
self.addEventListener("activate", event => event.waitUntil(
  caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith("n7wgp-radio-")).map(k => caches.delete(k))))
    .then(() => self.clients.claim())
));
