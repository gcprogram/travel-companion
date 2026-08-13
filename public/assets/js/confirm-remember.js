/**
 * Like confirm-submit.js's data-confirm, but for repetitive deletions within
 * one page (e.g. removing several photos from a gallery one by one): adds a
 * third "don't ask again for this kind of action" option. The choice is
 * remembered per data-confirm-group in sessionStorage, so it clears with the
 * browser tab and is also explicitly cleared on logout.
 *
 *   <form data-confirm-group="photo_delete" data-confirm-message="..."
 *         data-confirm-yes="..." data-confirm-no="..." data-confirm-all="...">
 *
 * A form additionally marked data-delete-inline submits via fetch() instead
 * of a normal navigation, removing its closest [data-delete-item] ancestor
 * from the page on success rather than reloading - deleting several photos
 * or sights back to back used to reload (and jump to the top of the page)
 * on every single one, most annoyingly once "don't ask again" was picked,
 * since that path submitted the form natively with no interception at all.
 * Falls back to a normal reload if the request didn't succeed (e.g. session
 * expired), so an error is never silently swallowed.
 */
(function () {
  var STORAGE_PREFIX = 'confirmSkip:';
  var modal = null;
  var pendingForm = null;

  function submitForm(form) {
    if (!form.hasAttribute('data-delete-inline')) {
      form.submit();
      return;
    }

    fetch(form.action, {
      method: (form.method || 'POST').toUpperCase(),
      credentials: 'same-origin',
      // Tells the controller to skip its usual redirect+flash (see
      // PhotoController::delete()/PoiController::delete()) - there's no
      // navigation happening here for a flash message to show up on.
      headers: { 'X-Inline-Delete': '1' },
      body: new FormData(form),
    }).then(function (response) {
      if (!response.ok) {
        window.location.reload();
        return;
      }
      var item = form.closest('[data-delete-item]');
      if (item) {
        item.remove();
      } else {
        window.location.reload();
      }
    }).catch(function () {
      window.location.reload();
    });
  }

  function closeModal() {
    if (modal) {
      modal.hidden = true;
    }
    pendingForm = null;
  }

  function buildModal() {
    var el = document.createElement('div');
    el.className = 'confirm-modal';
    el.hidden = true;
    el.innerHTML =
      '<div class="confirm-modal__backdrop"></div>' +
      '<div class="confirm-modal__panel">' +
        '<p class="confirm-modal__message"></p>' +
        '<div class="confirm-modal__actions">' +
          '<button type="button" class="btn btn-ghost" data-confirm-action="no"></button>' +
          '<button type="button" class="btn btn-ghost" data-confirm-action="all"></button>' +
          '<button type="button" class="btn btn-primary" data-confirm-action="yes"></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);

    el.querySelector('.confirm-modal__backdrop').addEventListener('click', closeModal);
    el.querySelector('[data-confirm-action="no"]').addEventListener('click', closeModal);
    el.querySelector('[data-confirm-action="yes"]').addEventListener('click', function () {
      var form = pendingForm;
      closeModal();
      if (form) {
        submitForm(form);
      }
    });
    el.querySelector('[data-confirm-action="all"]').addEventListener('click', function () {
      var form = pendingForm;
      var group = form ? form.dataset.confirmGroup : null;
      closeModal();
      if (group) {
        sessionStorage.setItem(STORAGE_PREFIX + group, '1');
      }
      if (form) {
        submitForm(form);
      }
    });

    return el;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-confirm-group]');
    if (!form) {
      return;
    }
    if (sessionStorage.getItem(STORAGE_PREFIX + form.dataset.confirmGroup) === '1') {
      event.preventDefault();
      submitForm(form);
      return;
    }
    event.preventDefault();

    if (!modal) {
      modal = buildModal();
    }
    pendingForm = form;
    modal.querySelector('.confirm-modal__message').textContent = form.dataset.confirmMessage || '';
    modal.querySelector('[data-confirm-action="no"]').textContent = form.dataset.confirmNo || 'No';
    modal.querySelector('[data-confirm-action="all"]').textContent = form.dataset.confirmAll || 'All';
    modal.querySelector('[data-confirm-action="yes"]').textContent = form.dataset.confirmYes || 'Yes';
    modal.hidden = false;
  });

  document.addEventListener('DOMContentLoaded', function () {
    var logoutForm = document.querySelector('form[action="/logout"]');
    if (!logoutForm) {
      return;
    }
    logoutForm.addEventListener('submit', function () {
      for (var i = sessionStorage.length - 1; i >= 0; i--) {
        var key = sessionStorage.key(i);
        if (key && key.indexOf(STORAGE_PREFIX) === 0) {
          sessionStorage.removeItem(key);
        }
      }
    });
  });
})();
