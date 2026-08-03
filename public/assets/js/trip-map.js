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
  var lightbox = document.querySelector('[data-map-lightbox]');
  var lightboxBody = document.querySelector('[data-map-lightbox-body]');
  var ZOOM_THUMBNAIL_THRESHOLD = 14;

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
      html: '<img src="' + pin.thumbUrl + '" alt="" loading="lazy">' + badge,
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
    img.src = pin.fullUrl;
    img.alt = '';
    img.onload = function () {
      if (img.naturalHeight > img.naturalWidth) {
        // Portrait: constrain width to its natural aspect ratio so it
        // takes up roughly the same area as a landscape at full width.
        // If landscape at panel-width 300px shows as 300×200, a portrait
        // should display at e.g. 200×300 — proportionally narrower.
        var panelW = lightboxBody.parentElement.offsetWidth - 32;
        img.style.maxWidth = Math.round(panelW * img.naturalWidth / img.naturalHeight) + 'px';
      }
    };
    lightboxBody.innerHTML = '';
    lightboxBody.appendChild(img);
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
    .then(function (response) { return response.json(); })
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

      pins.forEach(function (pin) {
        var marker = L.marker([pin.lat, pin.lng], { icon: dotIcon(pin.kind) });
        marker.on('click', function () { openLightbox(pin); });
        marker.addTo(map);
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
            L.circleMarker([p.lat, p.lng], {
              radius: p.isPause ? 6 : 4,
              color: p.isPause ? '#c56a3c' : '#2f6f5e',
              fillColor: p.isPause ? '#c56a3c' : '#2f6f5e',
              fillOpacity: 0.9,
              weight: 1,
            }).bindTooltip(label, { sticky: true }).addTo(routeGroup);
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

      var poiLatlngs = [];
      pois.forEach(function (poi) {
        var icon = L.divIcon({
          className: 'map-view__poi-pin' + (poi.visited ? ' map-view__poi-pin--visited' : ''),
          iconSize: [16, 16],
        });
        L.marker([poi.lat, poi.lng], { icon: icon }).bindTooltip(poi.name, { sticky: true }).addTo(map);
        poiLatlngs.push([poi.lat, poi.lng]);
      });

      map.on('zoomend', applyIconsForZoom);
      map.fitBounds(pinLatlngs.concat(trackLatlngs).concat(poiLatlngs), { padding: [40, 40], maxZoom: 16 });
      applyIconsForZoom();
    })
    .catch(function () {
      var empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = container.dataset.msgEmpty;
      container.replaceWith(empty);
    });
});
