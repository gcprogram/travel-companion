/**
 * Shared chunked upload helper: slices a Blob and uploads the pieces
 * sequentially so a single request never exceeds the host's
 * post_max_size, no matter how large the reassembled file is.
 * Used by photo-upload.js and video-upload.js.
 */
window.ChunkedUpload = (function () {
  const CHUNK_SIZE = 1024 * 1024; // 1 MB
  // A screen lock/backgrounded tab can suspend an in-flight fetch()
  // indefinitely (no browser-level timeout of its own) - without this, one
  // stalled chunk would hang the whole sequential chain forever, with
  // nothing left to ever retry it (see offline-queue.js's visibilitychange
  // resume, which needs a stuck request to actually fail before a fresh
  // sync() attempt can safely start). 45s is generous for a 1MB chunk even
  // on a poor connection, short enough that a genuine stall surfaces well
  // within one job-worker-style retry cycle.
  const CHUNK_TIMEOUT_MS = 45000;

  /**
   * @param {Blob} blob
   * @param {string} filename
   * @param {string} uploadUrl
   * @param {string} csrfToken
   * @param {{onProgress?: (fraction: number) => void, extraFields?: Record<string, string>}} [options]
   * @returns {Promise<any>} the JSON response from the final chunk
   */
  function upload(blob, filename, uploadUrl, csrfToken, options) {
    const onProgress = (options && options.onProgress) || function () {};
    const extraFields = (options && options.extraFields) || {};
    const uploadId = crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random();
    const chunkCount = Math.max(1, Math.ceil(blob.size / CHUNK_SIZE));

    let chain = Promise.resolve();
    let lastResult = null;

    for (let i = 0; i < chunkCount; i++) {
      (function (chunkIndex) {
        chain = chain.then(function () {
          const start = chunkIndex * CHUNK_SIZE;
          const chunk = blob.slice(start, start + CHUNK_SIZE);
          const formData = new FormData();
          formData.append('_csrf', csrfToken);
          formData.append('upload_id', uploadId);
          formData.append('chunk_index', String(chunkIndex));
          formData.append('chunk_count', String(chunkCount));
          formData.append('filename', filename);
          Object.keys(extraFields).forEach(function (key) {
            formData.append(key, extraFields[key]);
          });
          formData.append('chunk', chunk, filename);

          var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
          var timedOut = false;
          var timer = controller ? setTimeout(function () {
            timedOut = true;
            controller.abort();
          }, CHUNK_TIMEOUT_MS) : null;

          return fetch(uploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
          })
            .finally(function () { clearTimeout(timer); })
            .catch(function (err) {
              if (timedOut) {
                var timeoutErr = new Error('upload_timeout');
                timeoutErr.timedOut = true;
                throw timeoutErr;
              }
              throw err;
            })
            .then(function (response) {
              if (response.redirected) {
                // The session expired (not just the CSRF token): RequireLogin
                // sent us to /login, and fetch followed that transparently to
                // a 200 HTML page - not the JSON our controller would return.
                // Surfaced distinctly so callers (offline-queue.js) can stop
                // and prompt for re-login instead of silently retrying a
                // request that can never succeed until then.
                var authErr = new Error('auth_required');
                authErr.authRequired = true;
                throw authErr;
              }
              if (!response.ok) {
                // Read the body for diagnostics even though we're about to throw —
                // {"error": "..."} from our controllers, or an HTML error page if
                // something failed before it even got there (e.g. a 419/CSRF or a
                // proxy-level rejection).
                return response.text().then(function (text) {
                  var err = new Error('upload_failed: HTTP ' + response.status);
                  err.status = response.status;
                  err.body = text;
                  throw err;
                });
              }
              return response.json();
            })
            .then(function (json) {
              onProgress((chunkIndex + 1) / chunkCount);
              lastResult = json;
            });
        });
      })(i);
    }

    return chain.then(function () { return lastResult; });
  }

  return { upload: upload };
})();
