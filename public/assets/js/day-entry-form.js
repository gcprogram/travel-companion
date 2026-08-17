/**
 * Location capture for the diary entry form via the browser Geolocation API.
 * Without JS, or without permission, the form still works, just without GPS data.
 * User-facing strings come from data-msg-* attributes set by the (translated) template.
 */
document.addEventListener('DOMContentLoaded', function () {
  var button = document.querySelector('[data-geolocate]');
  var status = document.querySelector('[data-geolocate-status]');
  var latInput = document.getElementById('lat');
  var lngInput = document.getElementById('lng');

  if (!button || !status || !latInput || !lngInput) {
    return;
  }

  button.addEventListener('click', function () {
    if (!('geolocation' in navigator)) {
      status.textContent = button.dataset.msgUnsupported;
      return;
    }

    status.textContent = button.dataset.msgLocating;
    navigator.geolocation.getCurrentPosition(
      function (position) {
        var lat = position.coords.latitude.toFixed(6);
        var lng = position.coords.longitude.toFixed(6);
        latInput.value = lat;
        lngInput.value = lng;
        status.textContent = button.dataset.msgCaptured
          .replace(':lat', lat)
          .replace(':lng', lng);
      },
      function (error) {
        status.textContent = button.dataset.msgError.replace(':error', error.message);
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });
});

/**
 * Local draft autosave: protects against losing typed content to a lost
 * connection, a crashed tab, or accidentally navigating away — independent
 * of whether the eventual save request can reach the server. Deliberately
 * NOT wired into OfflineQueue/auto-sync: the entry form is a plain POST
 * with server-rendered validation, and replicating that flow through fetch
 * would be a lot of added risk for content that's cheap to just re-submit
 * once the user is back and reviews the restored draft themselves.
 */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-entry-draft]');
  var banner = document.querySelector('[data-draft-banner]');
  if (!form || !banner || !window.localStorage) {
    return;
  }

  var key = form.dataset.draftKey;
  var fields = ['entry_date', 'title', 'body', 'mood'];

  function currentValues() {
    var data = {};
    fields.forEach(function (name) {
      var checked = form.querySelector('[name="' + name + '"]:checked');
      if (checked) {
        data[name] = checked.value;
        return;
      }
      var el = form.elements.namedItem(name);
      if (el && typeof el.value === 'string') {
        data[name] = el.value;
      }
    });
    return data;
  }

  function applyValues(data) {
    Object.keys(data).forEach(function (name) {
      var radio = form.querySelector('[name="' + name + '"][value="' + CSS.escape(data[name]) + '"]');
      if (radio && (radio.type === 'radio' || radio.type === 'checkbox')) {
        radio.checked = true;
        return;
      }
      var el = form.elements.namedItem(name);
      if (el && typeof el.value === 'string') {
        el.value = data[name];
      }
    });
  }

  function save() {
    try {
      localStorage.setItem(key, JSON.stringify(currentValues()));
    } catch (e) {
      // Storage full/unavailable (e.g. private browsing) — autosave just doesn't happen.
    }
  }

  function clearDraft() {
    localStorage.removeItem(key);
  }

  var stored = null;
  try {
    var raw = localStorage.getItem(key);
    stored = raw ? JSON.parse(raw) : null;
  } catch (e) {
    stored = null;
  }

  if (stored && JSON.stringify(stored) !== JSON.stringify(currentValues())) {
    banner.hidden = false;
    var restoreBtn = banner.querySelector('[data-draft-restore]');
    var discardBtn = banner.querySelector('[data-draft-discard]');
    if (restoreBtn) {
      restoreBtn.addEventListener('click', function () {
        applyValues(stored);
        banner.hidden = true;
      });
    }
    if (discardBtn) {
      discardBtn.addEventListener('click', function () {
        clearDraft();
        banner.hidden = true;
      });
    }
  }

  var saveTimer = null;
  form.addEventListener('input', function () {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 500);
  });
  form.addEventListener('change', save);
  form.addEventListener('submit', clearDraft);
});

/**
 * Photo/video thumbnail generation runs as an async background job (a few
 * seconds, sometimes longer under load) - the page reload right after
 * upload finishing (photo-upload.js/video-upload.js) often lands before
 * that job has actually run, so it still shows "processing" placeholders
 * with no further sign of life. Poll a tiny status endpoint and reload once
 * nothing is left pending, so finished thumbnails show up without the user
 * having to navigate away and back manually.
 *
 * Deliberately NOT a blind location.reload() every few seconds while
 * anything is pending (what this used to do): on a gallery with two dozen
 * photos, every one of those reloads re-fetches two dozen image URLs - the
 * exact "many requests to many different URLs" pattern that got Bitpalast's
 * abuse detection to block the app's own IP. One small JSON poll costs one
 * request instead, and only reloads the page once, when it's actually done.
 * Backs off and gives up after a while rather than polling forever, in case
 * a job is stuck.
 */
document.addEventListener('DOMContentLoaded', function () {
  var gallery = document.querySelector('[data-photo-gallery], [data-video-gallery]');
  var hasPending = document.querySelector('.photo-gallery__placeholder:not(.photo-gallery__placeholder--failed)');
  if (!gallery || !hasPending) {
    return;
  }
  var entryId = gallery.dataset.entryId;
  var delay = 4000;
  var elapsed = 0;
  var maxElapsed = 120000; // give up after 2 minutes - a stuck job isn't fixed by more polling.

  function poll() {
    fetch('/entries/' + entryId + '/media-status', { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : { pending: false }; })
      .then(function (data) {
        if (!data.pending) {
          window.location.reload();
          return;
        }
        elapsed += delay;
        if (elapsed >= maxElapsed) {
          return;
        }
        delay = Math.min(delay * 1.5, 15000);
        setTimeout(poll, delay);
      })
      .catch(function () {
        // Network hiccup - not worth escalating, just try again next tick.
        elapsed += delay;
        if (elapsed < maxElapsed) {
          setTimeout(poll, delay);
        }
      });
  }

  setTimeout(poll, delay);
});

/**
 * "Übernehmen" on the AI-generated summary just copies its text into the
 * body textarea client-side - never a server-side overwrite, so a real
 * entry the user already wrote is never silently replaced without them
 * seeing and confirming it first (they still have to click Speichern).
 */
document.addEventListener('DOMContentLoaded', function () {
  var button = document.querySelector('[data-ai-summary-apply]');
  var text = document.querySelector('[data-ai-summary-text]');
  if (!button || !text) {
    return;
  }

  button.addEventListener('click', function () {
    var targetId = button.dataset.target;
    var target = targetId && document.getElementById(targetId);
    if (!target) {
      return;
    }
    target.value = text.textContent.trim();
  });
});
