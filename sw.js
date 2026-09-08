const CACHE = "n7wgp-n76-v1";
const SHELL = ["/n76/", "/n76/manifest.webmanifest", "/n76/radio-icon.svg", "/n76/og.png"];

self.addEventListener("install", event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(key => key.startsWith("n7wgp-n76-") && key !== CACHE).map(key => caches.delete(key))
  )).then(() => self.clients.claim()));
});

self.addEventListener("fetch", event => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api")) return;

  if (req.mode === "navigate") {
    if (url.pathname !== "/n76/" && url.pathname !== "/n76/index.html") return;
    event.respondWith(fetch(req).then(response => {
      const copy = response.clone();
      caches.open(CACHE).then(cache => cache.put("/n76/", copy));
      return response;
    }).catch(() => caches.match("/n76/")));
    return;
  }
  event.respondWith(caches.match(req).then(hit => hit || fetch(req).then(response => {
    const copy = response.clone();
    caches.open(CACHE).then(cache => cache.put(req, copy));
    return response;
  })));
});
