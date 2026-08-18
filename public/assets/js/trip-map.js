/**
 * Trip map: photo/video pins on a Leaflet/OSM map. Two icon states per pin
 * (small dot vs. thumbnail) swapped on zoom instead of rebuilding markers,
 * so panning/zooming a trip with a few hundred pins stays smooth.
 *
 * A real GPS track (GPX upload or folder-derived points) replaces the naive
 * chronological pin-order line once one exists for the trip. POIs (museums,
 * monuments, ...) render as small colored pins, tinted once marked visited.
 */
document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('trip-map');
  if (!container || typeof L === 'undefined') {
    return;
  }

  var routeToggle = document.querySelector('[data-map-route-toggle]');
  var photoToggle = document.querySelector('[data-map-photo-toggle]');
  var geocacheToggle = document.querySelector('[data-map-geocache-toggle]');
  var lightbox = document.querySelector('[data-map-lightbox]');
  var lightboxBody = document.querySelector('[data-map-lightbox-body]');
  var ZOOM_THUMBNAIL_THRESHOLD = 14;
  var canEdit = container.dataset.canEdit === '1';

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

  function dotIcon(kind) {
    return L.divIcon({
      className: 'map-view__pin-dot' + (kind === 'video' ? ' map-view__pin-dot--video' : ''),
      iconSize: [14, 14],
    });
  }

  function thumbIcon(pin) {
    var badge = pin.kind === 'video' ? '<span class="map-view__pin-thumb-badge">&#9654;</span>' : '';
    return L.divIcon({
      className: 'map-view__pin-thumb',
      html: '<img src="' + pin.thumbUrl + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">' + badge,
      iconSize: [44, 44],
    });
  }

  function openLightbox(pin) {
    if (!lightbox || !lightboxBody) {
      return;
    }
    if (pin.kind === 'video') {
      lightboxBody.innerHTML = '<video controls autoplay class="map-lightbox__media" src="' + pin.fullUrl + '"></video>';
      lightbox.hidden = false;
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
    lightbox.hidden = false;
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

  function closeLightbox() {
    if (!lightbox || !lightboxBody) {
      return;
    }
    lightbox.hidden = true;
    lightboxBody.innerHTML = '';
  }

  document.querySelectorAll('[data-map-lightbox-close]').forEach(function (el) {
    el.addEventListener('click', closeLightbox);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeLightbox();
    }
  });

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

      pins.forEach(function (pin) {
        var marker = L.marker([pin.lat, pin.lng], { icon: dotIcon(pin.kind) });
        marker.on('click', function () { openLightbox(pin); });
        marker.addTo(photoGroup);
        markers.push({ marker: marker, pin: pin, dot: dotIcon(pin.kind), thumb: thumbIcon(pin) });
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
