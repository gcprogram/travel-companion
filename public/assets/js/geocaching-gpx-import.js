/**
 * Geocaching GPX/ZIP import: intercepts the form and uploads the (often
 * large, e.g. a full "myfinds" export) GPX/ZIP via ChunkedUpload instead of
 * a single POST - a big single-shot upload can exceed the host's
 * post_max_size, which silently empties $_POST/$_FILES and makes
 * CsrfMiddleware bounce the request as if the session had expired, even
 * though the session is fine. Field notes (always small) ride along as a
 * base64 extra field on every chunk rather than as a second real upload, to
 * keep the server-side chunk endpoint single-file.
 *
 * Also remembers the last-typed GC username in localStorage, same as
 * before - never sent anywhere except with this form's own submit, used
 * only to match against the GPX's own found logs.
 */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-geocaching-gpx-form]');
  var usernameInput = document.querySelector('[data-geocaching-gpx-username]');
  var gpxInput = document.getElementById('geocaching-gpx-file');
  var notesInput = document.getElementById('geocaching-field-notes');
  var csrfField = form ? form.querySelector('input[name="_csrf"]') : null;

  if (!form || !usernameInput || !window.localStorage) {
    return;
  }

  var STORAGE_KEY = 'geocachingGpxUsername';
  var remembered = window.localStorage.getItem(STORAGE_KEY);
  if (remembered) {
    usernameInput.value = remembered;
  }

  if (!gpxInput || !csrfField || !window.ChunkedUpload) {
    // No chunked-upload support available - fall back to the plain form
    // submit (small files still work fine as a single POST).
    form.addEventListener('submit', function () {
      if (usernameInput.value.trim()) {
        window.localStorage.setItem(STORAGE_KEY, usernameInput.value.trim());
      }
    });
    return;
  }

  var statusEl = document.getElementById('geocaching-gpx-status');

  function readAsBase64(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () {
        var result = reader.result; // "data:<mime>;base64,<data>"
        var comma = result.indexOf(',');
        resolve(comma >= 0 ? result.slice(comma + 1) : '');
      };
      reader.onerror = function () { reject(reader.error); };
      reader.readAsDataURL(file);
    });
  }

  form.addEventListener('submit', function (event) {
    var file = gpxInput.files[0];
    if (!file) {
      return; // Let the native "required" validation handle the empty case.
    }
    event.preventDefault();

    if (usernameInput.value.trim()) {
      window.localStorage.setItem(STORAGE_KEY, usernameInput.value.trim());
    }

    var submitButtons = form.querySelectorAll('button[type="submit"]');
    submitButtons.forEach(function (btn) { btn.disabled = true; });
    if (statusEl) {
      statusEl.hidden = false;
      statusEl.textContent = statusEl.dataset.msgUploading || 'Uploading...';
    }

    var notesFile = notesInput && notesInput.files[0] ? notesInput.files[0] : null;
    var notesPromise = notesFile ? readAsBase64(notesFile) : Promise.resolve('');

    notesPromise
      .then(function (notesBase64) {
        var extraFields = { gc_username: usernameInput.value.trim() };
        if (notesBase64) {
          extraFields.field_notes_base64 = notesBase64;
        }
        var uploadUrl = form.action.replace(/\/?$/, '') + '/chunk';
        return window.ChunkedUpload.upload(file, file.name, uploadUrl, csrfField.value, {
          extraFields: extraFields,
          onProgress: function (fraction) {
            if (statusEl) {
              statusEl.textContent = (statusEl.dataset.msgUploading || 'Uploading...') + ' ' + Math.round(fraction * 100) + '%';
            }
          },
        });
      })
      .then(function (json) {
        if (json && json.redirect) {
          window.location.href = json.redirect;
          return;
        }
        window.location.reload();
      })
      .catch(function (err) {
        console.error('Geocaching GPX upload failed:', err);
        submitButtons.forEach(function (btn) { btn.disabled = false; });
        if (statusEl) {
          statusEl.textContent = statusEl.dataset.msgError || 'Upload failed.';
        }
      });
  });
});
