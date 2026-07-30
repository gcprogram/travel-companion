/**
 * Confirmation prompt for destructive forms, e.g.:
 *   <form data-confirm="Really delete this?">
 * Kept as an external listener (instead of inline onsubmit="return confirm(...)")
 * so the app can run under a Content-Security-Policy without 'unsafe-inline'.
 */
document.addEventListener('submit', function (event) {
  var form = event.target.closest('[data-confirm]');
  if (form && !window.confirm(form.dataset.confirm)) {
    event.preventDefault();
  }
});
