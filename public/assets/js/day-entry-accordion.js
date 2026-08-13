/**
 * Diary entries on the trip page start collapsed to just their header
 * (date/title/mood/rating) - the body (text, hourly weather, photo/video
 * galleries) is fetched from DayEntryController::panel() only on first
 * expand, so a trip with many entries/photos doesn't ship or request all of
 * that media on initial page load.
 *
 * Each toggle also fires a 'day-entry-toggle' event on window with the
 * entry's date, which trip-map.js listens for to switch the map between the
 * full trip and a single day's track/pins - the two files don't know about
 * each other beyond that event.
 */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-day-entry-card]').forEach(function (card) {
    var toggle = card.querySelector('[data-day-entry-toggle]');
    var body = card.querySelector('[data-day-entry-body]');
    if (!toggle || !body) {
      return;
    }

    var entryId = card.dataset.entryId;
    var entryDate = card.dataset.entryDate;
    var loaded = false;

    toggle.addEventListener('click', function () {
      var willOpen = body.hidden;
      body.hidden = !willOpen;
      card.classList.toggle('is-open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

      if (willOpen && !loaded) {
        body.innerHTML = '<p class="day-entry-card__loading">' + (card.dataset.msgLoading || '') + '</p>';
        fetch('/entries/' + entryId + '/panel', { credentials: 'same-origin' })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('HTTP ' + response.status);
            }
            return response.text();
          })
          .then(function (html) {
            body.innerHTML = html;
            loaded = true;
          })
          .catch(function (err) {
            console.error('Diary entry panel fetch failed:', err);
            body.innerHTML = '<p class="empty-state">' + (card.dataset.msgError || '') + '</p>';
          });
      }

      window.dispatchEvent(new CustomEvent('day-entry-toggle', { detail: { date: entryDate, open: willOpen } }));
    });
  });
});
