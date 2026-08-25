/**
 * "Detailed" diary view (trip.show.detail_view_toggle): shows each photo's/
 * video's caption (vision-AI generated, or the AI MediaAnalyzer EXIF import
 * if no vision run has happened yet - both just live in the same `caption`
 * column, see migration 0035/PhotoRepository::updateVisionCaption()) plus a
 * button to (re)generate it via the configured Vision-AI provider.
 *
 * The toggle only flips a class on .day-entry-list (data-day-entry-list) -
 * never replaced, unlike individual .day-entry-card__body panels which load
 * lazily via innerHTML (day-entry-accordion.js) - so CSS alone makes newly
 * loaded panels respect the current state, no re-binding needed. State
 * persists in localStorage so it survives a reload.
 *
 * The "generate" button is a delegated listener on the same stable list
 * element for the same reason - it has to work on captions inside panels
 * that don't exist in the DOM yet when this script runs.
 */
document.addEventListener('DOMContentLoaded', function () {
  var list = document.querySelector('[data-day-entry-list]');
  var toggle = document.querySelector('[data-day-entry-detail-toggle]');
  if (!list || !toggle) {
    return;
  }

  var STORAGE_KEY = 'dayEntryDetailView';

  function applyState(detailed) {
    list.classList.toggle('day-entry-list--detail', detailed);
    toggle.checked = detailed;
  }

  applyState(window.localStorage && window.localStorage.getItem(STORAGE_KEY) === '1');

  toggle.addEventListener('change', function () {
    applyState(toggle.checked);
    if (window.localStorage) {
      window.localStorage.setItem(STORAGE_KEY, toggle.checked ? '1' : '0');
    }
  });

  var csrfToken = list.dataset.csrfToken;

  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-media-caption-generate]');
    if (!button) {
      return;
    }

    var wrap = button.closest('[data-media-caption]');
    var textEl = wrap ? wrap.querySelector('[data-media-caption-text]') : null;
    if (!textEl) {
      return;
    }

    var previousText = textEl.textContent;
    button.disabled = true;
    textEl.textContent = list.dataset.msgGenerating || '';

    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);

    fetch(button.dataset.captionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          textEl.textContent = data.error || list.dataset.msgCaptionError || '';
          return;
        }
        textEl.textContent = data.caption;
      })
      .catch(function () {
        textEl.textContent = list.dataset.msgCaptionError || previousText;
      })
      .finally(function () {
        button.disabled = false;
      });
  });

  // Photo click -> the shared lightbox overlay trip-map.js already built
  // (prev/next, click-outside-to-close, time/address/sight caption) - reused
  // here with THIS diary entry's own photo set (data-lightbox-photos on the
  // .photo-gallery <ul>, panel.php) instead of the map's trip-wide pins.
  // window.openTripPhotoLightbox only exists on pages that also render
  // trip-map.js (every page with a diary, i.e. the trip's own page) - a
  // no-op click elsewhere would be a silent dead end, so nothing to guard
  // beyond the existence check itself.
  list.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-lightbox-photo]');
    if (!trigger || typeof window.openTripPhotoLightbox !== 'function') {
      return;
    }

    var gallery = trigger.closest('[data-lightbox-photos]');
    if (!gallery) {
      return;
    }

    var photos;
    try {
      photos = JSON.parse(gallery.dataset.lightboxPhotos || '[]');
    } catch (e) {
      return;
    }

    var photoId = parseInt(trigger.dataset.lightboxPhoto, 10);
    var index = photos.findIndex(function (p) { return p.id === photoId; });
    if (index === -1) {
      return;
    }

    window.openTripPhotoLightbox(photos, index);
  });

  // Sight cards' small "area" map (Stefan's ask) - deliberately a real,
  // live Leaflet instance per card rather than a pre-rendered static image:
  // MapTiler's free tier has no static-image endpoint (Stefan's own point),
  // and Bitpalast can't run a headless browser server-side to fake one
  // (exec/proc_open disabled) - the only way to produce a "snippet" image
  // at all would be capturing a canvas in the VISITOR's own browser and
  // uploading it back for caching, real infrastructure that isn't built
  // yet. A live tiny map is simpler and, since cards only exist inside a
  // lazily-loaded, opt-in detail view, cheap in practice: a card's tiles
  // are only ever fetched once it's actually both in the DOM (that day's
  // panel expanded) and visible (detail mode on) - never for a whole trip's
  // worth of sights at once. Fixed to a ~600x600m area via fitBounds()
  // rather than a hardcoded zoom, so it comes out right regardless of the
  // card's actual rendered size.
  var tileKey = list.dataset.tileKey;

  function initMinimap(el) {
    el.dataset.mapInitialized = '1';
    var lat = parseFloat(el.dataset.lat);
    var lng = parseFloat(el.dataset.lng);
    if (isNaN(lat) || isNaN(lng) || typeof window.L === 'undefined' || !tileKey) {
      return;
    }

    var map = L.map(el, {
      zoomControl: false,
      dragging: false,
      scrollWheelZoom: false,
      doubleClickZoom: false,
      boxZoom: false,
      keyboard: false,
      tap: false,
    });
    L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, {
      maxZoom: 19,
      crossOrigin: true,
      attribution: '&copy; <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> '
        + '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
    }).addTo(map);
    L.marker([lat, lng]).addTo(map);

    // ~300m in each direction -> a ~600x600m view; degrees-per-metre varies
    // with latitude for longitude, not for latitude.
    var degLat = 300 / 111320;
    var degLng = 300 / (111320 * Math.cos(lat * Math.PI / 180));
    map.fitBounds([[lat - degLat, lng - degLng], [lat + degLat, lng + degLng]]);
  }

  function initPendingMinimaps() {
    if (!list.classList.contains('day-entry-list--detail')) {
      return;
    }
    list.querySelectorAll('[data-poi-minimap]:not([data-map-initialized])').forEach(initMinimap);
  }

  toggle.addEventListener('change', initPendingMinimaps);
  // Panels load lazily via innerHTML (day-entry-accordion.js, independent
  // of this file) - a mutation observer picks up newly inserted minimap
  // placeholders without this file needing to coordinate with that one.
  new MutationObserver(initPendingMinimaps).observe(list, { childList: true, subtree: true });
  initPendingMinimaps();
});
