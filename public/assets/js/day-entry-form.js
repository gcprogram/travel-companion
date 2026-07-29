/**
 * Standort-Erfassung fürs Tagebuch-Formular über die Browser-Geolocation-API.
 * Ohne JS bzw. ohne Erlaubnis bleibt das Formular nutzbar, nur ohne GPS-Daten.
 */
document.addEventListener('DOMContentLoaded', function () {
  var button = document.querySelector('[data-geolocate]');
  var status = document.querySelector('[data-geolocate-status]');
  var latInput = document.getElementById('lat');
  var lngInput = document.getElementById('lng');

  if (!button || !status || !latInput || !lngInput) {
    return;
  }

  button.addEventListener('click', function () {
    if (!('geolocation' in navigator)) {
      status.textContent = 'Standorterfassung wird von diesem Browser nicht unterstützt.';
      return;
    }

    status.textContent = 'Standort wird ermittelt …';
    navigator.geolocation.getCurrentPosition(
      function (position) {
        var lat = position.coords.latitude.toFixed(6);
        var lng = position.coords.longitude.toFixed(6);
        latInput.value = lat;
        lngInput.value = lng;
        status.textContent = 'Erfasst: ' + lat + ', ' + lng;
      },
      function (error) {
        status.textContent = 'Standort konnte nicht ermittelt werden (' + error.message + ').';
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });
});
