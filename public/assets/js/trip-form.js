/**
 * Progressive Enhancement fürs Reiseformular: Stationen-Zeilen
 * hinzufügen/entfernen, ohne Server-Rundtrip. Ohne JS funktioniert
 * das Formular weiterhin (die Server-Validierung ignoriert leere Zeilen).
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
