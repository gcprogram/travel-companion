/**
 * "Detailed" diary view (trip.show.detail_view_toggle): shows each photo's/
 * video's caption (vision-AI generated, or the AI MediaAnalyzer EXIF import
 * if no vision run has happened yet - both just live in the same `caption`
 * column, see migration 0035/PhotoRepository::updateVisionCaption()) plus a
 * button to (re)generate it via the configured Vision-AI provider.
 *
 * The toggle only flips a class on .day-entry-list (data-day-entry-list) -
 * never replaced, unlike individual .day-entry-card__body panels which load
 * lazily via innerHTML (day-entry-accordion.js) - so CSS alone makes newly
 * loaded panels respect the current state, no re-binding needed. State
 * persists in localStorage so it survives a reload.
 *
 * The "generate" button is a delegated listener on the same stable list
 * element for the same reason - it has to work on captions inside panels
 * that don't exist in the DOM yet when this script runs.
 */
document.addEventListener('DOMContentLoaded', function () {
  var list = document.querySelector('[data-day-entry-list]');
  var toggle = document.querySelector('[data-day-entry-detail-toggle]');
  if (!list || !toggle) {
    return;
  }

  var STORAGE_KEY = 'dayEntryDetailView';

  function applyState(detailed) {
    list.classList.toggle('day-entry-list--detail', detailed);
    toggle.checked = detailed;
  }

  applyState(window.localStorage && window.localStorage.getItem(STORAGE_KEY) === '1');

  toggle.addEventListener('change', function () {
    applyState(toggle.checked);
    if (window.localStorage) {
      window.localStorage.setItem(STORAGE_KEY, toggle.checked ? '1' : '0');
    }
  });

  var csrfToken = list.dataset.csrfToken;

  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-media-caption-generate]');
    if (!button) {
      return;
    }

    var wrap = button.closest('[data-media-caption]');
    var textEl = wrap ? wrap.querySelector('[data-media-caption-text]') : null;
    if (!textEl) {
      return;
    }

    var previousText = textEl.textContent;
    button.disabled = true;
    textEl.textContent = list.dataset.msgGenerating || '';

    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);

    fetch(button.dataset.captionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          textEl.textContent = data.error || list.dataset.msgCaptionError || '';
          return;
        }
        textEl.textContent = data.caption;
      })
      .catch(function () {
        textEl.textContent = list.dataset.msgCaptionError || previousText;
      })
      .finally(function () {
        button.disabled = false;
      });
  });
});
