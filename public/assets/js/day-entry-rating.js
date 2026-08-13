/**
 * Star ratings on a diary entry (see DayEntryController::rate()) live
 * inside the lazily-fetched panel (day-entry-accordion.js), so this listens
 * on document via delegation rather than binding on load. Picking a star
 * submits immediately via fetch(); the response carries the fresh
 * average/count, so the summary line updates in place without a reload.
 */
document.addEventListener('change', function (event) {
  var input = event.target.closest('[data-rating-input]');
  if (!input) {
    return;
  }
  var form = input.closest('[data-rating-form]');
  var widget = input.closest('[data-rating-widget]');
  if (!form || !widget) {
    return;
  }

  fetch(form.action, {
    method: 'POST',
    credentials: 'same-origin',
    body: new FormData(form),
  })
    .then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function (data) {
      var summary = widget.querySelector('[data-rating-summary]');
      if (!summary) {
        return;
      }
      if (data.average === null) {
        summary.textContent = widget.dataset.msgNone || '';
        return;
      }
      var rounded = Math.round(data.average);
      var stars = '★'.repeat(rounded) + '☆'.repeat(5 - rounded);
      var countText = (widget.dataset.msgCountTemplate || '').replace(':count', String(data.count));
      summary.textContent = stars + ' ' + data.average.toFixed(1) + ' (' + countText + ')';
    })
    .catch(function (err) {
      console.error('Rating submit failed:', err);
    });
});
