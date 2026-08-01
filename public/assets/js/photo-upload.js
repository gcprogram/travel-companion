/**
 * Photo upload: hands each selected file to the shared ChunkedUpload helper
 * (chunked-upload.js) one at a time. Processing (thumbnails) happens async
 * on the server; we just reload once every file has been uploaded so the
 * gallery picks up the new entries. Files that can't reach the server
 * (offline, or the connection drops mid-upload) go into OfflineQueue
 * instead of failing outright — offline-gallery.js renders them and syncs
 * them later.
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-photo-input]');
  var status = document.querySelector('[data-photo-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  if (!input || !status || !csrfField || !window.ChunkedUpload) {
    return;
  }

  var uploadUrl = input.dataset.uploadUrl;
  var entryId = parseInt(input.dataset.entryId, 10);

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files);
    if (files.length === 0) {
      return;
    }
    input.disabled = true;
    uploadAll(files).then(function () {
      window.location.reload();
    }).catch(function (err) {
      console.error('Photo upload failed:', err);
      status.textContent = isQuotaExceeded(err) ? input.dataset.msgQuotaExceeded : input.dataset.msgError;
      input.disabled = false;
    });
  });

  // The server rejects an over-quota upload with {"error": "quota_exceeded"}
  // (HTTP 413, same as the file-too-large case) - a real, permanent
  // rejection, not a network hiccup, so it must not go to isNetworkError()
  // and get queued for later (it would just fail again).
  function isQuotaExceeded(err) {
    return !!(err && typeof err.body === 'string' && err.body.indexOf('quota_exceeded') !== -1);
  }

  function uploadAll(files) {
    return files.reduce(function (chain, file, index) {
      return chain.then(function () {
        status.textContent = input.dataset.msgUploading
          .replace(':current', String(index + 1))
          .replace(':total', String(files.length));
        return uploadOrQueue(file);
      });
    }, Promise.resolve());
  }

  function uploadOrQueue(file) {
    if (!navigator.onLine) {
      return queueFile(file);
    }
    return ChunkedUpload.upload(file, file.name, uploadUrl, csrfField.value).catch(function (err) {
      if (isNetworkError(err)) {
        return queueFile(file);
      }
      throw err;
    });
  }

  function queueFile(file) {
    if (!window.OfflineQueue) {
      throw new Error('offline_unsupported');
    }
    return OfflineQueue.add({
      type: 'photo',
      entryId: entryId,
      uploadUrl: uploadUrl,
      filename: file.name,
      blob: file,
    }).then(function () {
      status.textContent = input.dataset.msgQueued || '';
    });
  }

  // A network-layer failure (offline, DNS, connection dropped) rejects
  // fetch() itself with no HTTP response at all, so ChunkedUpload never got
  // to set .status on the error — as opposed to a real server rejection
  // (422/413/etc.), which always has one. That's the signal to queue
  // instead of just showing an error.
  function isNetworkError(err) {
    return !err || typeof err.status === 'undefined';
  }
});
