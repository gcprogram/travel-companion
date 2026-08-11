/**
 * App-shell service worker: caches static assets so the UI itself loads
 * offline, and lets the browser offer "Add to Home Screen".
 *
 * Deliberately does NOT cache HTML pages beyond a bare offline fallback:
 * every page embeds a CSRF token tied to the current session, so serving a
 * stale cached page would mean stale (rejected) form submits. Offline entry
 * creation is handled separately by offline-queue.js (IndexedDB), not by
 * serving old HTML from here.
 *
 * CACHE_VERSION: bump this on every deploy that changes any cached file.
 * skipWaiting()/clients.claim() below make a new version take over
 * immediately instead of waiting for every open tab to close — we've been
 * burned enough times this session by stale-cache debugging (OPcache,
 * browser JS cache) to not repeat that mistake here too.
 */
const CACHE_VERSION = 'v4';
const CACHE_NAME = 'tc-shell-' + CACHE_VERSION;

const PRECACHE_URLS = [
  '/assets/css/app.css',
  '/assets/js/chunked-upload.js',
  '/assets/js/confirm-submit.js',
  '/assets/js/day-entry-form.js',
  '/assets/js/photo-upload.js',
  '/assets/js/video-compress.js',
  '/assets/js/video-geotag.js',
  '/assets/js/video-upload.js',
  '/assets/js/vendor/mp4-muxer.js',
  '/assets/js/offline-queue.js',
  '/assets/js/offline-gallery.js',
  '/assets/js/pwa-register.js',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/manifest.json',
  '/offline.html',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) { return cache.addAll(PRECACHE_URLS); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (names) {
        return Promise.all(
          names.filter(function (name) { return name !== CACHE_NAME; })
            .map(function (name) { return caches.delete(name); })
        );
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  const request = event.request;
  if (request.method !== 'GET') {
    return; // Never intercept POSTs - those go through offline-queue.js instead.
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return; // Leave cross-origin requests (e.g. YouTube thumbnails) alone.
  }

  const isStaticAsset = PRECACHE_URLS.includes(url.pathname);

  if (isStaticAsset) {
    // Cache-first: these are versioned by CACHE_VERSION, safe to serve stale
    // until the next deploy bumps it.
    event.respondWith(
      caches.match(request).then(function (cached) {
        return cached || fetch(request);
      })
    );
    return;
  }

  // Everything else (HTML pages, photo/video bytes, API-style endpoints):
  // network-first, so logged-in state / CSRF tokens / gallery contents are
  // never served stale. Only fall back to a cache/offline page if the
  // network is truly unreachable.
  event.respondWith(
    fetch(request).catch(function () {
      if (request.mode === 'navigate') {
        return caches.match('/offline.html');
      }
      // caches.match() resolves to undefined on a miss, which
      // respondWith() can't turn into a Response - fall through to a
      // real (failing) network response instead of throwing. That retry
      // can itself reject (network still down) - respondWith() needs a
      // settled Response either way, so that gets a plain error Response
      // rather than an actually uncaught rejection.
      return caches.match(request).then(function (cached) {
        return cached || fetch(request);
      }).catch(function () {
        return new Response('', { status: 503, statusText: 'Offline' });
      });
    })
  );
});
