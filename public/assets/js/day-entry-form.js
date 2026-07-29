/**
 * Location capture for the diary entry form via the browser Geolocation API.
 * Without JS, or without permission, the form still works, just without GPS data.
 * User-facing strings come from data-msg-* attributes set by the (translated) template.
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
      status.textContent = button.dataset.msgUnsupported;
      return;
    }

    status.textContent = button.dataset.msgLocating;
    navigator.geolocation.getCurrentPosition(
      function (position) {
        var lat = position.coords.latitude.toFixed(6);
        var lng = position.coords.longitude.toFixed(6);
        latInput.value = lat;
        lngInput.value = lng;
        status.textContent = button.dataset.msgCaptured
          .replace(':lat', lat)
          .replace(':lng', lng);
      },
      function (error) {
        status.textContent = button.dataset.msgError.replace(':error', error.message);
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });
});
