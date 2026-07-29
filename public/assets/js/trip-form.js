/**
 * Progressive enhancement for the trip form: add/remove station rows
 * without a server round-trip. Without JS the form still works
 * (server-side validation ignores empty rows).
 */
document.addEventListener('DOMContentLoaded', function () {
  var list = document.querySelector('[data-station-list]');
  var addBtn = document.querySelector('[data-station-add]');
  var template = document.querySelector('[data-station-template]');

  if (!list || !addBtn || !template) {
    return;
  }

  addBtn.addEventListener('click', function () {
    var clone = template.content.cloneNode(true);
    list.appendChild(clone);
  });

  list.addEventListener('click', function (event) {
    var removeBtn = event.target.closest('[data-station-remove]');
    if (!removeBtn) {
      return;
    }
    var row = removeBtn.closest('.station-row');
    if (row) {
      row.remove();
    }
  });
});
