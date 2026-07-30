/**
 * Builds a trip track from a local photo/video folder WITHOUT uploading the
 * files themselves — only the {lat, lng, recordedAt} extracted from each
 * file client-side (photo-geotag.js / video-geotag.js) is sent to the
 * server. Lets a track exist even for media the user never uploads to the
 * site at all.
 *
 * webkitdirectory lets the user pick a whole folder in Chromium/Android;
 * browsers without it just fall back to a normal multi-file picker, which
 * still works fine here (only the resulting FileList matters).
 */
document.addEventListener('DOMContentLoaded', function () {
  var input = document.querySelector('[data-track-folder-input]');
  var status = document.querySelector('[data-track-folder-status]');
  if (!input) {
    return;
  }

  var IMAGE_EXTENSIONS = ['jpg', 'jpeg'];
  var VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v'];

  function extensionOf(name) {
    var dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
  }

  function setStatus(text) {
    if (status) {
      status.textContent = text;
    }
  }

  function extractOne(file) {
    var ext = extensionOf(file.name);
    var extractor = VIDEO_EXTENSIONS.indexOf(ext) !== -1
      ? window.VideoGeotag
      : (IMAGE_EXTENSIONS.indexOf(ext) !== -1 ? window.PhotoGeotag : null);
    if (!extractor) {
      return Promise.resolve(null);
    }
    return extractor.extract(file).catch(function () { return null; });
  }

  input.addEventListener('change', function () {
    var files = Array.prototype.filter.call(input.files, function (f) {
      var ext = extensionOf(f.name);
      return IMAGE_EXTENSIONS.indexOf(ext) !== -1 || VIDEO_EXTENSIONS.indexOf(ext) !== -1;
    });

    if (files.length === 0) {
      setStatus(input.dataset.msgNoMedia);
      return;
    }

    input.disabled = true;
    var scanned = 0;
    var points = [];

    setStatus(input.dataset.msgScanning + ' (0/' + files.length + ')');

    Promise.all(files.map(function (file) {
      return extractOne(file).then(function (result) {
        scanned++;
        setStatus(input.dataset.msgScanning + ' (' + scanned + '/' + files.length + ')');
        if (result && result.lat != null && result.lng != null && result.recordedAt) {
          points.push({ lat: result.lat, lng: result.lng, recordedAt: result.recordedAt });
        }
      });
    }))
      .then(function () {
        if (points.length < 2) {
          setStatus(input.dataset.msgNoPoints);
          input.disabled = false;
          return null;
        }

        points.sort(function (a, b) {
          return a.recordedAt < b.recordedAt ? -1 : (a.recordedAt > b.recordedAt ? 1 : 0);
        });

        setStatus(input.dataset.msgUploading);
        return fetch(input.dataset.submitUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ _csrf: input.dataset.csrfToken, points: points }),
        }).then(function (response) {
          if (!response.ok) {
            throw new Error('submit_failed: HTTP ' + response.status);
          }
          window.location.reload();
        });
      })
      .catch(function () {
        setStatus(input.dataset.msgError);
        input.disabled = false;
      });
  });
});
