/**
 * "Route editieren": trackpoint surgery on top of a plain Leaflet map.
 * Every action (delete/insert/move a point) is a real server round-trip
 * (TrackEditController) followed by a full page reload rather than trying
 * to keep the client-side point list, markers, AND the trim slider's
 * server-rendered seq bounds all in sync after a mutation - simpler and
 * safer than partial re-rendering for what's an occasional, deliberate
 * editing action, not a high-frequency interaction. The current map
 * view (center/zoom) survives that reload via sessionStorage (Stefan's
 * ask - smoothing several neighbouring points shouldn't mean re-finding
 * the same spot on the map after every single edit).
 *
 * Compact toolbar (Stefan's ask, replacing separate "Trackpunkt löschen"/
 * "Trackpunkt hinzufügen" buttons): one "current trackpoint" cursor,
 * shown as an editable time (plus date, only when the track spans more
 * than one day - otherwise the date would just be redundant noise) with
 * </> to step to the previous/next point. Three mode icons act on that
 * cursor or start a two-click gesture:
 *   - Delete: removes whichever point the cursor currently points at
 *     (selected by clicking its marker, by </>, or by typing/picking a
 *     time that matches it) - a plain action, not a toggle mode.
 *   - Add: click two adjacent points, then click the map to place a new
 *     one between them - the time field shows their midpoint and can be
 *     edited before that placement click; whatever it holds is sent as
 *     the new point's time instead of the server's own midpoint.
 *   - Move: click a point, then click the map to relocate it there -
 *     keeps its time/seq, only its position changes (fixes a single GPS
 *     outlier without disturbing the track's timeline).
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
  var moveUrlTemplate = container.dataset.moveUrl;
  var csrfToken = container.dataset.csrfToken;

  function setStatus(text) {
    if (statusEl) {
      statusEl.textContent = text || '';
    }
  }

  // Every edit is a full page reload (see file header) - which used to
  // always re-fit the map to the whole track, throwing away whatever
  // zoom/pan the user was at. Stashing the current view in sessionStorage
  // (per track, via the data URL - survives exactly this tab's reload,
  // not a real navigation elsewhere) and consuming it once on the next
  // load fixes that without needing to keep any client-side state in
  // sync across the reload.
  var viewStorageKey = 'routeEditView:' + (container.dataset.dataUrl || '');

  function saveCurrentView() {
    try {
      var center = map.getCenter();
      window.sessionStorage.setItem(viewStorageKey, JSON.stringify({
        lat: center.lat, lng: center.lng, zoom: map.getZoom(),
      }));
    } catch (e) {
      // Storage unavailable (private browsing, quota) - just falls back
      // to the usual fit-to-track view on the next load, no big deal.
    }
  }

  function takeSavedView() {
    try {
      var raw = window.sessionStorage.getItem(viewStorageKey);
      if (!raw) {
        return null;
      }
      window.sessionStorage.removeItem(viewStorageKey);
      var parsed = JSON.parse(raw);
      if (typeof parsed.lat === 'number' && typeof parsed.lng === 'number' && typeof parsed.zoom === 'number') {
        return parsed;
      }
    } catch (e) {
      // Ignore - falls back to the usual fit-to-track view.
    }
    return null;
  }

  var map = L.map(container, { zoomControl: true });
  L.control.scale({ imperial: false }).addTo(map);
  var tileKey = container.dataset.tileKey;
  if (tileKey) {
    L.tileLayer('https://api.maptiler.com/maps/openstreetmap/256/{z}/{x}/{y}.jpg?key=' + tileKey, {
      maxZoom: 19,
      crossOrigin: true,
      attribution: '&copy; <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> '
        + '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
    }).addTo(map);
  }

  var mode = 'view'; // 'view' | 'add' | 'move'
  var addSelection = []; // up to two selected points while mode === 'add'
  var moveSelection = null; // the point picked up while mode === 'move'
  var modeButtons = document.querySelectorAll('[data-route-edit-mode]');
  var deleteBtn = document.querySelector('[data-route-edit-delete]');
  var prevBtn = document.querySelector('[data-route-edit-prev]');
  var nextBtn = document.querySelector('[data-route-edit-next]');
  var dateInput = document.querySelector('[data-route-edit-current-date]');
  var timeInput = document.querySelector('[data-route-edit-current-time]');

  function resetSelections() {
    addSelection.forEach(function (p) { p.marker.setStyle({ color: '#2f6f5e', fillColor: '#2f6f5e' }); });
    addSelection = [];
    if (moveSelection) {
      moveSelection.marker.setStyle({ color: '#2f6f5e', fillColor: '#2f6f5e' });
      moveSelection = null;
    }
  }

  function setMode(next) {
    mode = mode === next ? 'view' : next;
    resetSelections();
    pendingInsertTime = null;
    renderTimeFields();
    modeButtons.forEach(function (btn) {
      btn.classList.toggle('is-active', btn.dataset.routeEditMode === mode);
    });
    if (mode === 'add') {
      setStatus(container.dataset.msgSelectAdjacent || '');
    } else if (mode === 'move') {
      setStatus(container.dataset.msgMoveSelect || '');
    } else {
      setStatus('');
    }
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

  function deletePointRequest(point) {
    if (!window.confirm(container.dataset.msgDeleteConfirm || '')) {
      return;
    }
    var url = deleteUrlTemplate.replace('__ID__', String(point.id));
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    postJson(url, body).then(function (result) {
      if (result.data && result.data.ok) {
        saveCurrentView();
        window.location.reload();
      } else {
        setStatus(container.dataset.msgError || '');
      }
    }).catch(function () { setStatus(container.dataset.msgError || ''); });
  }

  function insertPointRequest(pointA, pointB, latlng) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('point_id_a', String(pointA.id));
    body.set('point_id_b', String(pointB.id));
    body.set('lat', String(latlng.lat));
    body.set('lng', String(latlng.lng));
    if (pendingInsertTime) {
      body.set('recorded_at', toUtcDateTimeString(pendingInsertTime));
    }
    postJson(insertUrl, body).then(function (result) {
      if (result.data && result.data.ok) {
        saveCurrentView();
        window.location.reload();
      } else if (result.data && result.data.error === 'not_adjacent') {
        setStatus(container.dataset.msgNotAdjacent || '');
      } else {
        setStatus(container.dataset.msgError || '');
      }
    }).catch(function () { setStatus(container.dataset.msgError || ''); });
  }

  function movePointRequest(point, latlng) {
    var url = moveUrlTemplate.replace('__ID__', String(point.id));
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('lat', String(latlng.lat));
    body.set('lng', String(latlng.lng));
    postJson(url, body).then(function (result) {
      if (result.data && result.data.ok) {
        saveCurrentView();
        window.location.reload();
      } else {
        setStatus(container.dataset.msgError || '');
      }
    }).catch(function () { setStatus(container.dataset.msgError || ''); });
  }

  // --- "Current trackpoint" cursor: the point </>, marker clicks, and the
  // editable time field all act on (delete/move act on it directly; add
  // just uses it as the initial two clicks, unrelated to navigation). ---
  var points = [];
  var currentIndex = -1;
  var pendingInsertTime = null; // Date, only set while add-mode has 2 points selected
  var markersById = {};
  var highlightedMarker = null;

  function pad2(n) { return String(n).padStart(2, '0'); }

  function parseUtc(recordedAt) {
    if (!recordedAt) {
      return null;
    }
    var d = new Date(recordedAt.replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? null : d;
  }

  function toLocalDateValue(date) {
    return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
  }

  function toLocalTimeValue(date) {
    return pad2(date.getHours()) + ':' + pad2(date.getMinutes()) + ':' + pad2(date.getSeconds());
  }

  function toUtcDateTimeString(date) {
    return date.getUTCFullYear() + '-' + pad2(date.getUTCMonth() + 1) + '-' + pad2(date.getUTCDate())
      + ' ' + pad2(date.getUTCHours()) + ':' + pad2(date.getUTCMinutes()) + ':' + pad2(date.getUTCSeconds());
  }

  function currentDisplayDate() {
    if (pendingInsertTime) {
      return pendingInsertTime;
    }
    var p = currentIndex >= 0 ? points[currentIndex] : null;
    return p ? parseUtc(p.recordedAt) : null;
  }

  function renderTimeFields() {
    var date = currentDisplayDate();
    if (dateInput) {
      dateInput.value = date ? toLocalDateValue(date) : '';
    }
    if (timeInput) {
      timeInput.value = date ? toLocalTimeValue(date) : '';
    }
  }

  function updateNavButtons() {
    if (prevBtn) {
      prevBtn.disabled = currentIndex <= 0;
    }
    if (nextBtn) {
      nextBtn.disabled = currentIndex < 0 || currentIndex >= points.length - 1;
    }
    if (deleteBtn) {
      deleteBtn.disabled = currentIndex < 0;
    }
  }

  function setHighlightedMarker(marker) {
    if (highlightedMarker && highlightedMarker !== marker) {
      highlightedMarker.setStyle({ radius: 5, color: '#2f6f5e', fillColor: '#2f6f5e', weight: 2 });
    }
    if (marker) {
      marker.setStyle({ radius: 8, color: '#1d4a3c', fillColor: '#1d4a3c', weight: 3 });
    }
    highlightedMarker = marker;
  }

  function focusPoint(index) {
    if (index < 0 || index >= points.length) {
      return;
    }
    currentIndex = index;
    pendingInsertTime = null;
    renderTimeFields();
    updateNavButtons();
    var p = points[index];
    map.setView([p.lat, p.lng], Math.max(map.getZoom(), 15));
    setHighlightedMarker(markersById[p.id] || null);
  }

  function nearestIndexForDate(target) {
    var targetMs = target.getTime();
    var bestIndex = -1;
    var bestDiff = Infinity;
    points.forEach(function (p, i) {
      var d = parseUtc(p.recordedAt);
      if (!d) {
        return;
      }
      var diff = Math.abs(d.getTime() - targetMs);
      if (diff < bestDiff) {
        bestDiff = diff;
        bestIndex = i;
      }
    });
    return bestIndex;
  }

  function fieldsToDate() {
    if (!dateInput || !timeInput || !dateInput.value || !timeInput.value) {
      return null;
    }
    var d = new Date(dateInput.value + 'T' + timeInput.value);
    return isNaN(d.getTime()) ? null : d;
  }

  function onTimeFieldChange() {
    var date = fieldsToDate();
    if (!date) {
      return;
    }
    if (pendingInsertTime) {
      pendingInsertTime = date;
      return;
    }
    var idx = nearestIndexForDate(date);
    if (idx !== -1) {
      focusPoint(idx);
    }
  }

  if (dateInput) {
    dateInput.addEventListener('change', onTimeFieldChange);
  }
  if (timeInput) {
    timeInput.addEventListener('change', onTimeFieldChange);
  }
  if (prevBtn) {
    prevBtn.addEventListener('click', function () { focusPoint(currentIndex - 1); });
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', function () { focusPoint(currentIndex + 1); });
  }
  if (deleteBtn) {
    deleteBtn.addEventListener('click', function () {
      if (currentIndex >= 0) {
        deletePointRequest(points[currentIndex]);
      }
    });
  }

  function midpointDate(pointA, pointB) {
    var ta = parseUtc(pointA.recordedAt);
    var tb = parseUtc(pointB.recordedAt);
    if (!ta || !tb) {
      return null;
    }
    return new Date(Math.round((ta.getTime() + tb.getTime()) / 2));
  }

  function onMarkerClick(point, marker, index) {
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
        pendingInsertTime = midpointDate(pointA, pointB);
        renderTimeFields();
        setStatus(container.dataset.msgPlacePoint || '');
        map.once('click', function (e) {
          insertPointRequest(pointA, pointB, e.latlng);
        });
      }
      return;
    }

    if (mode === 'move') {
      if (moveSelection) {
        return; // Already have a point to move - waiting for the map click.
      }
      marker.setStyle({ color: '#c56a3c', fillColor: '#c56a3c' });
      moveSelection = { point: point, marker: marker };
      focusPoint(index);
      setStatus(container.dataset.msgMovePlace || '');
      map.once('click', function (e) {
        movePointRequest(moveSelection.point, e.latlng);
      });
      return;
    }

    focusPoint(index);
  }

  function formatTooltip(recordedAt) {
    var d = parseUtc(recordedAt);
    if (!d) {
      return '';
    }
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
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
      points = data.points || [];
      if (points.length > 1) {
        L.polyline(points.map(function (p) { return [p.lat, p.lng]; }), {
          color: '#2f6f5e', weight: 3, opacity: 0.8, interactive: false,
        }).addTo(map);
      }

      // Deep link from the Track-Player's Exit button (Stefan's ask): "the
      // trackpoint you were just looking at" carries over as the point to
      // fix, instead of always landing on index 0.
      var focusTimeParam = new URLSearchParams(window.location.search).get('focus_time');
      var focusTarget = focusTimeParam ? parseUtc(focusTimeParam) : null;

      var savedView = takeSavedView();
      if (savedView) {
        map.setView([savedView.lat, savedView.lng], savedView.zoom);
      } else if (points.length > 1) {
        map.fitBounds(points.map(function (p) { return [p.lat, p.lng]; }));
      } else if (points.length === 1) {
        map.setView([points[0].lat, points[0].lng], 15);
      }

      points.forEach(function (point, index) {
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
        marker.on('click', function () { onMarkerClick(point, marker, index); });
        markersById[point.id] = marker;
      });

      // Only one calendar day in the whole track -> the date is implied,
      // showing it in the field would just be redundant noise (Stefan's ask).
      var distinctDates = {};
      points.forEach(function (p) {
        var d = parseUtc(p.recordedAt);
        if (d) {
          distinctDates[toLocalDateValue(d)] = true;
        }
      });
      if (dateInput) {
        dateInput.hidden = Object.keys(distinctDates).length <= 1;
      }

      currentIndex = points.length > 0 ? 0 : -1;
      renderTimeFields();
      updateNavButtons();
      if (points.length === 0) {
        setStatus(container.dataset.msgNoPoints || '');
      } else if (focusTarget && !savedView) {
        focusPoint(nearestIndexForDate(focusTarget));
      }

      initTrimSlider(points);
    })
    .catch(function () { setStatus(container.dataset.msgError || ''); });
});
