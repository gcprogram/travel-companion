/**
 * "Besuchte Orte prüfen": a map-zoom carousel over detected stays
 * (TripMapController::review). One candidate at a time, map fit to a
 * ~2km box centred on it (track/sights/geocaches shown for context, from
 * the same /map/data endpoint the real map page uses), with a bottom bar
 * to edit the name and accept (keep as a visited place) or reject
 * (PoiController::dismissStay - the only "existence" a rejected,
 * never-persisted stay gets, so it stops resurfacing).
 */
document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('review-map');
  var bar = document.querySelector('[data-review-bar]');
  if (!container || !bar || !window.L) {
    return;
  }

  var stays = [];
  try {
    stays = JSON.parse(container.dataset.stays || '[]');
  } catch (e) {
    stays = [];
  }
  if (stays.length === 0) {
    return;
  }

  var nameInput = document.querySelector('[data-review-name]');
  var timeSpan = document.querySelector('[data-review-time]');
  var prevBtn = document.querySelector('[data-review-prev]');
  var nextBtn = document.querySelector('[data-review-next]');
  var acceptBtn = document.querySelector('[data-review-accept]');
  var rejectBtn = document.querySelector('[data-review-reject]');

  var csrfToken = container.dataset.csrfToken;
  var acceptUrl = container.dataset.acceptUrl;
  var dismissUrl = container.dataset.dismissUrl;
  var fallbackName = container.dataset.fallbackName || '';

  var index = 0;
  var candidateMarker = null;

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

  // Context layers (track + sights/geocaches) load once, independent of
  // which candidate is currently shown - only the map's viewport and the
  // highlighted candidate marker change between prev/next.
  fetch(container.dataset.mapDataUrl)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var track = data.track;
      if (track && track.points && track.points.length > 1) {
        var latlngs = track.points.map(function (p) { return [p.lat, p.lng]; });
        L.polyline(latlngs, { color: '#2f6f5e', weight: 3, opacity: 0.8, interactive: false }).addTo(map);
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
        L.marker([poi.lat, poi.lng], { icon: icon }).bindTooltip(poi.name, { sticky: true }).addTo(map);
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
    var stay = stays[index];
    nameInput.value = stay.name || '';
    nameInput.placeholder = fallbackName;

    var minutes = Math.round(stay.durationSeconds / 60);
    timeSpan.textContent = formatTime(stay.startedAt) + ' – ' + formatTime(stay.endedAt) + ' (' + minutes + ' min)';

    if (candidateMarker) {
      map.removeLayer(candidateMarker);
    }
    candidateMarker = L.circleMarker([stay.lat, stay.lng], {
      radius: 10,
      color: '#c56a3c',
      fillColor: '#c56a3c',
      fillOpacity: 0.9,
      weight: 2,
    }).addTo(map);

    // ~2km north-south extent, centred on the candidate, regardless of
    // latitude (a fixed zoom level wouldn't give a consistent real-world
    // size at different latitudes).
    map.fitBounds(L.latLng(stay.lat, stay.lng).toBounds(2000));

    prevBtn.disabled = index === 0;
    nextBtn.disabled = index === stays.length - 1;
  }

  function advanceAfterResolve() {
    stays.splice(index, 1);
    if (stays.length === 0) {
      container.innerHTML = '';
      bar.innerHTML = '<p class="field-hint">' + (container.dataset.msgDone || '') + '</p>';
      return;
    }
    if (index >= stays.length) {
      index = stays.length - 1;
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
    if (index < stays.length - 1) {
      index++;
      render();
    }
  });

  acceptBtn.addEventListener('click', function () {
    var stay = stays[index];
    setBusy(true);
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('lat', String(stay.lat));
    body.set('lng', String(stay.lng));
    body.set('name', nameInput.value.trim());
    body.set('started_at', stay.startedAt);
    body.set('ended_at', stay.endedAt);
    fetch(acceptUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'stay-review' },
      body: body.toString(),
    }).then(function (r) {
      if (!r.ok) {
        throw new Error('accept failed: HTTP ' + r.status);
      }
      setBusy(false);
      advanceAfterResolve();
    }).catch(function () {
      setBusy(false);
      timeSpan.textContent = container.dataset.msgError || '';
    });
  });

  rejectBtn.addEventListener('click', function () {
    var stay = stays[index];
    setBusy(true);
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('lat', String(stay.lat));
    body.set('lng', String(stay.lng));
    fetch(dismissUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (r) {
      if (!r.ok) {
        throw new Error('dismiss failed: HTTP ' + r.status);
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
