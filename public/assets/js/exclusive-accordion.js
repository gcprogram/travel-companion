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

// Deep link: /trip/<slug>/manage#share-tokens opens the section that
// contains (or is) the element with that id.
document.addEventListener('DOMContentLoaded', function () {
  if (!window.location.hash) {
    return;
  }
  var target;
  try {
    target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
  } catch (e) {
    return;
  }
  var section = target ? target.closest('[data-accordion-section]') : null;
  var toggle = section ? section.querySelector('[data-accordion-toggle]') : null;
  if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
    toggle.click();
    section.scrollIntoView();
  }
});
