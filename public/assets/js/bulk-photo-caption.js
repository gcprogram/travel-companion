/**
 * "KI generiere Fotobeschreibung" for the whole trip (Stefan's ask): starts
 * a server-side batch (PhotoController::startCaptionBatch(), one
 * photo.caption job per photo, worked off by the existing minute-cron job
 * queue - see PhotoCaptionHandler for the rate-limit pacing). Deliberately
 * NOT a client-driven loop: the whole point is that it keeps running on
 * the server across page reloads/mobile connection drops, shared fairly
 * across every user's uploads via one persistent rate-limit state.
 *
 * This script only starts/polls/cancels - it never calls the vision API
 * itself. Polling resumes automatically on page load by asking "what's
 * this trip's current batch doing" (captionBatchStatus()), no client-side
 * state needed to survive a reload.
 */
document.addEventListener('DOMContentLoaded', function () {
  var root = document.querySelector('[data-caption-batch]');
  if (!root) {
    return;
  }

  var tripId = root.dataset.tripId;
  var csrfToken = root.dataset.csrfToken;
  var startForm = root.querySelector('[data-caption-batch-start-form]');
  var statusBox = root.querySelector('[data-caption-batch-status]');
  var statusText = root.querySelector('[data-caption-batch-status-text]');
  var progress = root.querySelector('[data-caption-batch-progress]');
  var cancelButton = root.querySelector('[data-caption-batch-cancel]');

  var statusUrl = '/trips/' + tripId + '/photos/caption-batch/status';
  var startUrl = '/trips/' + tripId + '/photos/caption-batch';
  var cancelUrl = '/trips/' + tripId + '/photos/caption-batch/cancel';

  var pollTimer = null;
  var appliedPhotoIds = {};

  function formBody(extra) {
    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    if (extra) {
      Object.keys(extra).forEach(function (key) { body.set(key, extra[key]); });
    }
    return body;
  }

  function applyRecentCaptions(recent) {
    (recent || []).forEach(function (item) {
      if (appliedPhotoIds[item.id]) {
        return;
      }
      appliedPhotoIds[item.id] = true;
      var button = document.querySelector('[data-media-caption-generate][data-caption-url="/photos/' + item.id + '/caption"]');
      var wrap = button ? button.closest('[data-media-caption]') : null;
      var textEl = wrap ? wrap.querySelector('[data-media-caption-text]') : null;
      if (textEl) {
        textEl.textContent = item.caption;
      }
    });
  }

  function render(data) {
    if (!data.active) {
      stopPolling();
      startForm.hidden = false;
      statusBox.hidden = true;
      if (typeof data.total === 'number' && data.total > 0 && (data.done > 0 || data.failed > 0)) {
        statusText.textContent = (root.dataset.msgDone || '')
          .replace(':done', String(data.done))
          .replace(':total', String(data.total))
          .replace(':failed', String(data.failed));
        // Leave the just-finished summary visible for a moment instead of
        // instantly hiding it - the start form still works underneath.
        statusBox.hidden = false;
      } else if (data.total === 0) {
        statusText.textContent = root.dataset.msgNone || '';
        statusBox.hidden = false;
      }
      return;
    }

    startForm.hidden = true;
    statusBox.hidden = false;
    var doneOrFailed = data.done + data.failed;
    progress.max = data.total || 1;
    progress.value = doneOrFailed;
    statusText.textContent = (root.dataset.msgProgress || '')
      .replace(':done', String(doneOrFailed))
      .replace(':total', String(data.total));
    applyRecentCaptions(data.recent);
  }

  function poll() {
    fetch(statusUrl, { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        render(data);
        if (data.active) {
          pollTimer = window.setTimeout(poll, 5000);
        }
      })
      .catch(function () {
        pollTimer = window.setTimeout(poll, 5000);
      });
  }

  function stopPolling() {
    if (pollTimer) {
      window.clearTimeout(pollTimer);
      pollTimer = null;
    }
  }

  startForm.addEventListener('submit', function (event) {
    event.preventDefault();
    var mode = startForm.querySelector('input[name="mode"]:checked').value;

    fetch(startUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formBody({ mode: mode }),
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          statusText.textContent = root.dataset.msgError || '';
          statusBox.hidden = false;
          return;
        }
        if (data.total === 0) {
          statusText.textContent = root.dataset.msgNone || '';
          statusBox.hidden = false;
          return;
        }
        poll();
      })
      .catch(function () {
        statusText.textContent = root.dataset.msgError || '';
        statusBox.hidden = false;
      });
  });

  cancelButton.addEventListener('click', function () {
    cancelButton.disabled = true;
    fetch(cancelUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formBody(),
    })
      .then(function () {
        stopPolling();
        poll();
      })
      .finally(function () {
        cancelButton.disabled = false;
      });
  });

  // Resume watching an already-running batch (e.g. after a reload) without
  // any client-side memory of it.
  poll();
});
