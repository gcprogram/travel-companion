/**
 * "Route editieren": trackpoint surgery on top of a plain Leaflet map -
 * delete a point (click + confirm) or insert one between two existing,
 * adjacent points (click both, then click the map where the new point
 * should sit - time/elevation get interpolated server-side, see
 * TrackEditService::insertPoint). Every action is a real server round-trip
 * (TrackEditController) followed by a full page reload rather than trying
 * to keep the client-side point list, markers, AND the trim slider's
 * server-rendered seq bounds all in sync after a mutation - simpler and
 * safer than partial re-rendering for what's an occasional, deliberate
 * editing action, not a high-frequency interaction.
 *
 * Also owns the trim-slider live preview, ported from trip-map.js's
 * initTrimSliders() (this page no longer includes trip-map.js - it has its
 * own focused map, not the full pins/lightbox/day-accordion one).
 */
document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('route-edit-map');
  if (!container || typeof L === 'undefined') {
    return;
  }

  var statusEl = document.querySelector('[data-route-edit-status]');
  var deleteUrlTemplate = container.dataset.deleteUrl;
  var insertUrl = container.dataset.insertUrl;
  var csrfToken = container.dataset.csrfToken;

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text || '';
    }
  }

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

  var mode = 'view'; // 'view' | 'delete' | 'add'
  var addSelection = []; // up to two selected points while mode === 'add'
  var modeButtons = document.querySelectorAll('[data-route-edit-mode]');

  function setMode(next) {
    mode = mode === next ? 'view' : next;
    addSelection.forEach(function (p) { p.marker.setStyle({ color: '#2f6f5e', fillColor: '#2f6f5e' }); });
    addSelection = [];
    modeButtons.forEach(function (btn) {
      btn.classList.toggle('is-active', btn.dataset.routeEditMode === mode);
    });
    setStatus(mode === 'add' ? (container.dataset.msgSelectAdjacent || '') : '');
  }

  modeButtons.forEach(function (btn) {
    btn.addEventListener('click', function () { setMode(btn.dataset.routeEditMode); });
  });

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); });
  }

  function deletePoint(point) {
    if (!window.confirm(container.dataset.msgDeleteConfirm || '')) {
      return;
    }
    var url = deleteUrlTemplate.replace('__ID__', String(point.id));
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    postJson(url, body).then(function (result) {
      if (result.data && result.data.ok) {
        window.location.reload();
      } else {
        setStatus(container.dataset.msgError || '');
      }
    }).catch(function () { setStatus(container.dataset.msgError || ''); });
  }

  function insertPoint(pointA, pointB, latlng) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('point_id_a', String(pointA.id));
    body.set('point_id_b', String(pointB.id));
    body.set('lat', String(latlng.lat));
    body.set('lng', String(latlng.lng));
    postJson(insertUrl, body).then(function (result) {
      if (result.data && result.data.ok) {
        window.location.reload();
      } else if (result.data && result.data.error === 'not_adjacent') {
        setStatus(container.dataset.msgNotAdjacent || '');
      } else {
        setStatus(container.dataset.msgError || '');
      }
    }).catch(function () { setStatus(container.dataset.msgError || ''); });
  }

  function onMarkerClick(point, marker) {
    if (mode === 'delete') {
      deletePoint(point);
      return;
    }
    if (mode === 'add') {
      if (addSelection.length > 0 && addSelection[0].point.id === point.id) {
        return; // Same point clicked twice - ignore, still waiting for a second one.
      }
      marker.setStyle({ color: '#c56a3c', fillColor: '#c56a3c' });
      addSelection.push({ point: point, marker: marker });
      if (addSelection.length === 1) {
        setStatus(container.dataset.msgSelectAdjacent || '');
      } else if (addSelection.length === 2) {
        var pointA = addSelection[0].point;
        var pointB = addSelection[1].point;
        setStatus(container.dataset.msgPlacePoint || '');
        map.once('click', function (e) {
          insertPoint(pointA, pointB, e.latlng);
        });
      }
    }
  }

  function formatTooltip(recordedAt) {
    if (!recordedAt) {
      return '';
    }
    var d = new Date(recordedAt.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) {
      return '';
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // --- Trim slider live preview, ported from trip-map.js's initTrimSliders() ---
  function initTrimSlider(points) {
    var form = document.querySelector('[data-trim-slider-form]');
    if (!form || points.length === 0) {
      return;
    }
    var startRange = form.querySelector('[data-trim-range="start"]');
    var endRange = form.querySelector('[data-trim-range="end"]');
    var startTime = form.querySelector('[data-trim-time="start"]');
    var endTime = form.querySelector('[data-trim-time="end"]');
    if (!startRange || !endRange || !startTime || !endTime) {
      return;
    }

    var bySeq = {};
    points.forEach(function (p) { bySeq[p.seq] = p; });

    function toLocalDatetimeValue(isoUtc) {
      var d = new Date(isoUtc.replace(' ', 'T') + 'Z');
      if (isNaN(d.getTime())) {
        return '';
      }
      var pad = function (n) { return String(n).padStart(2, '0'); };
      return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
        + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function nearestSeqForLocalValue(value) {
      var target = new Date(value).getTime();
      if (isNaN(target)) {
        return null;
      }
      var bestSeq = null;
      var bestDiff = Infinity;
      points.forEach(function (p) {
        if (!p.recordedAt) {
          return;
        }
        var diff = Math.abs(new Date(p.recordedAt.replace(' ', 'T') + 'Z').getTime() - target);
        if (diff < bestDiff) {
          bestDiff = diff;
          bestSeq = p.seq;
        }
      });
      return bestSeq;
    }

    function updateTimeField(input, seq) {
      var p = bySeq[seq];
      input.value = (p && p.recordedAt) ? toLocalDatetimeValue(p.recordedAt) : '';
    }

    var previewActive = false;
    var previewContext = L.polyline([], { color: '#6b6357', weight: 2, opacity: 0.4, dashArray: '4,4' });
    var previewLine = L.polyline([], { color: '#c56a3c', weight: 4, opacity: 0.9 });

    function updatePreview() {
      if (!previewActive) {
        previewActive = true;
        previewContext.setLatLngs(points.map(function (p) { return [p.lat, p.lng]; }));
        previewContext.addTo(map);
        previewLine.addTo(map);
      }
      var start = parseInt(startRange.value, 10);
      var end = parseInt(endRange.value, 10);
      var selected = points.filter(function (p) { return p.seq >= start && p.seq <= end; });
      previewLine.setLatLngs(selected.map(function (p) { return [p.lat, p.lng]; }));
    }

    function onRangeInput(which) {
      var startSeq = parseInt(startRange.value, 10);
      var endSeq = parseInt(endRange.value, 10);
      if (which === 'start' && startSeq > endSeq) {
        endRange.value = String(startSeq);
      } else if (which === 'end' && endSeq < startSeq) {
        startRange.value = String(endSeq);
      }
      updateTimeField(startTime, parseInt(startRange.value, 10));
      updateTimeField(endTime, parseInt(endRange.value, 10));
      updatePreview();
    }

    function onTimeChange(which) {
      var input = which === 'start' ? startTime : endTime;
      if (!input.value) {
        return;
      }
      var seq = nearestSeqForLocalValue(input.value);
      if (seq === null) {
        return;
      }
      if (which === 'start') {
        seq = Math.min(seq, parseInt(endRange.value, 10));
        startRange.value = String(seq);
      } else {
        seq = Math.max(seq, parseInt(startRange.value, 10));
        endRange.value = String(seq);
      }
      updateTimeField(startTime, parseInt(startRange.value, 10));
      updateTimeField(endTime, parseInt(endRange.value, 10));
      updatePreview();
    }

    startRange.addEventListener('input', function () { onRangeInput('start'); });
    endRange.addEventListener('input', function () { onRangeInput('end'); });
    startTime.addEventListener('change', function () { onTimeChange('start'); });
    endTime.addEventListener('change', function () { onTimeChange('end'); });

    updateTimeField(startTime, parseInt(startRange.value, 10));
    updateTimeField(endTime, parseInt(endRange.value, 10));
  }

  fetch(container.dataset.dataUrl)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var points = data.points || [];
      if (points.length > 1) {
        L.polyline(points.map(function (p) { return [p.lat, p.lng]; }), {
          color: '#2f6f5e', weight: 3, opacity: 0.8, interactive: false,
        }).addTo(map);
        map.fitBounds(points.map(function (p) { return [p.lat, p.lng]; }));
      } else if (points.length === 1) {
        map.setView([points[0].lat, points[0].lng], 15);
      }

      points.forEach(function (point) {
        var marker = L.circleMarker([point.lat, point.lng], {
          radius: 5,
          color: '#2f6f5e',
          fillColor: '#2f6f5e',
          fillOpacity: 0.9,
          weight: 2,
        }).addTo(map);
        var tooltip = formatTooltip(point.recordedAt);
        if (tooltip) {
          marker.bindTooltip(tooltip);
        }
        marker.on('click', function () { onMarkerClick(point, marker); });
      });

      initTrimSlider(points);
    })
    .catch(function () { setStatus(container.dataset.msgError || ''); });
});
