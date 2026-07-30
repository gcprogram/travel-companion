/**
 * Video upload: compresses the selected file client-side (video-compress.js,
 * WebCodecs) before handing the result to the shared ChunkedUpload helper.
 * Browsers without WebCodecs support get the file input disabled with an
 * explanatory message pointing at the YouTube-link form instead.
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
        return ChunkedUpload.upload(result.blob, 'video.mp4', uploadUrl, csrfField.value, {
          onProgress: function (fraction) {
            status.textContent = input.dataset.msgUploading + ' ' + Math.round(fraction * 100) + '%';
          },
          extraFields: extraFields,
        });
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
        } else {
          status.textContent = input.dataset.msgError;
        }
        input.disabled = false;
      });
  });
});
