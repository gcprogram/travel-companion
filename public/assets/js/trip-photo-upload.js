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
 */
document.addEventListener('DOMContentLoaded', function () {
  var photoInput = document.querySelector('[data-trip-photo-input]');
  var videoInput = document.querySelector('[data-trip-video-input]');
  var status = document.querySelector('[data-trip-upload-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

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
        status.textContent = (status.dataset.msgResolving || '').replace(':filename', file.name);
        var extractPromise = kind === 'photo'
          ? (window.PhotoGeotag ? PhotoGeotag.extract(file) : Promise.resolve(null))
          : (window.VideoGeotag ? VideoGeotag.extract(file) : Promise.resolve(null));

        return extractPromise
          .catch(function () { return null; })
          .then(function (geo) {
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
    return OfflineQueue.sync({
      csrfToken: csrfField.value,
      force: true,
      onProgress: function (done, total) {
        status.textContent = (status.dataset.msgUploading || '')
          .replace(':current', String(done + 1))
          .replace(':total', String(total));
      },
    }).then(function (result) {
      if (result.synced > 0) {
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
