/**
 * Photo upload: every selected file is written to OfflineQueue (IndexedDB)
 * first, then synced via the shared ChunkedUpload helper - not "try a direct
 * upload, fall back to the queue on failure" like it used to be. That older
 * approach only ever durably saved a file once a request had already failed;
 * navigating to another page mid-upload aborts the in-flight request before
 * that failure handler gets a chance to run, so nothing after the file that
 * happened to be "in flight" at that exact moment ever made it anywhere -
 * not uploaded, not queued, just gone. Queuing every file up front first
 * means the same interruption only pauses the sync - offline-gallery.js
 * (which renders queued items and re-triggers sync on page load/reconnect)
 * picks up exactly where it left off, same as a real offline queue.
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-photo-input]');
  var status = document.querySelector('[data-photo-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  if (!input || !status || !csrfField || !window.ChunkedUpload || !window.OfflineQueue) {
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
    status.textContent = input.dataset.msgQueued || '';

    files.reduce(function (chain, file) {
      return chain.then(function () {
        return OfflineQueue.add({
          type: 'photo',
          entryId: entryId,
          uploadUrl: uploadUrl,
          filename: file.name,
          blob: file,
        });
      });
    }, Promise.resolve())
      .then(function () {
        // force: true - this is a direct result of the user picking files
        // just now, same as clicking "sync now"; it shouldn't suddenly be
        // held back by the WiFi-only preference that only governs
        // unprompted background syncing (e.g. on reconnect).
        return OfflineQueue.sync({ csrfToken: csrfField.value, force: true });
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
        } else {
          status.textContent = '';
        }
      })
      .catch(function (err) {
        console.error('Photo upload failed:', err);
        status.textContent = input.dataset.msgError;
        input.disabled = false;
      });
  });
});
