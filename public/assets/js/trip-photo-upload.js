/**
 * Trip-level photo/video upload (see TripPhotoController - Stage 1 of the
 * new trip-creation flow, HANDOVER.md "Großes Thema"). Unlike
 * day_entries/form.php's per-entry upload widget, no diary entry needs to
 * exist yet: each file's capture date is read client-side (PhotoGeotag/
 * VideoGeotag, already built for the "track from local folder" feature)
 * and used to find-or-create the matching day_entry
 * (DayEntryController::resolveForDate) before handing the file to the
 * exact same chunked-upload/OfflineQueue path the per-entry widget uses -
 * processing, EXIF extraction, storage quotas etc. all behave identically
 * either way, this only adds the date-based routing step in front of it.
 *
 * Files are processed one at a time (not in parallel): video compression
 * is CPU-heavy, and sequential processing means two files sharing the same
 * capture date never race resolveEntryId() into creating two entries for
 * it - the second file's lookup always sees the first file's already-cached
 * result.
 *
 * Also drives the sync-status widget (pending count / login-required
 * prompt / WiFi-only toggle) and resumes automatically on page load and on
 * returning from a backgrounded/locked screen - this page had none of that
 * before (only reacted to picking new files), which is what made an
 * interrupted big batch (session expired mid-upload on a flight, say) look
 * like it lost everything on the next visit: the already-uploaded photos
 * WERE safe (removed from the queue only once the server confirmed them),
 * but nothing here ever said so or picked the rest back up on its own.
 */
