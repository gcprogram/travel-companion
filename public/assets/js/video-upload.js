/**
 * Video upload: compresses the selected file client-side (video-compress.js,
 * WebCodecs) before handing the result to the shared ChunkedUpload helper.
 * Browsers without WebCodecs support get the file input disabled with an
 * explanatory message pointing at the YouTube-link form instead.
 *
 * Compression itself needs no network (it's pure in-browser WebCodecs), so
 * it runs the same whether online or not. Only the upload step falls back
 * to OfflineQueue when the connection isn't there.
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-video-input]');
  var status = document.querySelector('[data-video-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  if (!input || !status || !csrfField || !window.ChunkedUpload) {
    return;
  }

  if (!window.VideoCompress || !VideoCompress.isSupported()) {
    input.disabled = true;
    status.textContent = input.dataset.msgUnsupported;
    return;
  }

  var uploadUrl = input.dataset.uploadUrl;
  var entryId = parseInt(input.dataset.entryId, 10);

  input.addEventListener('change', function () {
    var file = input.files[0];
    if (!file) {
      return;
    }
    input.disabled = true;

    // Extract GPS from the original container BEFORE compression — the
    // compressed output has none (it's re-encoded from raw decoded frames,
    // the container and its metadata never survive that).
    var geotagPromise = (window.VideoGeotag ? VideoGeotag.extract(file) : Promise.resolve(null))
      .catch(function () { return null; });

    Promise.all([
      geotagPromise,
      VideoCompress.compress(file, {
        onProgress: function (fraction) {
          status.textContent = input.dataset.msgCompressing + ' ' + Math.round(fraction * 100) + '%';
        },
      }),
    ])
      .then(function (results) {
        var geotag = results[0];
        var result = results[1];
        var extraFields = {
          width: String(result.width),
          height: String(result.height),
          duration: String(Math.round(result.duration)),
        };
        if (geotag) {
          extraFields.lat = String(geotag.lat);
          extraFields.lng = String(geotag.lng);
        }
        return uploadOrQueue(result.blob, extraFields);
      })
      .then(function () {
        window.location.reload();
      })
      .catch(function (err) {
        console.error('Video upload failed:', err);
        if (err && err.message === 'video_too_long') {
          status.textContent = input.dataset.msgTooLong;
        } else if (err && err.message === 'codec_unsupported') {
          status.textContent = input.dataset.msgCodecUnsupported;
        } else if (isQuotaExceeded(err)) {
          status.textContent = input.dataset.msgQuotaExceeded;
        } else {
          status.textContent = input.dataset.msgError;
        }
        input.disabled = false;
      });
  });

  // The server rejects an over-quota upload with {"error": "quota_exceeded"}
  // (HTTP 413, same as the too-large case) - a real, permanent rejection,
  // not a network hiccup, so it must not go to isNetworkError() and get
  // queued for later (it would just fail again).
  function isQuotaExceeded(err) {
    return !!(err && typeof err.body === 'string' && err.body.indexOf('quota_exceeded') !== -1);
  }

  function uploadOrQueue(blob, extraFields) {
    if (!navigator.onLine) {
      return queueBlob(blob, extraFields);
    }
    return ChunkedUpload.upload(blob, 'video.mp4', uploadUrl, csrfField.value, {
      onProgress: function (fraction) {
        status.textContent = input.dataset.msgUploading + ' ' + Math.round(fraction * 100) + '%';
      },
      extraFields: extraFields,
    }).catch(function (err) {
      if (isNetworkError(err)) {
        return queueBlob(blob, extraFields);
      }
      throw err;
    });
  }

  function queueBlob(blob, extraFields) {
    if (!window.OfflineQueue) {
      throw new Error('offline_unsupported');
    }
    return OfflineQueue.add({
      type: 'video',
      entryId: entryId,
      uploadUrl: uploadUrl,
      filename: 'video.mp4',
      blob: blob,
      extraFields: extraFields,
    }).then(function () {
      status.textContent = input.dataset.msgQueued || '';
    });
  }

  function isNetworkError(err) {
    return !err || typeof err.status === 'undefined';
  }
});
