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
 *
 * The header's weather badge (data-weather-summary-toggle) sits inside this
 * same clickable header, so a click on it used to just collapse/expand the
 * whole card like any other part of the header - confusing when the intent
 * was "show me the hourly weather", not "close this entry". Clicking it
 * now always ends with the card open AND its hourly weather detail
 * (.weather-hours) expanded, never collapsed.
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

    function openWeatherHours() {
      var details = body.querySelector('.weather-hours');
      if (details) {
        details.open = true;
        details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }

    toggle.addEventListener('click', function (event) {
      var weatherClick = event.target.closest('[data-weather-summary-toggle]') !== null;

      if (weatherClick && !body.hidden) {
        // Already open - a weather click should never close it, just
        // (re-)reveal the hourly detail.
        event.stopPropagation();
        openWeatherHours();
        return;
      }

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
            if (weatherClick) {
              openWeatherHours();
            }
          })
          .catch(function (err) {
            console.error('Diary entry panel fetch failed:', err);
            body.innerHTML = '<p class="empty-state">' + (card.dataset.msgError || '') + '</p>';
          });
      } else if (willOpen && weatherClick) {
        openWeatherHours();
      }

      window.dispatchEvent(new CustomEvent('day-entry-toggle', { detail: { date: entryDate, open: willOpen } }));
    });
  });
});
