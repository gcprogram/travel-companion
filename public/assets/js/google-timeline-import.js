/**
 * Google Timeline.json import, entirely client-side: the export can be tens
 * of MB across several years, and the server's upload cap
 * (post_max_size=20M) couldn't take it raw even if that were desirable -
 * see track-folder-scan.js for the same "extract locally, only send the
 * small result" pattern. A date range (defaulting to the trip's own dates)
 * filters everything down before anything is sent.
 *
 * The export format has changed over the years and Google doesn't document
 * it. Field names here come from Stefan's own conversion-script attempts
 * (timeline_to_gpx*.php/.py, not part of this repo) and, crucially, from a
 * back-and-forth with Gemini debugging exactly this "0 results" problem
 * against Stefan's real export - the diagnosis there is the reason this
 * isn't a single rigid per-container parser:
 *
 * - Coordinates show up as degree strings like "49.9777661°, 8.6588394°",
 *   under DIFFERENT key names depending on where they sit: "LatLng" (capital
 *   L) inside a raw position fix, "latLng" (lowercase) inside a place
 *   visit's placeLocation, or as the bare string under "point" inside a
 *   timelinePath entry.
 * - Timestamps are equally inconsistent: "timestamp" on raw signals and
 *   activity records, "startTime"/"endTime" on segments, "time" inside a
 *   timelinePath entry, "deliveryTime" on a wifiScan.
 * - Raw signal entries (position/wifiScan/activityRecord fixes) are NOT
 *   reliably confined to one predictable top-level container - real
 *   exports put them at varying nesting depths. A parser that only walks
 *   one assumed path (e.g. root.rawSignals) silently finds nothing for a
 *   file shaped differently.
 *
 * Because of that last point, raw position fixes are found via
 * walkForRawPoints() - a full recursive walk of the entire parsed JSON
 * that looks for the known coordinate-string patterns at ANY depth,
 * regardless of which key contains them - rather than trusting a fixed
 * container path. This is deliberately redundant with the structured
 * per-segment walks below (both may find the same point - a shared "seen"
 * set dedupes by lat/lng/timestamp). The structured walks still exist
 * because they carry segment context the recursive walk doesn't: a
 * semanticSegment's own startTime for a visit, or the distinction between
 * "just a point" and "a place visit worth offering as a station".
 *
 * Old Takeout "Semantic Location History" (one file per month, top-level
 * {timelineObjects: [...]}) is a structurally distinct, older generation:
 * coordinates are latitudeE7/longitudeE7 (or short latE7/lngE7) - real
 * degrees * 1e7, not degree strings - so it gets its own structured walk;
 * the recursive walker only understands the string format.
 *
 * Place-visit names: old-format placeVisit.location already has plain-text
 * name/address from Google, used directly. New-format visit.topCandidate
 * only has a placeId + semanticType (Home/Work/Unknown) - Google doesn't
 * embed a resolved name in this export generation; actually resolving a
 * placeId needs the paid Places API (Stefan's own script calls Place
 * Details for this) - a deliberate API-key/cost decision, not wired up
 * here. These visits fall back to the same Nominatim reverse-geocoding
 * PoiController::addStay already does for track-detected stays.
 *
 * The parse summary intentionally reports raw counts (points found, visits
 * found, entries skipped) so a wrong field-name assumption shows up
 * immediately as "0 points" instead of silently importing nothing.
 */
