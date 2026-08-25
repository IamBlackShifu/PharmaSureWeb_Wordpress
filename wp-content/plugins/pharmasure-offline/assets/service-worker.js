const CACHE = 'pharmasure-shell-v1';
const OFFLINE = new Response('<!doctype html><html><meta name="viewport" content="width=device-width"><title>PharmaSure Offline</title><body><main><h1>Connection unavailable</h1><p>PharmaSure cannot reach the pharmacy server. Previously submitted work remains on this device until a signed sync succeeds.</p><p>Do not assume stock, dispensing, sales or payments were posted until the server confirms them.</p></main></body></html>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.put('/__pharmasure_offline__', OFFLINE.clone())).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('pharmasure-shell-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/wp-json/') || url.pathname.startsWith('/wp-admin/') || url.pathname.includes('admin-ajax.php') || url.searchParams.has('_wpnonce')) return;
  if (request.mode === 'navigate') { event.respondWith(fetch(request).catch(() => caches.match('/__pharmasure_offline__'))); return; }
  if (/\.(?:css|js|png|jpg|jpeg|svg|webp|woff2?)$/i.test(url.pathname)) { event.respondWith(caches.open(CACHE).then(cache => cache.match(request).then(hit => hit || fetch(request).then(response => { if (response.ok && response.type === 'basic') cache.put(request, response.clone()); return response; })))); }
});
