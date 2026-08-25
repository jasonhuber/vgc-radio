const CACHE = "n7wgp-radio-v1";
const SHELL = ["/", "/manifest.webmanifest", "/radio-icon.svg", "/og.png"];

self.addEventListener("install", event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(key => key.startsWith("n7wgp-radio-") && key !== CACHE).map(key => caches.delete(key))
  )).then(() => self.clients.claim()));
});

self.addEventListener("fetch", event => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api")) return;

  if (req.mode === "navigate") {
    event.respondWith(fetch(req).then(response => {
      const copy = response.clone();
      caches.open(CACHE).then(cache => cache.put("/", copy));
      return response;
    }).catch(() => caches.match("/")));
    return;
  }
  event.respondWith(caches.match(req).then(hit => hit || fetch(req).then(response => {
    const copy = response.clone();
    caches.open(CACHE).then(cache => cache.put(req, copy));
    return response;
  })));
});
