/**
 * Chunked photo upload: slices each selected file into small pieces and
 * uploads them one at a time, so a single request never exceeds the host's
 * post_max_size limit even though the reassembled photo can be larger.
 * Processing (thumbnails) happens async on the server; we just reload once
 * every file has been uploaded so the gallery picks up the new entries.
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-photo-input]');
  var status = document.querySelector('[data-photo-status]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  if (!input || !status || !csrfField) {
    return;
  }

  var CHUNK_SIZE = 1024 * 1024; // 1 MB
  var uploadUrl = input.dataset.uploadUrl;

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files);
    if (files.length === 0) {
      return;
    }
    input.disabled = true;
    uploadAll(files).then(function () {
      window.location.reload();
    });
  });

  function uploadAll(files) {
    return files.reduce(function (chain, file, index) {
      return chain.then(function () {
        status.textContent = input.dataset.msgUploading
          .replace(':current', String(index + 1))
          .replace(':total', String(files.length));
        return uploadFile(file);
      });
    }, Promise.resolve());
  }

  function uploadFile(file) {
    var uploadId = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random());
    var chunkCount = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

    var chain = Promise.resolve();
    for (var i = 0; i < chunkCount; i++) {
      (function (chunkIndex) {
        chain = chain.then(function () {
          var start = chunkIndex * CHUNK_SIZE;
          var chunk = file.slice(start, start + CHUNK_SIZE);
          return uploadChunk(chunk, uploadId, chunkIndex, chunkCount, file.name);
        });
      })(i);
    }
    return chain;
  }

  function uploadChunk(chunk, uploadId, chunkIndex, chunkCount, filename) {
    var formData = new FormData();
    formData.append('_csrf', csrfField.value);
    formData.append('upload_id', uploadId);
    formData.append('chunk_index', String(chunkIndex));
    formData.append('chunk_count', String(chunkCount));
    formData.append('filename', filename);
    formData.append('chunk', chunk, filename);

    return fetch(uploadUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('upload failed');
        }
        return response.json();
      })
      .catch(function (err) {
        status.textContent = input.dataset.msgError;
        input.disabled = false;
        throw err;
      });
  }
});
