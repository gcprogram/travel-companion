/**
 * Video upload: compresses the selected file client-side (video-compress.js,
 * WebCodecs) before handing the result to OfflineQueue. Browsers without
 * WebCodecs support get the file input disabled with an explanatory message
 * pointing at the YouTube-link form instead.
 *
 * Compression itself needs no network (it's pure in-browser WebCodecs), so
 * it runs the same whether online or not. The compressed result always goes
 * into OfflineQueue first, then gets synced immediately - see
 * photo-upload.js for why: queuing before any network call starts means a
 * mid-upload page navigation only pauses the sync instead of losing the
 * file outright (a queue-then-sync path is durable in IndexedDB; an
 * in-flight fetch aborted by navigation is not, and never gets a chance to
 * queue itself after the fact).
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
        if (!window.OfflineQueue) {
          throw new Error('offline_unsupported');
        }
        // See photo-upload.js: the "saved on this device" message would be
        // wrong here - online, the sync right below starts immediately.
        status.textContent = '';
        return OfflineQueue.add({
          type: 'video',
          entryId: entryId,
          uploadUrl: uploadUrl,
          filename: 'video.mp4',
          blob: result.blob,
          extraFields: extraFields,
        });
      })
      .then(function () {
        // force: true - see photo-upload.js: this follows directly from the
        // user picking a file just now, not an unprompted background sync,
        // so it shouldn't be held back by the WiFi-only preference.
        return OfflineQueue.sync({
          csrfToken: csrfField.value,
          force: true,
          onProgress: function (done, total, fraction) {
            // A video is one big item, so its own chunk percentage is the
            // useful signal - unlike photos, where the file count is.
            status.textContent = input.dataset.msgUploading + ' ' + Math.round(fraction * 100) + '%';
          },
        });
      })
      .then(function (result) {
        if (result.synced > 0) {
          window.location.reload();
          return;
        }
        input.disabled = false;
        if (result.authRequired) {
          status.textContent = input.dataset.msgLoginRequired || input.dataset.msgError;
        } else if (result.failed > 0) {
          status.textContent = input.dataset.msgError;
        } else if (result.skipped) {
          status.textContent = input.dataset.msgQueued || '';
        } else {
          status.textContent = '';
        }
      })
      .catch(function (err) {
        console.error('Video upload failed:', err);
        if (err && err.message === 'video_too_long') {
          status.textContent = input.dataset.msgTooLong;
        } else if (err && err.message === 'codec_unsupported') {
          status.textContent = input.dataset.msgCodecUnsupported;
        } else {
          status.textContent = input.dataset.msgError;
        }
        input.disabled = false;
      });
  });
});
