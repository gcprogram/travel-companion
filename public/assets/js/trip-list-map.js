/**
 * Small, non-interactive track preview per trip card on the home/my-trips
 * list (Stefan's request: a map "right under the title box", never
 * scrollable/zoomable - the whole card is a link to the trip, so the map
 * itself stays pointer-events:none in CSS and just decorates the card
 * instead of capturing clicks/drags meant for navigation). No MapTiler
 * static-maps API (needs a paid plan Stefan doesn't have) - this loads the
 * same tile layer the real trip map uses, just with every interaction
 * handler disabled and a tiny, pre-sampled point list
 * (TrackRepository::previewPoints) instead of the full track.
 */
document.addEventListener('DOMContentLoaded', function () {
  var boxes = document.querySelectorAll('[data-trip-preview-map]');
  boxes.forEach(function (box) {
    var points;
    try {
      points = JSON.parse(box.dataset.points || '[]');
    } catch (e) {
      return;
    }
    if (!Array.isArray(points) || points.length < 2) {
      return;
    }

    var map = L.map(box, {
      zoomControl: false,
      attributionControl: true,
      dragging: false,
      scrollWheelZoom: false,
      doubleClickZoom: false,
      touchZoom: false,
      boxZoom: false,
      keyboard: false,
      tap: false,
    });

    var tileKey = box.dataset.tileKey;
    if (tileKey) {
      L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, {
        maxZoom: 19,
        crossOrigin: true,
        attribution: '&copy; MapTiler &copy; OpenStreetMap contributors',
      }).addTo(map);
    }

    var latlngs = points.map(function (p) { return [p.lat, p.lng]; });
    // interactive:false is the actual fix for click-through, not the
    // container's pointer-events:none in CSS alone - Leaflet's own
    // .leaflet-interactive class sets pointer-events:auto on a path by
    // default (so markers/lines with popups stay clickable even inside a
    // pointer-events:none ancestor), which would otherwise let a click
    // landing exactly on the drawn line swallow the card's own link click.
    L.polyline(latlngs, { color: '#2f6f5e', weight: 3, opacity: 0.9, interactive: false }).addTo(map);
    map.fitBounds(latlngs, { padding: [10, 10] });
  });
});
