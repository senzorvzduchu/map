/*
 * Service worker for senzorvzduchu.cz/map/
 *
 * Strategy (no precache list, so no rebuild needed when the map changes):
 *   - navigation (HTML):      network first, fall back to cached shell, then offline page
 *   - same-origin .php / API: network first with short cache fallback (stale data beats no data)
 *   - static assets:          stale-while-revalidate (js, css, fonts, images, map libs on CDNs)
 *   - map tiles:              cache-first with a size cap, so panning around offline still works a bit
 *
 * Bump VERSION whenever you want to force clients to drop old caches.
 */
const VERSION = 'sv-map-v1';
const SHELL = `${VERSION}-shell`;
const ASSETS = `${VERSION}-assets`;
const DATA = `${VERSION}-data`;
const TILES = `${VERSION}-tiles`;
const TILE_LIMIT = 400;               // max cached tiles (~ 10-20 MB)
const OFFLINE_URL = '/map/offline.html';

const CDN_HOSTS = [
  'unpkg.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com',
  'fonts.googleapis.com', 'fonts.gstatic.com', 'code.jquery.com'
];
const TILE_HOSTS = [
  'tile.openstreetmap.org', 'basemaps.cartocdn.com', 'tiles.stadiamaps.com',
  'api.mapbox.com', 'server.arcgisonline.com', 'tile.thunderforest.com'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL).then((c) => c.addAll([OFFLINE_URL, '/map/manifest.json']))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function hostMatches(url, list) {
  return list.some((h) => url.hostname === h || url.hostname.endsWith('.' + h));
}

async function networkFirst(request, cacheName, timeoutMs = 8000) {
  const cache = await caches.open(cacheName);
  try {
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), timeoutMs);
    const res = await fetch(request, { signal: controller.signal });
    clearTimeout(t);
    if (res && res.ok) cache.put(request, res.clone());
    return res;
  } catch (e) {
    const cached = await cache.match(request);
    if (cached) return cached;
    throw e;
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const fetching = fetch(request).then((res) => {
    if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone());
    return res;
  }).catch(() => undefined);
  return cached || fetching || fetch(request);
}

async function cacheFirstCapped(request, cacheName, limit) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  const res = await fetch(request);
  if (res && (res.ok || res.type === 'opaque')) {
    cache.put(request, res.clone());
    // crude LRU: drop oldest entries when over the cap
    const keys = await cache.keys();
    if (keys.length > limit) {
      await Promise.all(keys.slice(0, keys.length - limit).map((k) => cache.delete(k)));
    }
  }
  return res;
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // 1. page navigations
  if (req.mode === 'navigate') {
    event.respondWith(
      networkFirst(req, SHELL).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  // 2. map tiles
  if (hostMatches(url, TILE_HOSTS)) {
    event.respondWith(cacheFirstCapped(req, TILES, TILE_LIMIT));
    return;
  }

  // 3. same-origin data endpoints (PHP proxies, JSON, SQLite history exports)
  if (url.origin === self.location.origin &&
      (url.pathname.endsWith('.php') || url.pathname.endsWith('.json') || url.pathname.includes('/api/'))) {
    // manifest is static, treat as asset
    if (url.pathname.endsWith('/manifest.json')) {
      event.respondWith(staleWhileRevalidate(req, ASSETS));
      return;
    }
    event.respondWith(networkFirst(req, DATA, 12000));
    return;
  }

  // 4. static assets, same origin or known CDNs
  if (url.origin === self.location.origin || hostMatches(url, CDN_HOSTS)) {
    if (/\.(js|css|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|json)$/i.test(url.pathname)) {
      event.respondWith(staleWhileRevalidate(req, ASSETS));
      return;
    }
  }
  // everything else: straight to network
});

// Lets the page trigger an immediate update after you deploy a new sw.js
self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
