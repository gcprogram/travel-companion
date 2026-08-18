/**
 * Shows the selected filename next to a file input on the Routen-Details
 * upload table (_route_fields.php) - native inputs only ever show their own
 * "no file chosen" browser text, nothing an app template can read/style.
 */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-filename-display]').forEach(function (input) {
    var targetSelector = input.dataset.filenameDisplayTarget;
    var target = targetSelector ? document.querySelector(targetSelector) : null;
    if (!target) {
      return;
    }
    var placeholder = target.textContent;
    input.addEventListener('change', function () {
      target.textContent = input.files && input.files[0] ? input.files[0].name : placeholder;
    });
  });
});
