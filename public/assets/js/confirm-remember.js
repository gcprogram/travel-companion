/**
 * Like confirm-submit.js's data-confirm, but for repetitive deletions within
 * one page (e.g. removing several photos from a gallery one by one): adds a
 * third "don't ask again for this kind of action" option. The choice is
 * remembered per data-confirm-group in sessionStorage, so it clears with the
 * browser tab and is also explicitly cleared on logout.
 *
 *   <form data-confirm-group="photo_delete" data-confirm-message="..."
 *         data-confirm-yes="..." data-confirm-no="..." data-confirm-all="...">
 */
(function () {
  var STORAGE_PREFIX = 'confirmSkip:';
  var modal = null;
  var pendingForm = null;

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
        form.submit();
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
        form.submit();
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
