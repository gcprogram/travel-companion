/**
 * Track-Player (Stefan's idea, PLAN.md): animates playback of the trip's
 * GPS track under the big interactive map. Initialized by trip-map.js
 * once its own /map/data fetch resolves (window.TrackPlayer.init(...))
 * rather than fetching anything itself, so it shares the exact same
 * points/pins/pois trip-map.js already parsed.
 *
 * Playback model (v2 - Stefan's "it doesn't glide" report on v1): a single
 * requestAnimationFrame loop drives a continuous "elapsed ms" clock over
 * precomputed point-to-point segments (durations from the three speed
 * bands below). The marker/played-line position is LINEARLY INTERPOLATED
 * every frame between the current segment's two points - it glides, it
 * never teleports between v1's discrete per-segment jumps.
 *
 * The camera is a SEPARATE, independently-paced concern: v1 called
 * map.flyTo() once per segment, which whiplashed the zoom on every single
 * short segment and made a huge segment (a flight) look like a snap-zoom
 * because the camera only "found out" about it at the exact moment it
 * started. v2 instead looks AHEAD (lookaheadBounds()) at the next several
 * seconds of upcoming playback, so the camera starts widening out for an
 * upcoming flight WHILE still finishing the previous, denser segment, and
 * commits a new flyTo only when the desired view has actually drifted
 * (maybeRetargetCamera()), at a duration that scales with how much the
 * zoom needs to change - a small pan gets a quick correction, a drastic
 * zoom swing gets several seconds, per Stefan's own diagnosis ("man muss
 * ihm mehr Zeit geben, wenn der Zoom sich sehr stark ändert").
 *
 * Pausing on photos/geocaches (Stefan's ask) is done by PROXIMITY, not by
 * matching timestamps: trip_pois.visit_date has no time-of-day at all
 * (DATE column), so exact time-based matching isn't possible for those -
 * photos do have a precise taken_at, but reusing the same distance check
 * for both keeps one simple mechanism instead of two. Checked against the
 * live interpolated position (not just at real track vertices), so a
 * photo near the middle of a long segment is still found.
 */
