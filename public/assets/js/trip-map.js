/**
 * Trip map: photo/video pins on a Leaflet/OSM map. Two icon states per pin
 * (small dot vs. thumbnail) swapped on zoom instead of rebuilding markers,
 * so panning/zooming a trip with a few hundred pins stays smooth.
 *
 * A real GPS track (GPX upload or folder-derived points) replaces the naive
 * chronological pin-order line once one exists for the trip. POIs (museums,
 * monuments, ...) render as small colored pins, tinted once marked visited.
 *
 * The photo/video LIGHTBOX overlay (open/prev/next/rotate/rate/delete) is
 * set up unconditionally, independent of whether this page's own big map
 * (#trip-map) exists - review.php's carousel (review-carousel.js) has its
 * own separate #review-map with a fundamentally different "zoom to one
 * candidate at a time" behaviour that doesn't fit this file's "fit
 * everything" model, so it never builds a map here, but it still wants the
 * SAME lightbox for its own photo pins rather than the tiny hover-tooltip
 * preview it used to have. #trip-map or #review-map, whichever exists,
 * supplies the shared canEdit/csrfToken/message dataset values either way.
 */
document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('trip-map');
  var lightboxHost = container || document.getElementById('review-map');
  if (!lightboxHost || typeof L === 'undefined') {
    return;
  }

  var routeToggle = document.querySelector('[data-map-route-toggle]');
  var photoToggle = document.querySelector('[data-map-photo-toggle]');
  var geocacheToggle = document.querySelector('[data-map-geocache-toggle]');
  var lightbox = document.querySelector('[data-map-lightbox]');
  var lightboxBody = document.querySelector('[data-map-lightbox-body]');
  var lightboxActions = document.querySelector('[data-map-lightbox-actions]');
  var ZOOM_THUMBNAIL_THRESHOLD = 14;
  var canEdit = lightboxHost.dataset.canEdit === '1';
  var csrfToken = lightboxHost.dataset.csrfToken || '';
  var msgLightboxDeleteConfirm = lightboxHost.dataset.msgLightboxDeleteConfirm || '';
  var msgLightboxActionError = lightboxHost.dataset.msgLightboxActionError || '';
  // Keyed "kind:id" (photo ids and video ids share one numeric namespace,
  // not each other's) -> {marker, group}, so a lightbox delete can remove
  // the matching live marker instead of requiring a full page reload to
  // see it disappear from the map. Populated below as this file's own
  // markers are built, and exposed so review-carousel.js's own separately
  // built markers can register into the same map too.
  var lightboxMarkersByKey = {};
  window.registerLightboxMarker = function (key, marker, group) {
    lightboxMarkersByKey[key] = { marker: marker, group: group };
  };

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatTime(isoUtc) {
    // Stored as UTC ('YYYY-MM-DD HH:MM:SS'); displayed in the viewer's local time.
    var d = new Date(isoUtc.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) {
      return '';
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '. ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  var lightboxPrevBtn = document.querySelector('[data-map-lightbox-prev]');
  var lightboxNextBtn = document.querySelector('[data-map-lightbox-next]');
  var lightboxCaption = document.querySelector('[data-map-lightbox-caption]');
  // The set prev/next currently cycles through - trip-map.js always uses
  // every geotagged pin (already chronological, TripMapController::data()),
  // set once the fetch resolves.
  var lightboxPins = [];
  var lightboxIndex = -1;

  function renderLightboxCaption(pin) {
    if (!lightboxCaption) {
      return;
    }
    var lines = ['<p>' + escapeHtml(formatTime(pin.takenAt)) + '</p>'];
    if (pin.address) {
      lines.push('<p>' + escapeHtml(pin.address) + '</p>');
    }
    if (pin.poiName) {
      lines.push('<p>' + escapeHtml(pin.poiName) + '</p>');
    }
    lightboxCaption.innerHTML = lines.join('');
  }

  // Rotate/rate only apply to photos (rotate needs Imagick pixels to work
  // on; a video has none of its own here, and xmp:Rating-style "Fav" stars
  // were only asked for photos) - hidden for a video pin, delete stays
  // available for both since PhotoController/VideoController both support
  // the same inline-delete convention.
  var lightboxStarButtons = lightboxActions
    ? Array.prototype.slice.call(lightboxActions.querySelectorAll('[data-map-lightbox-star]'))
    : [];
  var lightboxRotateButtons = lightboxActions
    ? Array.prototype.slice.call(lightboxActions.querySelectorAll('[data-map-lightbox-rotate]'))
    : [];
  var lightboxCropBtn = lightboxActions ? lightboxActions.querySelector('[data-map-lightbox-crop]') : null;

  function renderLightboxActions(pin) {
    exitCropMode();
    if (!lightboxActions) {
      return;
    }
    var isPhoto = pin.kind === 'photo';
    lightboxRotateButtons.forEach(function (btn) { btn.hidden = !isPhoto; });
    if (lightboxCropBtn) {
      lightboxCropBtn.hidden = !isPhoto;
    }
    var ratingEl = lightboxActions.querySelector('[data-map-lightbox-rating]');
    if (ratingEl) {
      ratingEl.hidden = !isPhoto;
    }
    var rating = isPhoto ? (pin.rating || 0) : 0;
    lightboxStarButtons.forEach(function (btn) {
      var value = parseInt(btn.dataset.mapLightboxStar, 10);
      btn.classList.toggle('is-filled', value <= rating);
    });
  }

  function renderLightboxMedia(pin) {
    if (pin.kind === 'video') {
      lightboxBody.innerHTML = '<video controls autoplay class="map-lightbox__media" src="' + pin.fullUrl + '"></video>';
      return;
    }
    var img = document.createElement('img');
    img.className = 'map-lightbox__media';
    img.alt = '';
    img.onerror = function () {
      console.error('Lightbox image failed to load:', pin.fullUrl);
      img.style.display = 'none';
    };
    lightboxBody.innerHTML = '';
    lightboxBody.appendChild(img);
    img.src = pin.fullUrl;
  }

  // Called with (pinSet, index) so any page's own click handler (this
  // file's own pins, day-entry-detail-view.js's diary gallery,
  // review-carousel.js's context pins) can reuse this same overlay/CSS
  // with a different photo set instead of a single fixed list.
  function openLightboxAt(pinSet, index) {
    if (!lightbox || !lightboxBody || pinSet.length === 0) {
      return;
    }
    lightboxPins = pinSet;
    lightboxIndex = Math.max(0, Math.min(index, pinSet.length - 1));
    var pin = lightboxPins[lightboxIndex];
    renderLightboxMedia(pin);
    renderLightboxCaption(pin);
    renderLightboxActions(pin);
    if (lightboxPrevBtn) {
      lightboxPrevBtn.disabled = lightboxIndex === 0;
    }
    if (lightboxNextBtn) {
      lightboxNextBtn.disabled = lightboxIndex === lightboxPins.length - 1;
    }
    lightbox.hidden = false;
  }

  function stepLightbox(delta) {
    if (lightboxIndex < 0) {
      return;
    }
    var next = lightboxIndex + delta;
    if (next < 0 || next >= lightboxPins.length) {
      return;
    }
    openLightboxAt(lightboxPins, next);
  }

  if (lightboxPrevBtn) {
    lightboxPrevBtn.addEventListener('click', function () { stepLightbox(-1); });
  }
  if (lightboxNextBtn) {
    lightboxNextBtn.addEventListener('click', function () { stepLightbox(1); });
  }
  // Exposed so any other script on the page can open the same overlay for
  // its own photo set, without this file needing to know anything about
  // that other context.
  window.openTripPhotoLightbox = openLightboxAt;

  function closeLightbox() {
    if (!lightbox || !lightboxBody) {
      return;
    }
    stopSlideshow();
    exitCropMode();
    lightbox.hidden = true;
    lightboxBody.innerHTML = '';
    lightboxIndex = -1;
  }

  document.querySelectorAll('[data-map-lightbox-close]').forEach(function (el) {
    el.addEventListener('click', closeLightbox);
  });

  // Every visible copy of a photo (gallery thumbnail, map marker, the
  // lightbox's own <img>) is just an <img src="/photos/ID/..."> pointing at
  // the same stable URL - rotate/caption-regenerate change the bytes behind
  // that URL without changing it, so a plain cache-bust sweep refreshes
  // every surface at once without each one needing its own update path.
  function cacheBustPhoto(photoId) {
    document.querySelectorAll(
      'img[src*="/photos/' + photoId + '/thumb"], img[src*="/photos/' + photoId + '/web"]'
    ).forEach(function (img) {
      img.src = img.src.split('?')[0] + '?t=' + Date.now();
    });
  }

  // cacheBustPhoto() alone only fixes whatever's in the DOM *right now* -
  // stepping away and back (prev/next, or the slideshow) re-renders the
  // lightbox <img> from pin.fullUrl again, and without this the browser's
  // own HTTP cache (PhotoController::show() sends max-age=86400) would
  // happily serve the pre-edit bytes right back, making the edit look like
  // it silently reverted (Stefan hit this: rotate, step away and back,
  // rotate again from what looked like the original -> actually a second
  // rotation on top of the first, ending up upside down). Stamping the
  // pin object's own URLs means every future render of THIS pin, from
  // anywhere, is forced to re-fetch.
  function bustPinUrls(pin) {
    var bust = '?t=' + Date.now();
    pin.fullUrl = pin.fullUrl.split('?')[0] + bust;
    if (pin.thumbUrl) {
      pin.thumbUrl = pin.thumbUrl.split('?')[0] + bust;
    }
  }

  function currentLightboxPhotoBody() {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    return body;
  }

  if (lightboxActions) {
    lightboxRotateButtons.forEach(function (btn) {
      btn.addEventListener('click', function () { rotateCurrentLightboxPhoto(btn.dataset.mapLightboxRotate); });
    });
    lightboxStarButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        rateCurrentLightboxPhoto(parseInt(btn.dataset.mapLightboxStar, 10));
      });
    });
    var deleteBtn = lightboxActions.querySelector('[data-map-lightbox-delete]');
    if (deleteBtn) {
      deleteBtn.addEventListener('click', deleteCurrentLightboxPhoto);
    }
  }

  function rotateCurrentLightboxPhoto(direction) {
    if (lightboxIndex < 0 || (direction !== 'l' && direction !== 'r')) {
      return;
    }
    var pin = lightboxPins[lightboxIndex];
    if (!pin || pin.kind !== 'photo') {
      return;
    }
    exitCropMode(); // a crop selection drawn before rotating no longer matches anything afterwards
    var body = currentLightboxPhotoBody();
    body.set('direction', direction);
    fetch('/photos/' + pin.id + '/rotate', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok) {
          throw new Error('rotate failed');
        }
        // Updates the still-open lightbox <img> (its src already contains
        // "/photos/ID/web", matched by the same substring selector) plus
        // every other visible copy of this photo on the page - no need to
        // rebuild the lightbox body itself.
        cacheBustPhoto(pin.id);
        bustPinUrls(pin);
      })
      .catch(function () { window.alert(msgLightboxActionError); });
  }

  function rateCurrentLightboxPhoto(rating) {
    if (lightboxIndex < 0) {
      return;
    }
    var pin = lightboxPins[lightboxIndex];
    if (!pin || pin.kind !== 'photo') {
      return;
    }
    // Clicking the already-top-rated star again clears the rating, same as
    // most star-rating widgets - lets you unfavorite without a separate control.
    var newRating = pin.rating === rating ? 0 : rating;
    var body = currentLightboxPhotoBody();
    body.set('rating', String(newRating));
    fetch('/photos/' + pin.id + '/rate', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok) {
          throw new Error('rate failed');
        }
        pin.rating = newRating;
        renderLightboxActions(pin);
      })
      .catch(function () { window.alert(msgLightboxActionError); });
  }

  function deleteCurrentLightboxPhoto() {
    if (lightboxIndex < 0) {
      return;
    }
    var pin = lightboxPins[lightboxIndex];
    if (!pin || !window.confirm(msgLightboxDeleteConfirm)) {
      return;
    }
    var url = pin.kind === 'video' ? '/videos/' + pin.id + '/delete' : '/photos/' + pin.id + '/delete';
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Inline-Delete': '1' },
      body: currentLightboxPhotoBody(),
    })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        var key = pin.kind + ':' + pin.id;
        var entry = lightboxMarkersByKey[key];
        if (entry) {
          entry.group.removeLayer(entry.marker);
          delete lightboxMarkersByKey[key];
        }
        // lightboxPins IS the same array the caller (this file's own pins,
        // or day-entry-detail-view.js's/review-carousel.js's parsed set) is
        // working with - splicing in place rather than filtering into a
        // new array lets that caller's own copy of the list shrink too,
        // not just this module's view of it.
        var removedIndex = lightboxIndex;
        lightboxPins.splice(removedIndex, 1);
        window.dispatchEvent(new CustomEvent('trip-photo-deleted', { detail: { kind: pin.kind, id: pin.id } }));
        if (lightboxPins.length === 0) {
          closeLightbox();
        } else {
          openLightboxAt(lightboxPins, Math.min(removedIndex, lightboxPins.length - 1));
        }
      })
      .catch(function () { window.alert(msgLightboxActionError); });
  }

  // --- Crop (Stefan's ask): drag a rectangle over the currently displayed
  // photo, then apply/cancel. The <img> is sized via max-width/max-height
  // with object-fit: contain, so for a photo whose aspect ratio doesn't
  // match the available box there's letterboxing INSIDE the element's own
  // box - getRenderedImageRect() finds the actual visible picture area so
  // dragging near an edge doesn't select empty padding. Sends fractions
  // (0-1) of that area rather than pixels, since the server only knows the
  // stored derivatives' own pixel sizes, not this rendered box's.
  var lightboxCropApplyBtn = lightboxActions ? lightboxActions.querySelector('[data-map-lightbox-crop-apply]') : null;
  var lightboxCropCancelBtn = lightboxActions ? lightboxActions.querySelector('[data-map-lightbox-crop-cancel]') : null;
  var cropActive = false;
  var cropRectFrac = null;
  var cropDragStart = null;
  var cropBoxEl = null;

  function getRenderedImageRect(img) {
    var box = img.getBoundingClientRect();
    if (!img.naturalWidth || !img.naturalHeight) {
      return box;
    }
    var boxRatio = box.width / box.height;
    var imgRatio = img.naturalWidth / img.naturalHeight;
    var renderWidth, renderHeight, offsetX, offsetY;
    if (imgRatio > boxRatio) {
      renderWidth = box.width;
      renderHeight = box.width / imgRatio;
      offsetX = 0;
      offsetY = (box.height - renderHeight) / 2;
    } else {
      renderHeight = box.height;
      renderWidth = box.height * imgRatio;
      offsetY = 0;
      offsetX = (box.width - renderWidth) / 2;
    }
    return { left: box.left + offsetX, top: box.top + offsetY, width: renderWidth, height: renderHeight };
  }

  function ensureCropBoxEl() {
    if (!cropBoxEl) {
      cropBoxEl = document.createElement('div');
      cropBoxEl.className = 'map-lightbox__crop-box';
      document.body.appendChild(cropBoxEl);
    }
    return cropBoxEl;
  }

  function updateCropButtons() {
    if (lightboxCropApplyBtn) {
      lightboxCropApplyBtn.hidden = !cropActive || !cropRectFrac;
    }
    if (lightboxCropCancelBtn) {
      lightboxCropCancelBtn.hidden = !cropActive;
    }
    if (lightboxCropBtn) {
      lightboxCropBtn.classList.toggle('is-active', cropActive);
    }
  }

  function onCropPointerMove(e) {
    if (!cropDragStart) {
      return;
    }
    var box = ensureCropBoxEl();
    var left = Math.min(cropDragStart.x, e.clientX);
    var top = Math.min(cropDragStart.y, e.clientY);
    var width = Math.abs(e.clientX - cropDragStart.x);
    var height = Math.abs(e.clientY - cropDragStart.y);
    box.style.left = left + 'px';
    box.style.top = top + 'px';
    box.style.width = width + 'px';
    box.style.height = height + 'px';
  }

  function onCropPointerUp() {
    document.removeEventListener('mousemove', onCropPointerMove);
    document.removeEventListener('mouseup', onCropPointerUp);
    if (!cropDragStart) {
      return;
    }
    var rect = cropDragStart.imageRect;
    var box = ensureCropBoxEl();
    var boxRect = box.getBoundingClientRect();
    cropDragStart = null;

    var left = Math.max(boxRect.left, rect.left);
    var top = Math.max(boxRect.top, rect.top);
    var right = Math.min(boxRect.left + boxRect.width, rect.left + rect.width);
    var bottom = Math.min(boxRect.top + boxRect.height, rect.top + rect.height);
    var width = right - left;
    var height = bottom - top;
    // A tiny/negative box is just a stray click, not a deliberate drag.
    if (width < rect.width * 0.03 || height < rect.height * 0.03) {
      box.classList.remove('is-active');
      cropRectFrac = null;
      updateCropButtons();
      return;
    }

    cropRectFrac = {
      x: (left - rect.left) / rect.width,
      y: (top - rect.top) / rect.height,
      width: width / rect.width,
      height: height / rect.height,
    };
    box.style.left = left + 'px';
    box.style.top = top + 'px';
    box.style.width = width + 'px';
    box.style.height = height + 'px';
    updateCropButtons();
  }

  function onCropPointerDown(e) {
    if (!cropActive) {
      return;
    }
    var img = lightboxBody.querySelector('img');
    if (!img) {
      return;
    }
    cropDragStart = { x: e.clientX, y: e.clientY, imageRect: getRenderedImageRect(img) };
    var box = ensureCropBoxEl();
    box.classList.add('is-active');
    box.style.left = e.clientX + 'px';
    box.style.top = e.clientY + 'px';
    box.style.width = '0px';
    box.style.height = '0px';
    cropRectFrac = null;
    updateCropButtons();
    document.addEventListener('mousemove', onCropPointerMove);
    document.addEventListener('mouseup', onCropPointerUp);
    e.preventDefault();
  }

  function exitCropMode() {
    cropActive = false;
    cropRectFrac = null;
    cropDragStart = null;
    if (cropBoxEl) {
      cropBoxEl.classList.remove('is-active');
    }
    var img = lightboxBody ? lightboxBody.querySelector('img') : null;
    if (img) {
      img.removeEventListener('mousedown', onCropPointerDown);
    }
    updateCropButtons();
  }

  function enterCropMode() {
    if (lightboxIndex < 0) {
      return;
    }
    var pin = lightboxPins[lightboxIndex];
    if (!pin || pin.kind !== 'photo') {
      return;
    }
    cropActive = true;
    cropRectFrac = null;
    updateCropButtons();
    var img = lightboxBody.querySelector('img');
    if (img) {
      img.addEventListener('mousedown', onCropPointerDown);
    }
  }

  function applyCrop() {
    if (lightboxIndex < 0 || !cropRectFrac) {
      return;
    }
    var pin = lightboxPins[lightboxIndex];
    if (!pin) {
      return;
    }
    var body = currentLightboxPhotoBody();
    body.set('x', String(cropRectFrac.x));
    body.set('y', String(cropRectFrac.y));
    body.set('width', String(cropRectFrac.width));
    body.set('height', String(cropRectFrac.height));
    fetch('/photos/' + pin.id + '/crop', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok) {
          throw new Error('crop failed');
        }
        exitCropMode();
        cacheBustPhoto(pin.id);
        bustPinUrls(pin);
      })
      .catch(function () { window.alert(msgLightboxActionError); });
  }

  if (lightboxCropBtn) {
    lightboxCropBtn.addEventListener('click', function () {
      if (cropActive) {
        exitCropMode();
      } else {
        enterCropMode();
      }
    });
  }
  if (lightboxCropCancelBtn) {
    lightboxCropCancelBtn.addEventListener('click', exitCropMode);
  }
  if (lightboxCropApplyBtn) {
    lightboxCropApplyBtn.addEventListener('click', applyCrop);
  }

  // --- Play/slideshow (Stefan's ask): step forward automatically every N
  // seconds, starting from whichever photo is already open. Stops itself
  // at the end of the current set (mirrors stepLightbox()'s own bounds
  // check) rather than looping - closing the lightbox stops it too
  // (closeLightbox() above).
  var lightboxPlayBtn = lightboxActions ? lightboxActions.querySelector('[data-map-lightbox-play]') : null;
  var lightboxPlaySecondsInput = lightboxActions ? lightboxActions.querySelector('[data-map-lightbox-play-seconds]') : null;
  var slideshowTimer = null;

  function stopSlideshow() {
    if (slideshowTimer) {
      clearInterval(slideshowTimer);
      slideshowTimer = null;
    }
    if (lightboxPlayBtn) {
      lightboxPlayBtn.classList.remove('is-active');
      lightboxPlayBtn.innerHTML = '&#9654;';
      lightboxPlayBtn.title = lightboxPlayBtn.dataset.playLabel || lightboxPlayBtn.title;
      lightboxPlayBtn.setAttribute('aria-label', lightboxPlayBtn.dataset.playLabel || lightboxPlayBtn.getAttribute('aria-label'));
    }
  }

  function startSlideshow() {
    if (!lightboxPlayBtn) {
      return;
    }
    var seconds = lightboxPlaySecondsInput ? parseFloat(lightboxPlaySecondsInput.value) : 3;
    if (!seconds || seconds <= 0) {
      seconds = 3;
    }
    lightboxPlayBtn.classList.add('is-active');
    lightboxPlayBtn.innerHTML = '&#9208;';
    lightboxPlayBtn.dataset.playLabel = lightboxPlayBtn.dataset.playLabel || lightboxPlayBtn.title;
    if (lightboxPlayBtn.dataset.pauseLabel) {
      lightboxPlayBtn.title = lightboxPlayBtn.dataset.pauseLabel;
      lightboxPlayBtn.setAttribute('aria-label', lightboxPlayBtn.dataset.pauseLabel);
    }
    slideshowTimer = setInterval(function () {
      if (lightboxIndex < 0 || lightboxIndex >= lightboxPins.length - 1) {
        stopSlideshow();
        return;
      }
      stepLightbox(1);
    }, seconds * 1000);
  }

  if (lightboxPlayBtn) {
    lightboxPlayBtn.addEventListener('click', function () {
      if (slideshowTimer) {
        stopSlideshow();
      } else {
        startSlideshow();
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeLightbox();
      return;
    }
    if (!lightbox || lightbox.hidden || !lightboxActions) {
      return;
    }
    // Don't hijack these common letters while the user is typing anywhere
    // else on the page (e.g. a trip description field).
    var active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) {
      return;
    }
    if (e.key === 'd') {
      deleteCurrentLightboxPhoto();
    } else if (e.key === 'l') {
      rotateCurrentLightboxPhoto('l');
    } else if (e.key === 'r') {
      rotateCurrentLightboxPhoto('r');
    }
  });

  // Everything below builds THIS page's own big "fit everything" map -
  // review.php's carousel builds its own separate #review-map instead (see
  // the file header comment), so none of this runs there; it still gets
  // the lightbox above via window.openTripPhotoLightbox/registerLightboxMarker.
  if (!container) {
    return;
  }

  // Day-accordion filtering (see day-entry-accordion.js): only relevant on
  // the trip page, a no-op everywhere else since no 'day-entry-toggle'
  // event is ever fired without that script. Populated once /map/data
  // resolves; toggles that happen before then just update openEntryDates,
  // applied as soon as the data arrives.
  var openEntryDates = {};
  var mapDataLoaded = false;
  var dayMarkers = [];
  var dayTrackPoints = [];
  var fullRouteGroup = null;
  var dayRouteLine = L.polyline([], { color: '#c56a3c', weight: 4, opacity: 0.9 });
  var fullFitLatLngs = [];

  function localDateOf(isoUtc) {
    var d = new Date(isoUtc.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) {
      return '';
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function applyDayFilter() {
    if (!mapDataLoaded) {
      return;
    }
    var activeDates = Object.keys(openEntryDates).filter(function (d) { return openEntryDates[d]; });

    if (activeDates.length === 0) {
      dayMarkers.forEach(function (m) {
        if (!map.hasLayer(m.marker)) {
          m.marker.addTo(map);
        }
      });
      map.removeLayer(dayRouteLine);
      if (fullRouteGroup && (!routeToggle || routeToggle.checked)) {
        fullRouteGroup.addTo(map);
      }
      if (fullFitLatLngs.length > 0) {
        map.fitBounds(fullFitLatLngs, { padding: [40, 40], maxZoom: 16 });
      }
      return;
    }

    var activeSet = {};
    activeDates.forEach(function (d) { activeSet[d] = true; });

    var boundsLatLngs = [];
    dayMarkers.forEach(function (m) {
      if (activeSet[m.pin.entryDate]) {
        if (!map.hasLayer(m.marker)) {
          m.marker.addTo(map);
        }
        boundsLatLngs.push([m.pin.lat, m.pin.lng]);
      } else if (map.hasLayer(m.marker)) {
        map.removeLayer(m.marker);
      }
    });

    if (fullRouteGroup) {
      map.removeLayer(fullRouteGroup);
    }

    var dayLatLngs = dayTrackPoints
      .filter(function (p) { return p.recordedAt && activeSet[localDateOf(p.recordedAt)]; })
      .map(function (p) { return [p.lat, p.lng]; });
    dayRouteLine.setLatLngs(dayLatLngs);
    if (dayLatLngs.length > 1 && (!routeToggle || routeToggle.checked)) {
      dayRouteLine.addTo(map);
    } else {
      map.removeLayer(dayRouteLine);
    }

    boundsLatLngs = boundsLatLngs.concat(dayLatLngs);
    if (boundsLatLngs.length > 0) {
      map.fitBounds(boundsLatLngs, { padding: [40, 40], maxZoom: 16 });
    }
  }

  window.addEventListener('day-entry-toggle', function (e) {
    openEntryDates[e.detail.date] = e.detail.open;
    applyDayFilter();
  });

  function buildPoiPopup(poi) {
    var wrap = document.createElement('div');
    wrap.className = 'map-poi-popup';

    var title = document.createElement('strong');
    title.textContent = poi.name;
    wrap.appendChild(title);

    var form = document.createElement('form');
    form.method = 'post';
    form.action = '/pois/' + poi.id + '/delete';
    form.addEventListener('submit', function (e) {
      if (!window.confirm(container.dataset.msgPoiDeleteConfirm || '')) {
        e.preventDefault();
      }
    });

    var token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_csrf';
    token.value = container.dataset.csrfToken || '';
    form.appendChild(token);

    var button = document.createElement('button');
    button.type = 'submit';
    button.className = 'btn btn-ghost btn-small';
    button.textContent = container.dataset.msgPoiDelete || '';
    form.appendChild(button);

    wrap.appendChild(form);
    return wrap;
  }

  var map = L.map(container, { zoomControl: true }).setView([20, 0], 2);
  L.control.scale({ imperial: false }).addTo(map);

  // tile.openstreetmap.org's usage policy forbids the traffic pattern a
  // real app produces (no bulk/heavy use) — MapTiler serves the same OSM
  // cartography under a plan that's actually meant for this.
  var tileKey = container.dataset.tileKey;
  if (tileKey) {
    // MapTiler's XYZ endpoint serves 512px tiles by default; without the
    // explicit /256/ path segment Leaflet (default tileSize 256) would place
    // them on the wrong grid, producing gaps/misalignment. /256/ requests
    // 256px tiles that match Leaflet's default grid directly.
    L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, {
      maxZoom: 19,
      crossOrigin: true,
      attribution: '&copy; <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> '
        + '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
    }).addTo(map);
  } else {
    // An empty MAPTILER_KEY is the #1 cause of a blank gray map: the tile
    // layer is never added, so no tile request is made at all. Surface it
    // in the console so config issues are debuggable instead of silent.
    console.warn('Trip map: MAPTILER_KEY is empty — map tiles will not load. Set MAPTILER_KEY in .env on the server.');
  }

  // Marks a best-guess position (PhotoPositionInterpolationService) as such
  // rather than showing it identically to a real GPS fix - Stefan's own
  // "always visibly interpolated" requirement.
  function interpolatedClass(pin) {
    return pin.interpolated ? ' map-view__pin-dot--interpolated' : '';
  }

  function dotIcon(pin) {
    return L.divIcon({
      className: 'map-view__pin-dot' + (pin.kind === 'video' ? ' map-view__pin-dot--video' : '') + interpolatedClass(pin),
      iconSize: [14, 14],
    });
  }

  function thumbIcon(pin) {
    var badge = pin.kind === 'video' ? '<span class="map-view__pin-thumb-badge">&#9654;</span>' : '';
    return L.divIcon({
      className: 'map-view__pin-thumb' + (pin.interpolated ? ' map-view__pin-thumb--interpolated' : ''),
      html: '<img src="' + pin.thumbUrl + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">' + badge,
      iconSize: [44, 44],
    });
  }

  // Manual POI form: "pick on map" arms a one-time click on the map to fill
  // the hidden lat/lng inputs, independent of whether pin/track data has
  // loaded yet.
  var poiPickButton = document.querySelector('[data-poi-pick-on-map]');
  var poiPickStatus = document.querySelector('[data-poi-pick-status]');
  var poiLatInput = document.querySelector('[data-poi-lat-input]');
  var poiLngInput = document.querySelector('[data-poi-lng-input]');
  var poiPickMarker = null;
  if (poiPickButton && poiLatInput && poiLngInput) {
    poiPickButton.addEventListener('click', function () {
      if (poiPickStatus) {
        poiPickStatus.textContent = poiPickButton.dataset.msgPicking || '';
      }
      map.once('click', function (e) {
        poiLatInput.value = e.latlng.lat.toFixed(6);
        poiLngInput.value = e.latlng.lng.toFixed(6);
        if (poiPickStatus) {
          poiPickStatus.textContent = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
        }
        if (poiPickMarker) {
          map.removeLayer(poiPickMarker);
        }
        poiPickMarker = L.marker(e.latlng).addTo(map);
      });
    });
  }

  fetch(container.dataset.dataUrl, { credentials: 'same-origin' })
    .then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function (data) {
      var pins = data.pins || [];
      var track = data.track;
      var trackLatlngs = (track && track.points && track.points.length > 1)
        ? track.points.map(function (p) { return [p.lat, p.lng]; })
        : [];
      // Exposed so day-entry-detail-view.js's per-card minimaps can draw the
      // same track without a duplicate /map/data fetch.
      window.tripTrackLatLngs = trackLatlngs;
      var pois = data.pois || [];

      if (pins.length === 0 && trackLatlngs.length === 0 && pois.length === 0) {
        var empty = document.createElement('p');
        empty.className = 'empty-state';
        empty.textContent = container.dataset.msgEmpty;
        container.replaceWith(empty);
        return;
      }

      var markers = [];
      var pinLatlngs = [];

      // Photo/video pins can get dense enough to bury geocache/sight
      // markers and their labels underneath them - own layer group so
      // they can be hidden with one click (data-map-photo-toggle) instead
      // of hunting for the thing underneath.
      var photoGroup = L.layerGroup();
      if (!photoToggle || photoToggle.checked) {
        photoGroup.addTo(map);
      }
      if (photoToggle) {
        photoToggle.addEventListener('change', function () {
          if (photoToggle.checked) {
            photoGroup.addTo(map);
          } else {
            map.removeLayer(photoGroup);
          }
        });
      }

      pins.forEach(function (pin, pinIndex) {
        var marker = L.marker([pin.lat, pin.lng], { icon: dotIcon(pin) });
        marker.on('click', function () { openLightboxAt(pins, pinIndex); });
        marker.addTo(photoGroup);
        lightboxMarkersByKey[pin.kind + ':' + pin.id] = { marker: marker, group: photoGroup };
        markers.push({ marker: marker, pin: pin, dot: dotIcon(pin), thumb: thumbIcon(pin) });
        pinLatlngs.push([pin.lat, pin.lng]);
      });

      // A real GPS track (GPX or folder-derived) replaces the naive
      // pin-order line once one exists for the trip.
      var routeLatlngs = trackLatlngs.length > 0 ? trackLatlngs : pinLatlngs;
      if (routeLatlngs.length > 1) {
        var routeGroup = L.layerGroup();
        L.polyline(routeLatlngs, { color: '#2f6f5e', weight: 3, opacity: 0.8 }).addTo(routeGroup);

        // Timestamp/pause tooltips only make sense on a real track's
        // vertices (chronological photo pins already show their date on
        // click via the lightbox).
        if (trackLatlngs.length > 0) {
          track.points.forEach(function (p) {
            if (!p.recordedAt) {
              return;
            }
            var label = p.isPause
              ? (container.dataset.msgPause + ' ' + formatTime(p.recordedAt) + '–' + formatTime(p.recordedUntil))
              : formatTime(p.recordedAt);
            var vertex = L.circleMarker([p.lat, p.lng], {
              radius: p.isPause ? 6 : 2,
              color: p.isPause ? '#c56a3c' : '#2f6f5e',
              fillColor: p.isPause ? '#c56a3c' : '#2f6f5e',
              fillOpacity: 0.9,
              weight: 1,
            }).bindTooltip(label, { sticky: true });
            vertex.addTo(routeGroup);
          });
        }

        if (!routeToggle || routeToggle.checked) {
          routeGroup.addTo(map);
        }
        if (routeToggle) {
          routeToggle.addEventListener('change', function () {
            if (routeToggle.checked) {
              routeGroup.addTo(map);
            } else {
              map.removeLayer(routeGroup);
            }
          });
        }
      }

      function applyIconsForZoom() {
        var useThumb = map.getZoom() >= ZOOM_THUMBNAIL_THRESHOLD;
        markers.forEach(function (m) {
          m.marker.setIcon(useThumb ? m.thumb : m.dot);
        });
      }

      var geocacheGroup = L.layerGroup();
      if (!geocacheToggle || geocacheToggle.checked) {
        geocacheGroup.addTo(map);
      }
      if (geocacheToggle) {
        geocacheToggle.addEventListener('change', function () {
          if (geocacheToggle.checked) {
            geocacheGroup.addTo(map);
          } else {
            map.removeLayer(geocacheGroup);
          }
        });
      }

      var poiLatlngs = [];
      pois.forEach(function (poi) {
        // Geocaches (see PoiController::importGpx()) get their real
        // cache_type SVG icon instead of the generic coloured dot, and go
        // in their own toggleable layer (data-map-geocache-toggle) -
        // regular sights stay always-on, only geocaches tend to cluster
        // densely enough to be worth hiding.
        var icon = poi.cacheIconUrl
          ? L.divIcon({
              className: 'map-view__poi-pin map-view__poi-pin--cache' + (poi.visited ? ' map-view__poi-pin--visited' : ''),
              html: '<img src="' + poi.cacheIconUrl + '" alt="" width="44" height="44">',
              iconSize: [44, 44],
            })
          : L.divIcon({
              className: 'map-view__poi-pin' + (poi.visited ? ' map-view__poi-pin--visited' : ''),
              iconSize: [16, 16],
            });
        var marker = L.marker([poi.lat, poi.lng], { icon: icon })
          .bindTooltip(poi.name, { sticky: true })
          .addTo(poi.cacheIconUrl ? geocacheGroup : map);
        if (canEdit) {
          marker.bindPopup(buildPoiPopup(poi));
        }
        poiLatlngs.push([poi.lat, poi.lng]);
      });

      map.on('zoomend', applyIconsForZoom);
      fullFitLatLngs = pinLatlngs.concat(trackLatlngs).concat(poiLatlngs);
      map.fitBounds(fullFitLatLngs, { padding: [40, 40], maxZoom: 16 });
      applyIconsForZoom();

      dayMarkers = markers;
      dayTrackPoints = (track && track.points) || [];
      fullRouteGroup = routeGroup || null;
      mapDataLoaded = true;
      applyDayFilter();
    })
    .catch(function (err) {
      console.error('Trip map data fetch failed:', err);
      var empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = container.dataset.msgEmpty;
      container.replaceWith(empty);
    });
});
