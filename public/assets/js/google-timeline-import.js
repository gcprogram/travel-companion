/**
 * Google Timeline.json import, entirely client-side: the export can be tens
 * of MB across several years, and the server's upload cap
 * (post_max_size=20M) couldn't take it raw even if that were desirable -
 * see track-folder-scan.js for the same "extract locally, only send the
 * small result" pattern. A date range (defaulting to the trip's own dates)
 * filters everything down before anything is sent.
 *
 * The export format has changed over the years and Google doesn't document
 * it, so field names here are pieced together from Stefan's own three prior
 * conversion-script attempts (timeline_to_gpx*.php/.py, not part of this
 * repo) rather than an official spec - still not verified against a real
 * export from inside this app, so field-name coverage is kept as wide as
 * those scripts suggest, and unrecognized entries are skipped rather than
 * thrown on:
 *
 * - Old Takeout "Semantic Location History" (one file per month, top-level
 *   {timelineObjects: [...]}): activitySegment (movement - points from
 *   simplifiedRawPath.points or waypointPath.waypoints, each with its own
 *   timestamp/timestampMs, coordinates as latitudeE7/longitudeE7 or
 *   latE7/lngE7 - real degrees * 1e7) and placeVisit (a stop,
 *   location.{latitudeE7,longitudeE7,name,address,placeId} - name/address
 *   are already Google-resolved plain text, no further lookup needed).
 * - New on-device export (single Timeline.json, top-level
 *   {semanticSegments: [...], rawSignals: [...]}): coordinates as degree
 *   strings like "52.520000°, 13.405000°", not E7 integers. rawSignals
 *   entries carry raw GPS/WiFi fixes (.position, or the entry itself is the
 *   position - both seen). semanticSegments carry either .timelinePath (a
 *   route's own points, field "point"), .activity (movement, start/end),
 *   or .visit.topCandidate (a stop, via .placeLocation.latLng) -
 *   topCandidate only has placeId + semanticType (Home/Work/Unknown), NOT
 *   a resolved name/address (Google doesn't embed that in this export;
 *   resolving a placeId needs the paid Places API - Stefan's third script
 *   calls Google's Place Details endpoint for this, but that needs its own
 *   deliberately-chosen API key/cost tradeoff, not wired up here), so these
 *   fall back to the same Nominatim reverse-geocoding PoiController::addStay
 *   already does for track-detected stays.
 * - Oldest raw Takeout export, top-level {locations: [...]}: flat points
 *   with timestampMs and latitudeE7/longitudeE7.
 *
 * Timestamps show up as ISO-8601 strings, epoch milliseconds, or (rarely)
 * epoch seconds - parseTimestampToIso() normalizes all three.
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
  var submitTrackBtn = document.querySelector('[data-timeline-submit-track]');
  var fromInput = document.querySelector('[data-timeline-from]');
  var toInput = document.querySelector('[data-timeline-to]');

  var trackSubmitUrl = fileInput.dataset.trackSubmitUrl;
  var stayUrl = fileInput.dataset.stayUrl;
  var csrfToken = fileInput.dataset.csrfToken;

  var parsedPoints = [];

  function setStatus(text) {
    if (status) {
      status.textContent = text;
    }
  }

  // Both "52.5200°, 13.4050°" and plain "52.5200, 13.4050" - the ° is
  // dropped in some exports/locales.
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

  // Any of: ISO-8601 string, epoch milliseconds, epoch seconds (digit count
  // tells the two numeric cases apart, same heuristic as Stefan's PHP
  // scripts' parseTimestamp()). Returns a Date.parse()-compatible ISO
  // string, or null.
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

  function inRange(iso, fromMs, toMs) {
    var t = Date.parse(iso);
    return !isNaN(t) && t >= fromMs && t <= toMs;
  }

  function pointTimestamp(pt) {
    return parseTimestampToIso(
      pt.timestampMs !== undefined ? pt.timestampMs
        : (pt.timestamp !== undefined ? pt.timestamp : null),
    );
  }

  function extractOldFormat(root, fromMs, toMs, points, visits, counts) {
    if (!Array.isArray(root.timelineObjects)) {
      return false;
    }
    root.timelineObjects.forEach(function (obj) {
      if (obj.activitySegment) {
        var seg = obj.activitySegment;
        var path = (seg.simplifiedRawPath && seg.simplifiedRawPath.points)
          || (seg.waypointPath && seg.waypointPath.waypoints)
          || [];
        path.forEach(function (pt) {
          var ll = parseE7(pt);
          var iso = pointTimestamp(pt);
          if (ll && iso && inRange(iso, fromMs, toMs)) {
            points.push({ lat: ll.lat, lng: ll.lng, recordedAt: iso });
            counts.points++;
          } else {
            counts.skipped++;
          }
        });

        var start = seg.duration && parseTimestampToIso(seg.duration.startTimestamp);
        var end = seg.duration && parseTimestampToIso(seg.duration.endTimestamp);
        [[seg.startLocation, start], [seg.endLocation, end]].forEach(function (pair) {
          var ll = parseE7(pair[0]);
          if (ll && pair[1] && inRange(pair[1], fromMs, toMs)) {
            points.push({ lat: ll.lat, lng: ll.lng, recordedAt: pair[1] });
            counts.points++;
          }
        });
      } else if (obj.placeVisit) {
        var visit = obj.placeVisit;
        var loc = visit.location || {};
        var ll2 = parseE7(loc);
        var vStart = visit.duration && parseTimestampToIso(visit.duration.startTimestamp);
        var vEnd = visit.duration && parseTimestampToIso(visit.duration.endTimestamp);
        if (ll2 && vStart && inRange(vStart, fromMs, toMs)) {
          points.push({ lat: ll2.lat, lng: ll2.lng, recordedAt: vStart });
          counts.points++;
          visits.push({
            lat: ll2.lat, lng: ll2.lng,
            name: loc.name || null,
            address: loc.address || null,
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

  function extractNewFormat(root, fromMs, toMs, points, visits, counts) {
    var found = false;

    if (Array.isArray(root.rawSignals)) {
      found = true;
      root.rawSignals.forEach(function (entry) {
        // Seen both {position: {...}} and the position fields directly on
        // the entry itself, depending on export version.
        var sig = entry && (entry.position || entry);
        var ll = sig && parseLatLngString(sig.LatLng || sig.latLng);
        var iso = sig && parseTimestampToIso(
          sig.timestamp !== undefined ? sig.timestamp
            : (sig.timestampMs !== undefined ? sig.timestampMs : null),
        );
        if (ll && iso && inRange(iso, fromMs, toMs)) {
          points.push({ lat: ll.lat, lng: ll.lng, recordedAt: iso });
          counts.points++;
        } else {
          counts.skipped++;
        }
      });
    }

    if (Array.isArray(root.semanticSegments)) {
      found = true;
      root.semanticSegments.forEach(function (seg) {
        var segStart = parseTimestampToIso(seg.startTime);
        var segEnd = parseTimestampToIso(seg.endTime);

        if (Array.isArray(seg.timelinePath)) {
          seg.timelinePath.forEach(function (p) {
            var ll = parseLatLngString(p.point);
            var iso = parseTimestampToIso(p.time);
            if (ll && iso && inRange(iso, fromMs, toMs)) {
              points.push({ lat: ll.lat, lng: ll.lng, recordedAt: iso });
              counts.points++;
            } else {
              counts.skipped++;
            }
          });
        } else if (seg.activity) {
          var act = seg.activity;
          var s = parseLatLngString(act.start && (act.start.latLng || act.start.LatLng));
          var e = parseLatLngString(act.end && (act.end.latLng || act.end.LatLng));
          if (s && segStart && inRange(segStart, fromMs, toMs)) {
            points.push({ lat: s.lat, lng: s.lng, recordedAt: segStart });
            counts.points++;
          }
          if (e && segEnd && inRange(segEnd, fromMs, toMs)) {
            points.push({ lat: e.lat, lng: e.lng, recordedAt: segEnd });
            counts.points++;
          }
        } else if (seg.visit && seg.visit.topCandidate) {
          var tc = seg.visit.topCandidate;
          var loc = tc.placeLocation || {};
          var ll2 = parseLatLngString(loc.latLng || loc.LatLng);
          if (ll2 && segStart && inRange(segStart, fromMs, toMs)) {
            points.push({ lat: ll2.lat, lng: ll2.lng, recordedAt: segStart });
            counts.points++;
            // No resolved name/address in this export generation - only a
            // Home/Work/Unknown guess and an opaque placeId. Left for
            // PoiController::addStay's existing Nominatim fallback.
            visits.push({
              lat: ll2.lat, lng: ll2.lng,
              name: null, address: null,
              startedAt: segStart, endedAt: segEnd || segStart,
            });
            counts.visits++;
          } else {
            counts.skipped++;
          }
        } else {
          counts.skipped++;
        }
      });
    }

    return found;
  }

  // Oldest raw Takeout export: no semantic segmentation at all, just a flat
  // list of location fixes.
  function extractLocationsFormat(root, fromMs, toMs, points, counts) {
    if (!Array.isArray(root.locations)) {
      return false;
    }
    root.locations.forEach(function (loc) {
      var ll = parseE7(loc);
      var iso = pointTimestamp(loc);
      if (ll && iso && inRange(iso, fromMs, toMs)) {
        points.push({ lat: ll.lat, lng: ll.lng, recordedAt: iso });
        counts.points++;
      } else {
        counts.skipped++;
      }
    });
    return true;
  }

  function dateInputToRangeMs() {
    var fromStr = (fromInput && fromInput.value) || '';
    var toStr = (toInput && toInput.value) || '';
    var fromMs = fromStr ? new Date(fromStr + 'T00:00:00').getTime() : -Infinity;
    var toMs = toStr ? new Date(toStr + 'T23:59:59').getTime() : Infinity;
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

  fileInput.addEventListener('change', function () {
    var file = fileInput.files[0];
    if (!file) {
      return;
    }

    parsedPoints = [];
    if (visitList) {
      visitList.innerHTML = '';
    }
    if (summary) {
      summary.hidden = true;
    }

    setStatus(fileInput.dataset.msgReading || '');
    fileInput.disabled = true;

    file.text()
      .then(function (text) {
        setStatus(fileInput.dataset.msgParsing || '');
        return JSON.parse(text);
      })
      .then(function (root) {
        var range = dateInputToRangeMs();
        var points = [];
        var visits = [];
        var counts = { points: 0, visits: 0, skipped: 0 };

        var recognized = extractOldFormat(root, range.fromMs, range.toMs, points, visits, counts);
        recognized = extractNewFormat(root, range.fromMs, range.toMs, points, visits, counts) || recognized;
        recognized = extractLocationsFormat(root, range.fromMs, range.toMs, points, counts) || recognized;

        fileInput.disabled = false;

        if (!recognized) {
          setStatus(fileInput.dataset.msgUnrecognized || '');
          return;
        }

        parsedPoints = points;
        setStatus((fileInput.dataset.msgParsed || '')
          .replace(':points', String(counts.points))
          .replace(':visits', String(counts.visits))
          .replace(':skipped', String(counts.skipped)));

        if (summary) {
          summary.hidden = false;
        }
        if (submitTrackBtn) {
          submitTrackBtn.disabled = points.length < 2;
        }
        if (visitList) {
          visits.forEach(function (visit) {
            visitList.appendChild(buildVisitForm(visit));
          });
        }
      })
      .catch(function () {
        fileInput.disabled = false;
        setStatus(fileInput.dataset.msgError || '');
      });
  });

  if (submitTrackBtn) {
    submitTrackBtn.addEventListener('click', function () {
      if (parsedPoints.length < 2) {
        return;
      }
      submitTrackBtn.disabled = true;
      setStatus(fileInput.dataset.msgUploading || '');

      fetch(trackSubmitUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ _csrf: csrfToken, points: parsedPoints }),
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('submit_failed: HTTP ' + response.status);
        }
        window.location.reload();
      }).catch(function () {
        submitTrackBtn.disabled = false;
        setStatus(fileInput.dataset.msgError || '');
      });
    });
  }
});