(function () {
  // How far ahead (in upcoming PLAYBACK ms, not wall-clock ms) the camera
  // looks when deciding its next target - long enough that an approaching
  // flight is "seen" before it starts, short enough to stay cheap.
  var LOOKAHEAD_MS = 6000;
  var LOOKAHEAD_MAX_POINTS = 80;
  // Camera re-targets are throttled to at most one per this many wall-clock
  // ms - without this, a run of short segments would fight for the camera
  // every few hundred ms and never let a flyTo finish.
  var CAMERA_RETARGET_MIN_INTERVAL_MS = 1200;
  // A retarget's own flyTo duration scales with how much the zoom needs to
  // change (Stefan's diagnosis) - small correction: quick; a drastic
  // zoom-out/in: several seconds, so it always reads as a deliberate pan
  // rather than a snap.
  var CAMERA_BASE_DURATION_S = 1.0;
  var CAMERA_SECONDS_PER_ZOOM_LEVEL = 0.6;
  var CAMERA_MIN_DURATION_S = 0.8;
  var CAMERA_MAX_DURATION_S = 4.5;
  // Below this combined change the camera just isn't retargeted at all -
  // stops it fighting itself over noise-level wobbles.
  var CAMERA_MIN_ZOOM_DELTA = 0.4;
  var CAMERA_MIN_CENTER_DELTA_METERS = 40;
  // Proximity (photo/geocache) checks are real work (haversine per pin/POI)
  // - throttled to a few times a second of PLAYBACK time rather than every
  // single animation frame.
  var PROXIMITY_CHECK_INTERVAL_MS = 200;

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
        // secondsPerRealMinute is "seconds of PLAYBACK per real minute" -
        // a stray extra "* 60" here (carried over from v1) made every
        // dense (< 1 min gap) segment play 60x slower than configured,
        // which is almost certainly THE reason dense tracks felt endless
        // enough that Stefan had to fall back to single-stepping.
        return Math.max(minutes * secondsPerRealMinute * 1000, 30);
      }
      if (minutes < 30) {
        return Math.max(holdSecondsPerPoint, 0.05) * 1000;
      }
      return Math.max(longGapSeconds, 0.1) * 1000;
    }

    // --- Precomputed segments: [{from, to, duration, start}], "start" is
    // the cumulative elapsed-ms at which this segment begins. ---
    var segments = [];
    (function buildSegments() {
      var cum = 0;
      for (var i = 0; i < points.length - 1; i++) {
        var duration = segmentDurationMs(points[i], points[i + 1]);
        segments.push({ from: i, to: i + 1, duration: duration, start: cum });
        cum += duration;
      }
    })();
    var totalDurationMs = segments.length > 0 ? segments[segments.length - 1].start + segments[segments.length - 1].duration : 0;

    function segmentIndexAt(elapsedMs, hint) {
      var i = (hint >= 0 && hint < segments.length) ? hint : 0;
      while (i < segments.length - 1 && elapsedMs >= segments[i].start + segments[i].duration) {
        i++;
      }
      while (i > 0 && elapsedMs < segments[i].start) {
        i--;
      }
      return i;
    }

    function interpolate(segIndex, elapsedMs) {
      var seg = segments[segIndex];
      var t = seg.duration > 0 ? (elapsedMs - seg.start) / seg.duration : 1;
      t = Math.max(0, Math.min(1, t));
      var a = points[seg.from];
      var b = points[seg.to];
      return { lat: a.lat + (b.lat - a.lat) * t, lng: a.lng + (b.lng - a.lng) * t, t: t };
    }

    // Which real point's timestamp best represents the current moment -
    // used for the time readout and for the route-edit deep link on Exit.
    function nearestPointIndex(segIndex, elapsedMs) {
      var seg = segments[segIndex];
      var t = seg.duration > 0 ? (elapsedMs - seg.start) / seg.duration : 1;
      return t >= 0.5 ? seg.to : seg.from;
    }

    // --- State ---
    var elapsedMs = 0;
    var lastSegIndex = 0;
    var playing = false;
    var rafId = null;
    var lastFrameAt = null;
    var lastProximityCheckMs = -Infinity;
    var shownPinIds = {};
    var shownPoiIds = {};
    var photoHoldTimer = null;

    var playedLine = L.polyline([[points[0].lat, points[0].lng]], { color: colorPlayed, weight: 4, opacity: 0.9 });
    var leadingEdgeLine = L.polyline([], { color: colorPlayed, weight: 4, opacity: 0.9 });
    var cursorMarker = L.circleMarker([points[0].lat, points[0].lng], {
      radius: 7, color: colorPlayed, fillColor: colorPlayed, fillOpacity: 1, weight: 2,
    });

    var lastCameraTargetAt = 0;
    var lastCameraZoom = null;
    var lastCameraCenter = null;

    function clampZoom(z) {
      return Math.max(3, Math.min(18, z));
    }

    // Bounds of "where the camera should be looking" - the live position
    // plus every point due to play within the next LOOKAHEAD_MS of
    // playback time, so the camera can start widening for an approaching
    // big jump before that segment even begins.
    function lookaheadBounds(segIndex, elapsedMsNow) {
      var here = interpolate(segIndex, elapsedMsNow);
      var bounds = L.latLngBounds([[here.lat, here.lng]]);
      var seg = segments[segIndex];
      var remaining = LOOKAHEAD_MS - Math.max(seg.start + seg.duration - elapsedMsNow, 0);
      bounds.extend([points[seg.to].lat, points[seg.to].lng]);
      var idx = segIndex + 1;
      var added = 0;
      while (remaining > 0 && idx < segments.length && added < LOOKAHEAD_MAX_POINTS) {
        var s = segments[idx];
        bounds.extend([points[s.to].lat, points[s.to].lng]);
        remaining -= s.duration;
        added++;
        idx++;
      }
      return bounds;
    }

    function maybeRetargetCamera(segIndex, elapsedMsNow, now, force) {
      if (!force && now - lastCameraTargetAt < CAMERA_RETARGET_MIN_INTERVAL_MS) {
        return;
      }
      var bounds = lookaheadBounds(segIndex, elapsedMsNow);
      var desiredZoom;
      try {
        desiredZoom = clampZoom(map.getBoundsZoom(bounds, false, [70, 70]));
      } catch (e) {
        desiredZoom = map.getZoom();
      }
      var center = bounds.getCenter();
      var zoomDelta = Math.abs(desiredZoom - (lastCameraZoom !== null ? lastCameraZoom : map.getZoom()));
      var centerDelta = lastCameraCenter ? map.distance(center, lastCameraCenter) : Infinity;
      if (!force && zoomDelta < CAMERA_MIN_ZOOM_DELTA && centerDelta < CAMERA_MIN_CENTER_DELTA_METERS) {
        return;
      }
      var duration = Math.max(
        CAMERA_MIN_DURATION_S,
        Math.min(CAMERA_MAX_DURATION_S, CAMERA_BASE_DURATION_S + zoomDelta * CAMERA_SECONDS_PER_ZOOM_LEVEL),
      );
      map.flyTo(center, desiredZoom, { duration: duration, easeLinearity: 0.25 });
      lastCameraTargetAt = now;
      lastCameraZoom = desiredZoom;
      lastCameraCenter = center;
    }

    function updateTime(segIndex, elapsedMsNow) {
      if (timeEl) {
        timeEl.textContent = formatLocal(points[nearestPointIndex(segIndex, elapsedMsNow)].recordedAt);
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
      var here = interpolate(lastSegIndex, elapsedMs);
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
     * Checked against the live interpolated position, not just at real
     * track vertices, so a photo mid-segment is still found.
     */
    function checkProximityPause(here) {
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

    function rebuildPlayedLineUpTo(segIndex) {
      var coords = [];
      for (var i = 0; i <= segIndex; i++) {
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
      if (prevBtn) {
        prevBtn.disabled = lastSegIndex <= 0 && elapsedMs <= 0;
      }
      if (nextBtn) {
        nextBtn.disabled = elapsedMs >= totalDurationMs;
      }
    }

    function renderAt(segIndex, elapsedMsNow, now) {
      var here = interpolate(segIndex, elapsedMsNow);
      cursorMarker.setLatLng([here.lat, here.lng]);
      leadingEdgeLine.setLatLngs([
        [points[segments[segIndex].from].lat, points[segments[segIndex].from].lng],
        [here.lat, here.lng],
      ]);
      updateTime(segIndex, elapsedMsNow);
      maybeRetargetCamera(segIndex, elapsedMsNow, now, false);
      return here;
    }

    function stopRaf() {
      if (rafId !== null) {
        cancelAnimationFrame(rafId);
        rafId = null;
      }
      lastFrameAt = null;
    }

    function frame(now) {
      if (!playing) {
        return;
      }
      if (lastFrameAt === null) {
        lastFrameAt = now;
      }
      elapsedMs += now - lastFrameAt;
      lastFrameAt = now;

      if (elapsedMs >= totalDurationMs) {
        elapsedMs = totalDurationMs;
        lastSegIndex = segments.length - 1;
        renderAt(lastSegIndex, elapsedMs, now);
        rebuildPlayedLineUpTo(points.length - 1);
        setPlayingUi(false);
        return;
      }

      var segIndex = segmentIndexAt(elapsedMs, lastSegIndex);
      if (segIndex !== lastSegIndex) {
        rebuildPlayedLineUpTo(segIndex);
        lastSegIndex = segIndex;
      }
      var here = renderAt(segIndex, elapsedMs, now);

      if (elapsedMs - lastProximityCheckMs >= PROXIMITY_CHECK_INTERVAL_MS) {
        lastProximityCheckMs = elapsedMs;
        var hit = checkProximityPause(here);
        if (hit === 'pin') {
          holdForPhoto();
          return;
        }
        if (hit === 'poi') {
          setPlayingUi(false);
          return;
        }
      }

      rafId = requestAnimationFrame(frame);
    }

    function holdForPhoto() {
      // Stays visually "playing" (Stefan's ask: like the lightbox's own
      // slideshow, auto-continues) - the pause button still works because
      // it clears this same timer.
      photoHoldTimer = setTimeout(function () {
        photoHoldTimer = null;
        closeLightboxIfOpen();
        rafId = requestAnimationFrame(frame);
      }, photoHoldSeconds() * 1000);
    }

    function play() {
      setPlayingUi(true);
      lastFrameAt = null;
      maybeRetargetCamera(lastSegIndex, elapsedMs, performance.now(), true);
      rafId = requestAnimationFrame(frame);
    }

    function pause() {
      playing = false;
      stopRaf();
      if (photoHoldTimer) {
        clearTimeout(photoHoldTimer);
        photoHoldTimer = null;
      }
      setPlayingUi(false);
    }

    function seekToPointIndex(index, flyDurationSeconds) {
      index = Math.max(0, Math.min(index, points.length - 1));
      elapsedMs = index === 0 ? 0 : segments[index - 1].start + segments[index - 1].duration;
      lastSegIndex = segmentIndexAt(elapsedMs, lastSegIndex);
      rebuildPlayedLineUpTo(index);
      leadingEdgeLine.setLatLngs([]);
      cursorMarker.setLatLng([points[index].lat, points[index].lng]);
      if (timeEl) {
        timeEl.textContent = formatLocal(points[index].recordedAt);
      }
      map.flyTo([points[index].lat, points[index].lng], map.getZoom(), { duration: flyDurationSeconds });
      lastCameraTargetAt = performance.now();
      lastCameraCenter = L.latLng(points[index].lat, points[index].lng);
      lastCameraZoom = map.getZoom();
      setPlayingUi(playing);
    }

    function stepBy(delta) {
      pause();
      var currentIndex = nearestPointIndex(lastSegIndex, elapsedMs);
      var target = currentIndex + delta;
      if (target < 0 || target >= points.length) {
        return;
      }
      seekToPointIndex(target, 0.4);
    }

    function openPlayer() {
      playedLine.addTo(map);
      leadingEdgeLine.addTo(map);
      cursorMarker.addTo(map);
      if (routeToggle) {
        routeToggle.checked = true;
      }
      startBtn.hidden = true;
      toolbar.hidden = false;
      if (editHint) {
        editHint.hidden = false;
      }
      elapsedMs = 0;
      lastSegIndex = 0;
      rebuildPlayedLineUpTo(0);
      play();
    }

    function closePlayer(jumpToEdit) {
      pause();
      var lastPointIndex = nearestPointIndex(lastSegIndex, elapsedMs);
      var lastPoint = points[lastPointIndex];
      map.removeLayer(playedLine);
      map.removeLayer(leadingEdgeLine);
      map.removeLayer(cursorMarker);
      startBtn.hidden = false;
      toolbar.hidden = true;
      if (editHint) {
        editHint.hidden = true;
      }
      if (poiCard) {
        poiCard.hidden = true;
      }
      elapsedMs = 0;
      lastSegIndex = 0;
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
        } else if (elapsedMs >= totalDurationMs) {
          elapsedMs = 0;
          lastSegIndex = 0;
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
