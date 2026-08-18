/**
 * "Übernehmen" on the AI title/tags suggestion (see AiTripMetaService) -
 * same client-side-only copy as day-entry-form.js's AI summary apply:
 * fills the target field, never saves on its own. data-target names the
 * field id to fill, data-source the id of the element holding the
 * suggested text.
 */
document.addEventListener('DOMContentLoaded', function () {
  var buttons = document.querySelectorAll('[data-ai-apply]');
  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.dataset.target);
      var source = document.getElementById(button.dataset.source);
      if (!target || !source) {
        return;
      }
      target.value = source.textContent.trim();
    });
  });
});
