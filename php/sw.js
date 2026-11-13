// Fiyatavı servis çalışanı: siteyi "ana ekrana eklenebilir" yapar.
// Sayfalar her zaman önce internetten alınır (fiyatlar güncel kalsın); bağlantı yoksa son kaydedilen sayfa gösterilir.
// API istekleri hiç önbelleğe alınmaz.
const SURUM = "fiyatavi-v1";
const TEMEL = ["/", "/manifest.webmanifest", "/icon-192.png", "/icon-512.png"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(SURUM).then((c) => c.addAll(TEMEL)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== SURUM).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener("fetch", (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== "GET" || url.origin !== location.origin || url.pathname.startsWith("/api/")) return;
  if (req.mode === "navigate") {
    e.respondWith(fetch(req).then((res) => {
      if (res.ok && url.pathname === "/") caches.open(SURUM).then((c) => c.put("/", res.clone()));
      return res;
    }).catch(() => caches.match("/")));
    return;
  }
  if (TEMEL.includes(url.pathname)) e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
});
