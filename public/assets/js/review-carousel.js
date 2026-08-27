/**
 * "Besuchte Orte prüfen": a map-zoom carousel over review candidates
 * (TripMapController::review) - detected stays AND undiscovered Overpass
 * sights in one unified list (kind: 'stay'|'sight'), so a user reviews
 * everything still needing confirmation in one pass instead of jumping
 * between /review (stays only, formerly) and /pois (sights, bulk-only).
 * One candidate at a time, map fit to a ~2km box centred on it (track/
 * sights/geocaches shown for context, from the same /map/data endpoint the
 * real map page uses), with a bottom bar to accept (keep) or reject.
 *
 * A stay's name is editable (nothing else knows its name yet - see
 * PoiController::addStay); a sight already has a real OSM name, shown
 * read-only. Accept/reject hit different existing endpoints per kind:
 * - stay: PoiController::addStay / dismissStay (unchanged from before).
 * - sight: PoiController::toggleVisited (X-Requested-With: sight-review,
 *   the "confirm" this candidate is real) / PoiController::delete
 *   (X-Inline-Delete: 1, "reject" - same convention confirm-remember.js's
 *   inline delete already uses elsewhere).
 */
document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('review-map');
  var bar = document.querySelector('[data-review-bar]');
  if (!container || !bar || !window.L) {
    return;
  }

  var candidates = [];
  try {
    candidates = JSON.parse(container.dataset.candidates || '[]');
  } catch (e) {
    candidates = [];
  }
  if (candidates.length === 0) {
    return;
  }

  var kindSpan = document.querySelector('[data-review-kind]');
  var nameInput = document.querySelector('[data-review-name]');
  var timeSpan = document.querySelector('[data-review-time]');
  var prevBtn = document.querySelector('[data-review-prev]');
  var nextBtn = document.querySelector('[data-review-next]');
  var acceptBtn = document.querySelector('[data-review-accept]');
  var rejectBtn = document.querySelector('[data-review-reject]');
  var photosList = document.querySelector('[data-review-photos]');

  var csrfToken = container.dataset.csrfToken;
  var acceptUrl = container.dataset.acceptUrl;
  var dismissUrl = container.dataset.dismissUrl;
  var sightVisitedUrlBase = container.dataset.sightVisitedUrlBase;
  var sightDeleteUrlBase = container.dataset.sightDeleteUrlBase;
  var fallbackName = container.dataset.fallbackName || '';
  var kindLabels = { stay: container.dataset.kindStay || '', sight: container.dataset.kindSight || '' };

  var routeToggle = document.querySelector('[data-map-route-toggle]');
  var photoToggle = document.querySelector('[data-map-photo-toggle]');
  var geocacheToggle = document.querySelector('[data-map-geocache-toggle]');

  var index = 0;
  var candidateMarker = null;
  // Every photo pin from the context layer (kind 'photo', chronological,
  // same shape TripMapController::data() gives trip-map.js's own map) -
  // kept around so both the map pins AND the stay's own "nearby photos"
  // strip below the map can open the SAME lightbox (window.
  // openTripPhotoLightbox, set up by trip-map.js - loaded on this page
  // now purely for that shared overlay, see its own file header comment)
  // instead of the tiny hover-tooltip/new-tab-link this page used to have.
  var contextPhotoPins = [];

  var map = L.map(container, { zoomControl: true });
  var tileKey = container.dataset.tileKey;
  if (tileKey) {
    L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, {
      maxZoom: 19,
      crossOrigin: true,
      attribution: '&copy; <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> '
        + '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
    }).addTo(map);
  }

  // Context layers (track + photo pins + sights/geocaches) load once,
  // independent of which candidate is currently shown - only the map's
  // viewport and the highlighted candidate marker change between prev/next.
  // Same three toggleable layer groups as the main map (trip-map.js) - this
  // page never shared that script (its own zoom-to-one-candidate behaviour
  // doesn't fit trip-map.js's fit-everything model), so it re-implements
  // just the toggle wiring here rather than pulling in the whole file.
  fetch(container.dataset.mapDataUrl)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var track = data.track;
      if (track && track.points && track.points.length > 1) {
        var latlngs = track.points.map(function (p) { return [p.lat, p.lng]; });
        var routeLine = L.polyline(latlngs, { color: '#2f6f5e', weight: 3, opacity: 0.8, interactive: false });
        if (!routeToggle || routeToggle.checked) {
          routeLine.addTo(map);
        }
        if (routeToggle) {
          routeToggle.addEventListener('change', function () {
            if (routeToggle.checked) {
              routeLine.addTo(map);
            } else {
              map.removeLayer(routeLine);
            }
          });
        }
      }

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
      contextPhotoPins = data.pins || [];
      contextPhotoPins.forEach(function (pin, pinIndex) {
        var marker = L.marker([pin.lat, pin.lng], {
          icon: L.divIcon({
            className: 'map-view__pin-dot'
              + (pin.kind === 'video' ? ' map-view__pin-dot--video' : '')
              + (pin.interpolated ? ' map-view__pin-dot--interpolated' : ''),
            iconSize: [14, 14],
          }),
        });
        // Same full lightbox as the main map (trip-map.js) instead of a
        // tiny hover tooltip - Stefan's ask, and there's no good reason
        // for these pins to behave differently just because this page
        // never shared the "fit everything" map itself (see file header).
        marker.on('click', function () { window.openTripPhotoLightbox(contextPhotoPins, pinIndex); });
        marker.addTo(photoGroup);
        if (window.registerLightboxMarker) {
          window.registerLightboxMarker(pin.kind + ':' + pin.id, marker, photoGroup);
        }
      });

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
      (data.pois || []).forEach(function (poi) {
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
        var marker = L.marker([poi.lat, poi.lng], { icon: icon }).bindTooltip(poi.name, { sticky: true });
        marker.addTo(poi.cacheIconUrl ? geocacheGroup : map);
      });
    })
    .catch(function () { /* context layers are a nice-to-have - the candidate itself still works without them */ });

  function formatTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) {
      return '';
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
      + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function render() {
    var candidate = candidates[index];
    kindSpan.textContent = kindLabels[candidate.kind] || '';

    if (candidate.kind === 'sight') {
      nameInput.value = candidate.name || '';
      nameInput.readOnly = true;
      timeSpan.textContent = candidate.categoryLabel || '';
    } else {
      nameInput.value = candidate.name || '';
      nameInput.placeholder = fallbackName;
      nameInput.readOnly = false;
      var minutes = Math.round(candidate.durationSeconds / 60);
      timeSpan.textContent = formatTime(candidate.startedAt) + ' – ' + formatTime(candidate.endedAt) + ' (' + minutes + ' min)';
    }

    // A stay's resolved name is often unhelpful (garbled script, a bare
    // street address) - the photos actually taken during that stay
    // (TripMapController::review()'s photoIds) are usually the fastest way
    // to recognise the place, faster than zooming into the map.
    if (photosList) {
      var photoIds = candidate.kind === 'stay' ? (candidate.photoIds || []) : [];
      if (photoIds.length > 0) {
        // Same lightbox as the map pins/main map (Stefan's ask for
        // consistency) instead of a plain new-tab link - the photo's full
        // pin data (for prev/next, caption, rotate/rate) already lives in
        // contextPhotoPins from the context-layer fetch above.
        photosList.innerHTML = photoIds.map(function (id) {
          return '<li class="review-photos__item">'
            + '<button type="button" data-review-photo="' + id + '">'
            + '<img src="/photos/' + id + '/thumb" alt="" loading="lazy"></button></li>';
        }).join('');
        photosList.hidden = false;
      } else {
        photosList.innerHTML = '';
        photosList.hidden = true;
      }
    }

    if (candidateMarker) {
      map.removeLayer(candidateMarker);
    }
    candidateMarker = L.circleMarker([candidate.lat, candidate.lng], {
      radius: 10,
      color: '#c56a3c',
      fillColor: '#c56a3c',
      fillOpacity: 0.9,
      weight: 2,
    }).addTo(map);

    // ~2km north-south extent, centred on the candidate, regardless of
    // latitude (a fixed zoom level wouldn't give a consistent real-world
    // size at different latitudes).
    map.fitBounds(L.latLng(candidate.lat, candidate.lng).toBounds(2000));

    prevBtn.disabled = index === 0;
    nextBtn.disabled = index === candidates.length - 1;
  }

  function advanceAfterResolve() {
    candidates.splice(index, 1);
    if (candidates.length === 0) {
      container.innerHTML = '';
      bar.innerHTML = '<p class="field-hint">' + (container.dataset.msgDone || '') + '</p>';
      return;
    }
    if (index >= candidates.length) {
      index = candidates.length - 1;
    }
    render();
  }

  function setBusy(busy) {
    [prevBtn, nextBtn, acceptBtn, rejectBtn, nameInput].forEach(function (el) { el.disabled = busy; });
  }

  prevBtn.addEventListener('click', function () {
    if (index > 0) {
      index--;
      render();
    }
  });
  nextBtn.addEventListener('click', function () {
    if (index < candidates.length - 1) {
      index++;
      render();
    }
  });

  if (photosList) {
    photosList.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-review-photo]');
      if (!trigger || typeof window.openTripPhotoLightbox !== 'function') {
        return;
      }
      var photoId = parseInt(trigger.dataset.reviewPhoto, 10);
      var photoIndex = contextPhotoPins.findIndex(function (p) { return p.kind === 'photo' && p.id === photoId; });
      if (photoIndex !== -1) {
        window.openTripPhotoLightbox(contextPhotoPins, photoIndex);
      }
    });
  }

  function acceptStay(stay) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('lat', String(stay.lat));
    body.set('lng', String(stay.lng));
    body.set('name', nameInput.value.trim());
    body.set('started_at', stay.startedAt);
    body.set('ended_at', stay.endedAt);
    return fetch(acceptUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'stay-review' },
      body: body.toString(),
    });
  }

  function rejectStay(stay) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('lat', String(stay.lat));
    body.set('lng', String(stay.lng));
    return fetch(dismissUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
  }

  function acceptSight(sight) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    return fetch(sightVisitedUrlBase + sight.id + '/visited', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'sight-review' },
      body: body.toString(),
    });
  }

  function rejectSight(sight) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    return fetch(sightDeleteUrlBase + sight.id + '/delete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Inline-Delete': '1' },
      body: body.toString(),
    });
  }

  // A review pass over many candidates (checking each on the map, maybe
  // renaming a stay) can easily outlast the server-side session lifetime -
  // the page still has the CSRF token it was rendered with, but the
  // session it belonged to is gone. CsrfMiddleware then answers with a 419
  // HTML "bounce" page instead of the JSON these handlers expect, which
  // used to just look like an opaque, unexplained failure (Stefan's
  // report: "es ist etwas schiefgelaufen", nothing in the server log
  // either - CsrfMiddleware/AppErrorHandler don't log this case). Anything
  // already accepted/rejected before this point already succeeded for
  // real; only the current candidate needs redoing after the reload.
  function handleFailedResponse(response) {
    if (response.status === 419) {
      timeSpan.textContent = container.dataset.msgSessionExpired || '';
      setTimeout(function () { window.location.reload(); }, 2500);
      return;
    }
    timeSpan.textContent = container.dataset.msgError || '';
  }

  acceptBtn.addEventListener('click', function () {
    var candidate = candidates[index];
    setBusy(true);
    var request = candidate.kind === 'sight' ? acceptSight(candidate) : acceptStay(candidate);
    request.then(function (r) {
      if (!r.ok) {
        handleFailedResponse(r);
        setBusy(false);
        return;
      }
      setBusy(false);
      advanceAfterResolve();
    }).catch(function () {
      setBusy(false);
      timeSpan.textContent = container.dataset.msgError || '';
    });
  });

  rejectBtn.addEventListener('click', function () {
    var candidate = candidates[index];
    setBusy(true);
    var request = candidate.kind === 'sight' ? rejectSight(candidate) : rejectStay(candidate);
    request.then(function (r) {
      if (!r.ok) {
        handleFailedResponse(r);
        setBusy(false);
        return;
      }
      setBusy(false);
      advanceAfterResolve();
    }).catch(function () {
      setBusy(false);
      timeSpan.textContent = container.dataset.msgError || '';
    });
  });

  render();
});