document.addEventListener('DOMContentLoaded', function () {
  var fileInput = document.querySelector('[data-timeline-file-input]');
  if (!fileInput) {
    return;
  }

  var status = document.querySelector('[data-timeline-status]');
  var summary = document.querySelector('[data-timeline-summary]');
  var visitList = document.querySelector('[data-timeline-visits]');
  var uploadBtn = document.querySelector('[data-timeline-upload-btn]');
  var fromInput = document.querySelector('[data-timeline-from]');
  var toInput = document.querySelector('[data-timeline-to]');

  var trackSubmitUrl = fileInput.dataset.trackSubmitUrl;
  var stayUrl = fileInput.dataset.stayUrl;
  var csrfToken = fileInput.dataset.csrfToken;

  function setStatus(text) {
    if (status) {
      status.textContent = text;
    }
  }

  // Both "49.9777661°, 8.6588394°" and plain "49.9777661, 8.6588394" - the
  // ° is dropped in some exports/locales.
  function parseLatLngString(value) {
    if (typeof value !== 'string') {
      return null;
    }
    var m = value.match(/(-?\d+(?:\.\d+)?)\D+(-?\d+(?:\.\d+)?)/);
    if (!m) {
      return null;
    }
    var lat = parseFloat(m[1]);
    var lng = parseFloat(m[2]);
    if (isNaN(lat) || isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
      return null;
    }
    return { lat: lat, lng: lng };
  }

  // E7 = real degrees * 1e7, stored as an integer to avoid float precision
  // loss - both the "latitudeE7/longitudeE7" and short "latE7/lngE7" key
  // names show up depending on which part of the export it's in.
  function parseE7(obj) {
    if (!obj) {
      return null;
    }
    var latRaw = obj.latitudeE7 !== undefined ? obj.latitudeE7 : obj.latE7;
    var lngRaw = obj.longitudeE7 !== undefined ? obj.longitudeE7 : obj.lngE7;
    if (typeof latRaw !== 'number' || typeof lngRaw !== 'number') {
      return null;
    }
    var lat = latRaw / 1e7;
    var lng = lngRaw / 1e7;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180) {
      return null;
    }
    return { lat: lat, lng: lng };
  }

  // Any of: ISO-8601 string (with or without a numeric offset/milliseconds),
  // epoch milliseconds, epoch seconds (digit count tells the two numeric
  // cases apart). Returns a Date.parse()-compatible ISO string, or null.
  function parseTimestampToIso(value) {
    if (value === null || value === undefined || value === '') {
      return null;
    }
    if (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) {
      var n = Number(value);
      var ms = String(Math.trunc(n)).length > 11 ? n : n * 1000;
      var d = new Date(ms);
      return isNaN(d.getTime()) ? null : d.toISOString();
    }
    if (typeof value === 'string') {
      var t = Date.parse(value);
      return isNaN(t) ? null : new Date(t).toISOString();
    }
    return null;
  }

  // Priority mirrors the field names actually seen: raw signals and
  // activity records use "timestamp", timelinePath entries use "time",
  // segments use "startTime", wifiScan uses "deliveryTime".
  function pickTimestamp(node) {
    return parseTimestampToIso(
      node.timestamp !== undefined ? node.timestamp
        : node.time !== undefined ? node.time
          : node.startTime !== undefined ? node.startTime
            : node.deliveryTime !== undefined ? node.deliveryTime
              : null,
    );
  }

  // Every known shape a raw degree-string coordinate has turned up in:
  // position.LatLng (capital L), position.latLng/placeLocation.latLng
  // (lowercase), a bare "point" string, or LatLng/latLng directly on the
  // node itself (position sub-object passed in on its own).
  function pickRawCoordString(node) {
    if (node.position && typeof node.position === 'object') {
      return node.position.LatLng || node.position.latLng || null;
    }
    if (node.placeLocation && typeof node.placeLocation === 'object') {
      return node.placeLocation.latLng || node.placeLocation.LatLng || null;
    }
    if (typeof node.point === 'string') {
      return node.point;
    }
    if (typeof node.LatLng === 'string' || typeof node.latLng === 'string') {
      return node.LatLng || node.latLng;
    }
    return null;
  }

  function inRange(iso, fromMs, toMs) {
    var t = Date.parse(iso);
    return !isNaN(t) && t >= fromMs && t <= toMs;
  }

  function addPointOnce(points, seen, counts, lat, lng, iso) {
    var key = lat + ',' + lng + ',' + iso;
    if (seen[key]) {
      return;
    }
    seen[key] = true;
    points.push({ lat: lat, lng: lng, recordedAt: iso });
    counts.points++;
  }

  // Recursive safety net: walks the entire parsed JSON tree looking for a
  // coordinate string at any depth, regardless of which key/container holds
  // it - see the file header for why a fixed container path isn't reliable
  // here. Deliberately overlaps with the structured walks below; addPointOnce
  // dedupes.
  function walkForRawPoints(node, fromMs, toMs, points, seen, counts) {
    if (Array.isArray(node)) {
      for (var i = 0; i < node.length; i++) {
        walkForRawPoints(node[i], fromMs, toMs, points, seen, counts);
      }
      return;
    }
    if (!node || typeof node !== 'object') {
      return;
    }

    var raw = pickRawCoordString(node);
    if (raw) {
      var ll = parseLatLngString(raw);
      var iso = pickTimestamp(node);
      if (ll && iso && inRange(iso, fromMs, toMs)) {
        addPointOnce(points, seen, counts, ll.lat, ll.lng, iso);
      }
    }

    for (var key in node) {
      if (Object.prototype.hasOwnProperty.call(node, key)) {
        walkForRawPoints(node[key], fromMs, toMs, points, seen, counts);
      }
    }
  }

  function extractOldFormat(root, fromMs, toMs, points, visits, seen, counts) {
    if (!Array.isArray(root.timelineObjects)) {
      return false;
    }
    root.timelineObjects.forEach(function (obj) {
      if (!obj || typeof obj !== 'object') {
        counts.skipped++;
        return;
      }
      if (obj.activitySegment) {
        var seg = obj.activitySegment;
        var path = (seg.simplifiedRawPath && seg.simplifiedRawPath.points)
          || (seg.waypointPath && seg.waypointPath.waypoints)
          || [];
        path.forEach(function (pt) {
          if (!pt || typeof pt !== 'object') {
            counts.skipped++;
            return;
          }
          var ll = parseE7(pt);
          var iso = parseTimestampToIso(pt.timestampMs !== undefined ? pt.timestampMs : pt.timestamp);
          if (ll && iso && inRange(iso, fromMs, toMs)) {
            addPointOnce(points, seen, counts, ll.lat, ll.lng, iso);
          } else {
            counts.skipped++;
          }
        });

        var start = seg.duration && parseTimestampToIso(seg.duration.startTimestamp);
        var end = seg.duration && parseTimestampToIso(seg.duration.endTimestamp);
        [[seg.startLocation, start], [seg.endLocation, end]].forEach(function (pair) {
          var ll = parseE7(pair[0]);
          if (ll && pair[1] && inRange(pair[1], fromMs, toMs)) {
            addPointOnce(points, seen, counts, ll.lat, ll.lng, pair[1]);
          }
        });
      } else if (obj.placeVisit) {
        var visit = obj.placeVisit;
        var loc = visit.location || {};
        var ll2 = parseE7(loc);
        var vStart = visit.duration && parseTimestampToIso(visit.duration.startTimestamp);
        var vEnd = visit.duration && parseTimestampToIso(visit.duration.endTimestamp);
        if (ll2 && vStart && inRange(vStart, fromMs, toMs)) {
          addPointOnce(points, seen, counts, ll2.lat, ll2.lng, vStart);
          visits.push({
            lat: ll2.lat, lng: ll2.lng,
            name: loc.name || null,
            address: loc.address || null,
            placeId: loc.placeId || null,
            startedAt: vStart, endedAt: vEnd || vStart,
          });
          counts.visits++;
        } else {
          counts.skipped++;
        }
      } else {
        counts.skipped++;
      }
    });
    return true;
  }

  function extractSemanticSegments(root, fromMs, toMs, points, visits, seen, counts) {
    if (!Array.isArray(root.semanticSegments)) {
      return false;
    }
    root.semanticSegments.forEach(function (seg) {
      if (!seg || typeof seg !== 'object') {
        counts.skipped++;
        return;
      }
      var segStart = parseTimestampToIso(seg.startTime);
      var segEnd = parseTimestampToIso(seg.endTime);

      if (Array.isArray(seg.timelinePath)) {
        seg.timelinePath.forEach(function (p) {
          if (!p || typeof p !== 'object') {
            counts.skipped++;
            return;
          }
          var ll = parseLatLngString(p.point);
          var iso = parseTimestampToIso(p.time);
          if (ll && iso && inRange(iso, fromMs, toMs)) {
            addPointOnce(points, seen, counts, ll.lat, ll.lng, iso);
          } else {
            counts.skipped++;
          }
        });
      }
      if (seg.activity) {
        var act = seg.activity;
        var s = parseLatLngString(act.start && (act.start.latLng || act.start.LatLng));
        var e = parseLatLngString(act.end && (act.end.latLng || act.end.LatLng));
        if (s && segStart && inRange(segStart, fromMs, toMs)) {
          addPointOnce(points, seen, counts, s.lat, s.lng, segStart);
        }
        if (e && segEnd && inRange(segEnd, fromMs, toMs)) {
          addPointOnce(points, seen, counts, e.lat, e.lng, segEnd);
        }
      }
      if (seg.visit && seg.visit.topCandidate) {
        var tc = seg.visit.topCandidate;
        var loc = tc.placeLocation || {};
        var ll2 = parseLatLngString(loc.latLng || loc.LatLng);
        if (ll2 && segStart && inRange(segStart, fromMs, toMs)) {
          addPointOnce(points, seen, counts, ll2.lat, ll2.lng, segStart);
          // No resolved name/address in this export generation - only a
          // Home/Work/Unknown guess and an opaque placeId. Sent along so
          // PoiController::addStay can try resolving it via the Places API
          // (if an admin-configured key exists) before its Nominatim
          // fallback.
          visits.push({
            lat: ll2.lat, lng: ll2.lng,
            name: null, address: null, placeId: tc.placeId || null,
            startedAt: segStart, endedAt: segEnd || segStart,
          });
          counts.visits++;
        } else {
          counts.skipped++;
        }
      }
    });
    return true;
  }

  // Oldest raw Takeout export: no semantic segmentation at all, just a flat
  // list of location fixes.
  function extractLocationsFormat(root, fromMs, toMs, points, seen, counts) {
    if (!Array.isArray(root.locations)) {
      return false;
    }
    root.locations.forEach(function (loc) {
      if (!loc || typeof loc !== 'object') {
        counts.skipped++;
        return;
      }
      var ll = parseE7(loc);
      var iso = parseTimestampToIso(loc.timestampMs !== undefined ? loc.timestampMs : loc.timestamp);
      if (ll && iso && inRange(iso, fromMs, toMs)) {
        addPointOnce(points, seen, counts, ll.lat, ll.lng, iso);
      } else {
        counts.skipped++;
      }
    });
    return true;
  }

  // Inputs are type="datetime-local" (prefilled to the trip's start day
  // 00:00 / end day 23:59 by the template) - the value already carries a
  // time component, unlike the old type="date" inputs this replaced.
  function dateInputToRangeMs() {
    var fromStr = (fromInput && fromInput.value) || '';
    var toStr = (toInput && toInput.value) || '';
    var fromMs = fromStr ? new Date(fromStr).getTime() : -Infinity;
    var toMs = toStr ? new Date(toStr).getTime() : Infinity;
    return { fromMs: fromMs, toMs: toMs };
  }

  function buildVisitForm(visit) {
    var li = document.createElement('li');
    li.className = 'stay-list__item';

    var info = document.createElement('div');
    var label = document.createElement('span');
    label.className = 'stay-list__location';
    label.textContent = visit.name
      ? visit.name + (visit.address ? ' (' + visit.address + ')' : '')
      : (fileInput.dataset.msgVisitUnnamed || '');
    info.appendChild(label);

    var time = document.createElement('span');
    time.className = 'stay-list__time';
    time.textContent = new Date(visit.startedAt).toLocaleString();
    info.appendChild(document.createElement('br'));
    info.appendChild(time);
    li.appendChild(info);

    var form = document.createElement('form');
    form.method = 'post';
    form.action = stayUrl;
    form.className = 'stay-list__actions';

    [
      ['_csrf', csrfToken],
      ['lat', String(visit.lat)],
      ['lng', String(visit.lng)],
      ['name', visit.name || ''],
      ['address', visit.address || ''],
      ['place_id', visit.placeId || ''],
      ['started_at', visit.startedAt],
      ['ended_at', visit.endedAt],
    ].forEach(function (pair) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = pair[0];
      input.value = pair[1];
      form.appendChild(input);
    });

    var btn = document.createElement('button');
    btn.type = 'submit';
    btn.className = 'btn btn-ghost btn-small';
    btn.textContent = fileInput.dataset.msgVisitAdd || '';
    form.appendChild(btn);

    li.appendChild(form);
    return li;
  }

  // One button does the whole thing (read -> parse -> filter by the
  // current date range -> submit) - no separate preview/"Cut" step. The
  // page only reloads (discarding the upload form) once there's nothing
  // left to do; if the file also contained place-visits, those still need
  // individual per-visit confirmation (buildVisitForm() below, a distinct
  // feature from the track itself - see PoiController::addStay), so the
  // reload waits until after the user has had a chance to act on them.
  function runImport() {
    var file = fileInput.files[0];
    if (!file) {
      setStatus(fileInput.dataset.msgNoFile || '');
      return;
    }

    if (visitList) {
      visitList.innerHTML = '';
    }
    if (summary) {
      summary.hidden = true;
    }

    setStatus(fileInput.dataset.msgReading || '');
    if (uploadBtn) {
      uploadBtn.disabled = true;
    }

    file.text()
      .then(function (text) {
        setStatus(fileInput.dataset.msgParsing || '');
        return JSON.parse(text);
      })
      .then(function (root) {
        var range = dateInputToRangeMs();
        var points = [];
        var visits = [];
        var seen = {};
        var counts = { points: 0, visits: 0, skipped: 0 };

        var recognized = extractOldFormat(root, range.fromMs, range.toMs, points, visits, seen, counts);
        recognized = extractSemanticSegments(root, range.fromMs, range.toMs, points, visits, seen, counts) || recognized;
        recognized = extractLocationsFormat(root, range.fromMs, range.toMs, points, seen, counts) || recognized;
        // Always runs, format-agnostic - catches raw position/wifi fixes
        // wherever they actually live in this particular export.
        walkForRawPoints(root, range.fromMs, range.toMs, points, seen, counts);

        if (!recognized && points.length === 0) {
          setStatus(fileInput.dataset.msgUnrecognized || '');
          if (uploadBtn) {
            uploadBtn.disabled = false;
          }
          return null;
        }

        var parsedMsg = (fileInput.dataset.msgParsed || '')
          .replace(':points', String(counts.points))
          .replace(':visits', String(counts.visits))
          .replace(':skipped', String(counts.skipped));

        if (points.length < 2) {
          // Not enough for a track, but any place-visits found are still
          // worth offering - a single-location day can have zero movement.
          setStatus(parsedMsg);
          if (uploadBtn) {
            uploadBtn.disabled = false;
          }
          if (visitList && visits.length > 0) {
            visits.forEach(function (visit) {
              visitList.appendChild(buildVisitForm(visit));
            });
            if (summary) {
              summary.hidden = false;
            }
          }
          return null;
        }

        setStatus(fileInput.dataset.msgUploading || '');
        return fetch(trackSubmitUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf: csrfToken, points: points }),
        }).then(function (response) {
          if (!response.ok) {
            throw new Error('submit_failed: HTTP ' + response.status);
          }
          if (visits.length > 0 && visitList) {
            visits.forEach(function (visit) {
              visitList.appendChild(buildVisitForm(visit));
            });
            if (summary) {
              summary.hidden = false;
            }
            setStatus(parsedMsg);
            if (uploadBtn) {
              uploadBtn.disabled = false;
            }
          } else {
            window.location.reload();
          }
        });
      })
      .catch(function (err) {
        if (uploadBtn) {
          uploadBtn.disabled = false;
        }
        // The generic error message alone gives no clue what actually broke
        // (JSON.parse failure vs. an export quirk crashing one of the
        // extract* walks) - logging it means a report of "something went
        // wrong" can be diagnosed from the reporter's own browser console
        // instead of needing their raw export file.
        if (window.console && console.error) {
          console.error('Timeline import failed:', err);
        }
        // A SyntaxError here only ever comes from the JSON.parse() step -
        // worth its own message since it means the file itself is broken
        // (seen in practice: a manually trimmed-down export missing its
        // enclosing brackets), not an unrecognized-but-valid export shape.
        var msg = (err instanceof SyntaxError)
          ? (fileInput.dataset.msgInvalidJson || fileInput.dataset.msgError)
          : fileInput.dataset.msgError;
        setStatus(msg || '');
      });
  }

  if (uploadBtn) {
    uploadBtn.addEventListener('click', runImport);
  }
});