document.addEventListener('DOMContentLoaded', function () {
  var photoInput = document.querySelector('[data-trip-photo-input]');
  var videoInput = document.querySelector('[data-trip-video-input]');
  var status = document.querySelector('[data-trip-upload-status]');
  var progress = document.querySelector('[data-trip-upload-progress]');
  var csrfField = document.querySelector('input[name="_csrf"]');
  var syncStatus = document.querySelector('[data-sync-status]');
  var syncCount = document.querySelector('[data-sync-count]');
  var syncNowBtn = document.querySelector('[data-sync-now]');
  var wifiToggle = document.querySelector('[data-wifi-only-toggle]');

  if (!status || !csrfField || !window.OfflineQueue || (!photoInput && !videoInput)) {
    return;
  }

  var resolveUrl = (photoInput || videoInput).dataset.resolveUrl;
  var entryIdCache = {}; // 'YYYY-MM-DD' -> Promise<number>

  function localDateOf(isoUtc) {
    var d = new Date(isoUtc);
    if (isNaN(d.getTime())) {
      return null;
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  // A phone's file picker often hands the browser an internal storage
  // filename (e.g. "1000120353.jpg" from certain OEM camera/MTP
  // providers) that means nothing to the person watching the upload -
  // their gallery app shows a date/time instead, derived from the same
  // EXIF data this already extracts for day-entry routing. Falls back to
  // the raw filename when there's no readable capture date at all (rare).
  function displayNameFor(file, geo) {
    if (!geo || !geo.recordedAt) {
      return file.name;
    }
    var d = new Date(geo.recordedAt);
    if (isNaN(d.getTime())) {
      return file.name;
    }
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear()
      + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function resolveEntryId(date) {
    if (!entryIdCache[date]) {
      entryIdCache[date] = fetch(resolveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({ _csrf: csrfField.value, date: date }),
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('resolve_failed: HTTP ' + response.status);
          }
          return response.json();
        })
        .then(function (data) { return data.id; });
    }
    return entryIdCache[date];
  }

  function queuePhoto(file, entryId) {
    return OfflineQueue.add({
      type: 'photo',
      entryId: entryId,
      uploadUrl: '/entries/' + entryId + '/photos',
      filename: file.name,
      blob: file,
    });
  }

  function queueVideo(file, entryId) {
    return VideoCompress.compress(file, {
      onProgress: function (fraction) {
        status.textContent = (status.dataset.msgCompressing || '') + ' ' + Math.round(fraction * 100) + '%';
      },
    }).then(function (result) {
      return OfflineQueue.add({
        type: 'video',
        entryId: entryId,
        uploadUrl: '/entries/' + entryId + '/videos',
        filename: 'video.mp4',
        blob: result.blob,
        extraFields: {
          width: String(result.width),
          height: String(result.height),
          duration: String(Math.round(result.duration)),
        },
      });
    });
  }

  function processFiles(files, kind) {
    return files.reduce(function (chain, file) {
      return chain.then(function () {
        var extractPromise = kind === 'photo'
          ? (window.PhotoGeotag ? PhotoGeotag.extract(file) : Promise.resolve(null))
          : (window.VideoGeotag ? VideoGeotag.extract(file) : Promise.resolve(null));

        return extractPromise
          .catch(function () { return null; })
          .then(function (geo) {
            status.textContent = (status.dataset.msgResolving || '').replace(':filename', displayNameFor(file, geo));
            // No readable capture date (rare - a photo/video without EXIF
            // at all) falls back to today, same as the manual "add entry"
            // form's own default when a trip has no open date range left.
            var date = (geo && geo.recordedAt) ? localDateOf(geo.recordedAt) : null;
            return resolveEntryId(date || localDateOf(new Date().toISOString()));
          })
          .then(function (entryId) {
            return kind === 'photo' ? queuePhoto(file, entryId) : queueVideo(file, entryId);
          })
          .catch(function (err) {
            console.error((kind === 'photo' ? 'Photo' : 'Video') + ' queue failed for ' + file.name + ':', err);
          });
      });
    }, Promise.resolve());
  }

  function startSync() {
    if (progress) {
      progress.hidden = false;
      progress.value = 0;
    }
    return OfflineQueue.sync({
      csrfToken: csrfField.value,
      force: true,
      onProgress: function (done, total, fraction) {
        status.textContent = (status.dataset.msgUploading || '')
          .replace(':current', String(done + 1))
          .replace(':total', String(total));
        if (progress) {
          progress.value = Math.round(((done + fraction) / total) * 100);
        }
      },
    }).then(function (result) {
      if (progress) {
        progress.hidden = true;
      }
      renderSyncStatus();
      // authRequired first: a batch that synced some items before the
      // session expired must still show the login prompt (via
      // renderSyncStatus(), already called above) instead of reloading
      // straight into the server's own /login redirect - that would look
      // exactly like the upload lost everything, when the ones that made
      // it through are already safe on the server.
      if (result.synced > 0 && !result.authRequired) {
        window.location.reload();
        return;
      }
      if (result.authRequired) {
        status.textContent = status.dataset.msgLoginRequired || status.dataset.msgError || '';
      } else if (result.failed > 0) {
        status.textContent = status.dataset.msgError || '';
      } else {
        status.textContent = '';
      }
    });
  }

  // --- Sync-status widget (pending count / login-required / WiFi-only) ---
  // Same pattern as offline-gallery.js's per-entry widget, duplicated
  // rather than shared - this page has no photo gallery to render queue
  // tiles into, only this summary bar, and the two pages' DOM differs
  // enough that factoring it out isn't worth the coupling.
  if (wifiToggle) {
    wifiToggle.checked = OfflineQueue.isWifiOnly();
    wifiToggle.addEventListener('change', function () {
      OfflineQueue.setWifiOnly(wifiToggle.checked);
      trySync(false);
    });
  }
  if (syncNowBtn) {
    syncNowBtn.addEventListener('click', function () { trySync(true); });
  }

  function renderSyncStatus() {
    if (!syncStatus || !syncCount) {
      return;
    }
    OfflineQueue.getAll().then(function (items) {
      var count = items.length;
      if (count === 0) {
        syncStatus.hidden = true;
        return;
      }
      syncStatus.hidden = false;

      var needsLogin = items.some(function (i) { return i.lastError === 'auth_required'; });
      if (syncNowBtn) {
        syncNowBtn.hidden = needsLogin;
      }
      if (needsLogin) {
        syncCount.textContent = '';
        var link = document.createElement('a');
        link.href = '/login';
        link.textContent = syncStatus.dataset.msgLoginRequired || '';
        syncCount.appendChild(link);
        return;
      }

      var quotaExceeded = items.some(function (i) { return i.lastError === 'quota_exceeded'; });
      if (quotaExceeded) {
        syncCount.textContent = syncStatus.dataset.msgQuotaExceeded || '';
        return;
      }

      var label = count === 1
        ? syncStatus.dataset.msgPendingOne
        : (syncStatus.dataset.msgPendingMany || '').replace(':count', String(count));
      if (!OfflineQueue.canAutoSync() && navigator.onLine) {
        label += ' — ' + syncStatus.dataset.msgWaitingWifi;
      }
      syncCount.textContent = label;
    });
  }

  function trySync(force) {
    OfflineQueue.sync({ csrfToken: csrfField.value, force: force }).then(function (result) {
      renderSyncStatus();
      if (result.synced > 0 && !result.authRequired) {
        window.location.reload();
      }
    });
  }

  // Resume whatever's still queued from an earlier, interrupted visit -
  // this page previously only ever reacted to picking new files, so
  // returning here after a forced re-login (session expired mid-upload)
  // left the rest of the batch sitting untouched until the user picked
  // files again themselves.
  renderSyncStatus();
  trySync(false);
  window.addEventListener('online', function () { trySync(false); });
  window.addEventListener('offlinequeue:change', renderSyncStatus);
  // Covers returning from a locked/backgrounded screen the same way
  // 'online' covers regaining a connection - see chunked-upload.js's
  // per-chunk timeout, which is what lets a stalled request from before
  // the lock actually fail so this can safely start a fresh attempt
  // instead of racing it (offline-queue.js's sync() also guards against
  // that directly).
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      trySync(false);
    }
  });

  if (photoInput) {
    photoInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(photoInput.files);
      if (files.length === 0) {
        return;
      }
      photoInput.disabled = true;
      processFiles(files, 'photo').then(startSync).then(function () {
        photoInput.disabled = false;
      }, function () {
        photoInput.disabled = false;
      });
    });
  }

  if (videoInput) {
    if (!window.VideoCompress || !VideoCompress.isSupported()) {
      videoInput.disabled = true;
      status.textContent = status.dataset.msgVideoUnsupported || '';
    } else {
      videoInput.addEventListener('change', function () {
        var files = Array.prototype.slice.call(videoInput.files);
        if (files.length === 0) {
          return;
        }
        videoInput.disabled = true;
        processFiles(files, 'video').then(startSync).then(function () {
          videoInput.disabled = false;
        }, function () {
          videoInput.disabled = false;
        });
      });
    }
  }
});
