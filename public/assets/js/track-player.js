/**
 * Track-Player (Stefan's idea, PLAN.md): animates playback of the trip's
 * GPS track under the big interactive map. Initialized by trip-map.js
 * once its own /map/data fetch resolves (window.TrackPlayer.init(...))
 * rather than fetching anything itself, so it shares the exact same
 * points/pins/pois trip-map.js already parsed.
 *
 * Playback model: the track is split into point-to-point segments, each
 * given a duration (ms) by classifyGap() below (Stefan's three speed
 * bands). Advancing a segment calls map.flyTo() for a smooth camera pan
 * AND schedules the next segment via setTimeout matching that same
 * duration - decoupled from flyTo's own animation callback (Leaflet
 * doesn't expose one cleanly per-call), close enough for this purpose.
 * The "already played" polyline just grows by one point per completed
 * segment - far simpler than interpolating the line itself, and
 * invisible at normal point density anyway.
 *
 * Pausing on photos/geocaches (Stefan's ask) is done by PROXIMITY, not by
 * matching timestamps: trip_pois.visit_date has no time-of-day at all
 * (DATE column), so exact time-based matching isn't possible for those -
 * photos do have a precise taken_at, but reusing the same distance check
 * for both keeps one simple mechanism instead of two.
 */
(function () {
  function haversineMeters(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
      * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function parseUtc(recordedAt) {
    if (!recordedAt) {
      return null;
    }
    var d = new Date(recordedAt.replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? null : d;
  }

  function formatLocal(recordedAt) {
    var d = parseUtc(recordedAt);
    if (!d) {
      return '';
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '. ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function init(opts) {
    var el = opts.el;
    var map = opts.map;
    var points = (opts.points || []).filter(function (p) { return p.lat != null && p.lng != null; });
    var pins = opts.pins || [];
    var pois = opts.pois || []; // [{marker, poi}]
    var routeToggle = opts.routeToggle;
    var photoToggle = opts.photoToggle;
    var geocacheToggle = opts.geocacheToggle;
    var openLightboxAt = opts.openLightboxAt;
    var cfg = opts.config || {};

    if (points.length < 2) {
      return; // Nothing meaningful to play back.
    }

    var secondsPerRealMinute = parseFloat(cfg.secondsPerRealMinute) || 1;
    var holdSecondsPerPoint = parseFloat(cfg.holdSecondsPerPoint);
    if (!(holdSecondsPerPoint >= 0)) {
      holdSecondsPerPoint = 1;
    }
    var longGapSeconds = parseFloat(cfg.longGapSeconds) || 1;
    var colorPlayed = cfg.colorPlayed || '#c56a3c';
    var poiMatchMeters = parseFloat(cfg.poiMatchMeters) || 150;

    var startBtn = el.querySelector('[data-track-player-start]');
    var toolbar = el.querySelector('[data-track-player-toolbar]');
    var editHint = el.querySelector('[data-track-player-edit-hint]');
    var prevBtn = el.querySelector('[data-track-player-prev]');
    var toggleBtn = el.querySelector('[data-track-player-toggle]');
    var nextBtn = el.querySelector('[data-track-player-next]');
    var poiBtn = el.querySelector('[data-track-player-poi]');
    var exitBtn = el.querySelector('[data-track-player-exit]');
    var timeEl = el.querySelector('[data-track-player-time]');
    var poiCard = el.querySelector('[data-track-player-poi-card]');
    var routeEditUrl = el.dataset.routeEditUrl || '';
    var msgNoNearby = el.dataset.msgNoNearby || '';
    var msgDistance = el.dataset.msgDistance || ':name (:meters m)';

    // --- Segment durations (Stefan's three speed bands) ---
    // A stationary dwell (TrackSmoothingService's isPause) always flies
    // across the WHOLE dwell in longGapSeconds regardless of its real
    // length - a photo taken during the dwell still gets found and
    // paused on via the proximity check below, so nothing is missed by
    // "fast-forwarding" through it.
    function segmentDurationMs(a, b) {
      if (a.isPause) {
        return Math.max(longGapSeconds, 0.1) * 1000;
      }
      var ta = parseUtc(a.recordedAt);
      var tb = parseUtc(b.recordedAt);
      if (!ta || !tb) {
        return Math.max(holdSecondsPerPoint, 0.1) * 1000;
      }
      var minutes = (tb.getTime() - ta.getTime()) / 60000;
      if (minutes < 1) {
        return Math.max(minutes, 0) * 60 * secondsPerRealMinute * 1000 || 30;
      }
      if (minutes < 30) {
        return Math.max(holdSecondsPerPoint, 0.05) * 1000;
      }
      return Math.max(longGapSeconds, 0.1) * 1000;
    }

    // --- State ---
    var currentIndex = 0; // index into points[] of the last point "arrived at"
    var playing = false;
    var timer = null;
    var shownPinIds = {};
    var shownPoiIds = {};
    var playedLine = L.polyline([[points[0].lat, points[0].lng]], {
      color: colorPlayed, weight: 4, opacity: 0.9,
    });
    var cursorMarker = L.circleMarker([points[0].lat, points[0].lng], {
      radius: 7, color: colorPlayed, fillColor: colorPlayed, fillOpacity: 1, weight: 2,
    });

    function clampZoomForBounds(bounds) {
      var zoom;
      try {
        zoom = map.getBoundsZoom(bounds, false, [60, 60]);
      } catch (e) {
        zoom = map.getZoom();
      }
      // Stefan's ask: the tight-fit zoom made playback feel like it was
      // jumping too hard between points - two levels back (~1:4 the
      // apparent scale, since each Leaflet zoom level doubles it) gives
      // more visual context around the moving point.
      return Math.max(3, Math.min(18, zoom - 2));
    }

    function updateTime() {
      if (timeEl) {
        timeEl.textContent = formatLocal(points[currentIndex].recordedAt);
      }
    }

    function nearestPoiCard() {
      if (!poiCard) {
        return;
      }
      if (pois.length === 0) {
        poiCard.hidden = true;
        return;
      }
      var here = points[currentIndex];
      var best = null;
      var bestDist = Infinity;
      pois.forEach(function (entry) {
        var d = haversineMeters(here.lat, here.lng, entry.poi.lat, entry.poi.lng);
        if (d < bestDist) {
          bestDist = d;
          best = entry;
        }
      });
      if (!best) {
        poiCard.hidden = true;
        return;
      }
      poiCard.textContent = msgDistance.replace(':name', best.poi.name).replace(':meters', Math.round(bestDist));
      poiCard.hidden = false;
    }

    // Reads the SAME "seconds per photo" field the lightbox's own slideshow
    // uses (Stefan's ask) - only rendered for canEdit viewers, so a plain
    // viewer (no edit rights, but the player is public - see PLAN.md) just
    // gets the 3-second default.
    function photoHoldSeconds() {
      var input = document.querySelector('[data-map-lightbox-play-seconds]');
      var value = input ? parseFloat(input.value) : NaN;
      return value > 0 ? value : 3;
    }

    function closeLightboxIfOpen() {
      var lightbox = document.querySelector('[data-map-lightbox]');
      var body = document.querySelector('[data-map-lightbox-body]');
      if (lightbox && !lightbox.hidden) {
        lightbox.hidden = true;
        if (body) {
          body.innerHTML = '';
        }
      }
    }

    /**
     * @return 'pin'|'poi'|null - 'pin' auto-continues after photoHoldSeconds()
     * (Stefan's ask: like the slideshow, not a hard stop - the pause button
     * still works to actually halt it), 'poi' is a hard pause (no natural
     * "how long" for a sight/geocache card), null means nothing nearby.
     */
    function checkProximityPause() {
      var here = points[currentIndex];
      var hit = null;

      if (!photoToggle || photoToggle.checked) {
        pins.forEach(function (pin) {
          if (hit || shownPinIds[pin.kind + ':' + pin.id]) {
            return;
          }
          if (haversineMeters(here.lat, here.lng, pin.lat, pin.lng) <= poiMatchMeters) {
            hit = { type: 'pin', data: pin };
          }
        });
      }
      if (!hit && (!geocacheToggle || geocacheToggle.checked)) {
        pois.forEach(function (entry) {
          if (hit || shownPoiIds[entry.poi.id]) {
            return;
          }
          if (haversineMeters(here.lat, here.lng, entry.poi.lat, entry.poi.lng) <= poiMatchMeters) {
            hit = { type: 'poi', data: entry };
          }
        });
      }

      if (!hit) {
        return null;
      }
      if (hit.type === 'pin') {
        shownPinIds[hit.data.kind + ':' + hit.data.id] = true;
        var idx = pins.indexOf(hit.data);
        if (openLightboxAt && idx >= 0) {
          openLightboxAt(pins, idx);
        }
        return 'pin';
      }
      shownPoiIds[hit.data.poi.id] = true;
      if (hit.data.marker.openTooltip) {
        hit.data.marker.openTooltip();
      }
      nearestPoiCard();
      return 'poi';
    }

    function goToIndex(index, flyDurationSeconds) {
      currentIndex = Math.max(0, Math.min(index, points.length - 1));
      var p = points[currentIndex];
      var latlng = [p.lat, p.lng];
      cursorMarker.setLatLng(latlng);
      var bounds = L.latLngBounds([latlng]);
      if (currentIndex + 1 < points.length) {
        bounds.extend([points[currentIndex + 1].lat, points[currentIndex + 1].lng]);
      } else if (currentIndex > 0) {
        bounds.extend([points[currentIndex - 1].lat, points[currentIndex - 1].lng]);
      }
      map.flyTo(latlng, clampZoomForBounds(bounds), { duration: flyDurationSeconds });
      updateTime();
      if (prevBtn) {
        prevBtn.disabled = currentIndex <= 0;
      }
      if (nextBtn) {
        nextBtn.disabled = currentIndex >= points.length - 1;
      }
    }

    function rebuildPlayedLineUpTo(index) {
      var coords = [];
      for (var i = 0; i <= index; i++) {
        coords.push([points[i].lat, points[i].lng]);
      }
      playedLine.setLatLngs(coords);
    }

    function setPlayingUi(isPlaying) {
      playing = isPlaying;
      if (toggleBtn) {
        toggleBtn.innerHTML = isPlaying ? '&#9208;' : '&#9654;';
        var label = isPlaying ? toggleBtn.dataset.msgPause : toggleBtn.dataset.msgResume;
        if (label) {
          toggleBtn.title = label;
          toggleBtn.setAttribute('aria-label', label);
        }
      }
    }

    function stopTimer() {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
    }

    function scheduleNext() {
      stopTimer();
      if (currentIndex >= points.length - 1) {
        setPlayingUi(false);
        return;
      }
      var durationMs = segmentDurationMs(points[currentIndex], points[currentIndex + 1]);
      goToIndex(currentIndex + 1, durationMs / 1000);
      rebuildPlayedLineUpTo(currentIndex);
      timer = setTimeout(function () {
        var hit = checkProximityPause();
        if (hit === 'pin') {
          // Auto-continues like the lightbox's own slideshow - stays
          // "playing" (same timer var pause() already clears) so the
          // pause button is what actually halts it, per Stefan's ask.
          timer = setTimeout(function () {
            closeLightboxIfOpen();
            scheduleNext();
          }, photoHoldSeconds() * 1000);
        } else if (hit === 'poi') {
          setPlayingUi(false);
        } else {
          scheduleNext();
        }
      }, durationMs);
    }

    function play() {
      setPlayingUi(true);
      scheduleNext();
    }

    function pause() {
      stopTimer();
      setPlayingUi(false);
    }

    function stepBy(delta) {
      pause();
      var target = currentIndex + delta;
      if (target < 0 || target >= points.length) {
        return;
      }
      var durationMs = segmentDurationMs(points[Math.min(currentIndex, target)], points[Math.max(currentIndex, target)]);
      goToIndex(target, Math.min(durationMs, 600) / 1000);
      // Rebuilds either way, direction-agnostic: extends when stepping
      // forward, truncates back when stepping backward (Stefan's ask -
      // going back means "un-playing" that segment).
      rebuildPlayedLineUpTo(currentIndex);
    }

    function openPlayer() {
      playedLine.addTo(map);
      cursorMarker.addTo(map);
      if (routeToggle) {
        routeToggle.checked = true;
      }
      startBtn.hidden = true;
      toolbar.hidden = false;
      if (editHint) {
        editHint.hidden = false;
      }
      goToIndex(currentIndex, 0.5);
      rebuildPlayedLineUpTo(currentIndex);
      play();
    }

    function closePlayer(jumpToEdit) {
      pause();
      var lastPoint = points[currentIndex];
      map.removeLayer(playedLine);
      map.removeLayer(cursorMarker);
      startBtn.hidden = false;
      toolbar.hidden = true;
      if (editHint) {
        editHint.hidden = true;
      }
      if (poiCard) {
        poiCard.hidden = true;
      }
      currentIndex = 0;
      shownPinIds = {};
      shownPoiIds = {};
      if (jumpToEdit && routeEditUrl && lastPoint.recordedAt) {
        window.location.href = routeEditUrl + '?focus_time=' + encodeURIComponent(lastPoint.recordedAt);
      }
    }

    if (startBtn) {
      startBtn.addEventListener('click', openPlayer);
    }
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        if (playing) {
          pause();
        } else if (currentIndex >= points.length - 1) {
          currentIndex = 0;
          rebuildPlayedLineUpTo(0);
          play();
        } else {
          play();
        }
      });
    }
    if (prevBtn) {
      prevBtn.addEventListener('click', function () { stepBy(-1); });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () { stepBy(1); });
    }
    if (poiBtn) {
      poiBtn.addEventListener('click', function () {
        if (pois.length === 0 && poiCard) {
          poiCard.textContent = msgNoNearby;
          poiCard.hidden = false;
          return;
        }
        nearestPoiCard();
      });
    }
    if (exitBtn) {
      exitBtn.addEventListener('click', function () { closePlayer(true); });
    }
  }

  window.TrackPlayer = { init: init };
})();
