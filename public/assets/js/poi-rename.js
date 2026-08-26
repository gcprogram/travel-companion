/**
 * Inline rename for an already-confirmed sight/geocache on the /pois list
 * (_pois_fields.php) - the review carousel only ever lets you edit a STAY's
 * name before it's created (its own form field); nothing existed for
 * correcting an existing POI's name (e.g. an untranslated/foreign-script
 * Overpass result) until Stefan asked for it. Click the pencil -> the name
 * becomes a text input; Enter or blur saves, Escape cancels.
 */
document.addEventListener('DOMContentLoaded', function () {
  var list = document.querySelector('[data-poi-list]');
  if (!list) {
    return;
  }

  var csrfToken = list.dataset.csrfToken || '';
  var msgRenameError = list.dataset.msgRenameError || '';

  list.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-poi-rename-trigger]');
    if (!trigger) {
      return;
    }

    var poiId = trigger.dataset.poiId;
    var nameEl = list.querySelector('[data-poi-name][data-poi-id="' + poiId + '"]');
    if (!nameEl || nameEl.querySelector('input')) {
      return;
    }

    var originalName = nameEl.textContent;
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'poi-list__rename-input';
    input.value = originalName;
    input.maxLength = 190;

    var cancelled = false;

    function restore(text) {
      nameEl.textContent = text;
    }

    function save() {
      var newName = input.value.trim();
      if (newName === '' || newName === originalName) {
        restore(originalName);
        return;
      }

      var body = new URLSearchParams();
      body.set('_csrf', csrfToken);
      body.set('name', newName);

      fetch('/pois/' + poiId + '/rename', { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
        .then(function (data) {
          restore(data.name);
        })
        .catch(function () {
          restore(originalName);
          window.alert(msgRenameError);
        });
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      } else if (e.key === 'Escape') {
        cancelled = true;
        restore(originalName);
      }
    });

    input.addEventListener('blur', function () {
      if (!cancelled) {
        save();
      }
    });

    nameEl.textContent = '';
    nameEl.appendChild(input);
    input.focus();
    input.select();
  });
});
