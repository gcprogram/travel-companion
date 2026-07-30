/**
 * Trip map: photo/video pins on a Leaflet/OSM map. Two icon states per pin
 * (small dot vs. thumbnail) swapped on zoom instead of rebuilding markers,
 * so panning/zooming a trip with a few hundred pins stays smooth.
 *
 * A real GPS track (GPX upload or folder-derived points) replaces the naive
 * chronological pin-order line once one exists for the trip. POI rendering
 * (task #33) will read the same /map/data response, which already reserves
 * a `pois` key ([] for now).
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
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map);

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
    lightboxBody.innerHTML = pin.kind === 'video'
      ? '<video controls autoplay class="map-lightbox__media" src="' + pin.fullUrl + '"></video>'
      : '<img class="map-lightbox__media" src="' + pin.fullUrl + '" alt="">';
    lightbox.hidden = false;
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

  fetch(container.dataset.dataUrl, { credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      var pins = data.pins || [];
      var track = data.track;
      var trackLatlngs = (track && track.points && track.points.length > 1)
        ? track.points.map(function (p) { return [p.lat, p.lng]; })
        : [];

      if (pins.length === 0 && trackLatlngs.length === 0) {
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
        var routeLine = L.polyline(routeLatlngs, { color: '#2f6f5e', weight: 3, opacity: 0.8 });
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

      function applyIconsForZoom() {
        var useThumb = map.getZoom() >= ZOOM_THUMBNAIL_THRESHOLD;
        markers.forEach(function (m) {
          m.marker.setIcon(useThumb ? m.thumb : m.dot);
        });
      }

      map.on('zoomend', applyIconsForZoom);
      map.fitBounds(pinLatlngs.concat(trackLatlngs), { padding: [40, 40], maxZoom: 16 });
      applyIconsForZoom();
    })
    .catch(function () {
      var empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = container.dataset.msgEmpty;
      container.replaceWith(empty);
    });
});
