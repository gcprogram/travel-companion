/**
 * Convenience only: remembers the last-typed GC username in localStorage so
 * it doesn't have to be retyped for every import - never sent anywhere
 * except with the one form submit itself (see PoiController::importGpx()),
 * which uses it only to match against the GPX's own found logs, not stored
 * server-side at all.
 */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-geocaching-gpx-form]');
  var usernameInput = document.querySelector('[data-geocaching-gpx-username]');
  if (!form || !usernameInput || !window.localStorage) {
    return;
  }

  var STORAGE_KEY = 'geocachingGpxUsername';
  var remembered = window.localStorage.getItem(STORAGE_KEY);
  if (remembered) {
    usernameInput.value = remembered;
  }

  form.addEventListener('submit', function () {
    if (usernameInput.value.trim()) {
      window.localStorage.setItem(STORAGE_KEY, usernameInput.value.trim());
    }
  });
});
