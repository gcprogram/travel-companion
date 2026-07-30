/**
 * Photo upload: hands each selected file to the shared ChunkedUpload helper
 * (chunked-upload.js) one at a time. Processing (thumbnails) happens async
 * on the server; we just reload once every file has been uploaded so the
 * gallery picks up the new entries.
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-photo-input]');
  var status = document.querySelector('[data-photo-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  if (!input || !status || !csrfField || !window.ChunkedUpload) {
    return;
  }

  var uploadUrl = input.dataset.uploadUrl;

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
      status.textContent = input.dataset.msgError;
      input.disabled = false;
    });
  });

  function uploadAll(files) {
    return files.reduce(function (chain, file, index) {
      return chain.then(function () {
        status.textContent = input.dataset.msgUploading
          .replace(':current', String(index + 1))
          .replace(':total', String(files.length));
        return ChunkedUpload.upload(file, file.name, uploadUrl, csrfField.value);
      });
    }, Promise.resolve());
  }
});
