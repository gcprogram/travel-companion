/**
 * Registers the app-shell service worker (sw.js). Safe no-op in browsers
 * without service worker support.
 */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function (err) {
      console.error('Service worker registration failed:', err);
    });
  });
}
