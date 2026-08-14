/**
 * Generic exclusive accordion: opening a section closes every other section
 * in the same group. Used by the unified trip-edit page (manage.php,
 * Stage 3 of the new trip-creation flow) for its Metadaten/Route/Fotos/
 * Besuchte-Orte sections - deliberately a different behaviour from the
 * diary entry accordion (day-entry-accordion.js), which allows several
 * entries open at once.
 *
 *   <div data-accordion-group>
 *     <div data-accordion-section>
 *       <button data-accordion-toggle aria-expanded="false">...</button>
 *       <div data-accordion-body hidden>...</div>
 *     </div>
 *     ...
 *   </div>
 */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-accordion-group]').forEach(function (group) {
    var sections = group.querySelectorAll('[data-accordion-section]');

    sections.forEach(function (section) {
      var toggle = section.querySelector('[data-accordion-toggle]');
      var body = section.querySelector('[data-accordion-body]');
      if (!toggle || !body) {
        return;
      }

      toggle.addEventListener('click', function () {
        var willOpen = body.hidden;

        sections.forEach(function (other) {
          var otherToggle = other.querySelector('[data-accordion-toggle]');
          var otherBody = other.querySelector('[data-accordion-body]');
          if (!otherToggle || !otherBody) {
            return;
          }
          otherBody.hidden = true;
          other.classList.remove('is-open');
          otherToggle.setAttribute('aria-expanded', 'false');
        });

        if (willOpen) {
          body.hidden = false;
          section.classList.add('is-open');
          toggle.setAttribute('aria-expanded', 'true');
        }
      });
    });
  });
});
