/**
 * "Add AI provider" form on /admin/settings (see AdminAiProviderController,
 * AiProviderPresets) - GCToolkit-android's own three-step UX ported here:
 * pick a provider preset (fills the base URL, still editable) -> enter the
 * key -> "fetch available models" hits the endpoint's own /models list
 * server-side (browsers can't call arbitrary third-party APIs directly from
 * this page) and offers the result as a datalist, with manual entry always
 * still possible.
 */
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-ai-provider-form]');
  if (!form) {
    return;
  }

  // RequireAdmin deliberately returns a plain 404 (not a login redirect) for
  // a non-admin/expired session - the admin area shouldn't reveal its own
  // existence to a stranger. That's invisible to a real admin whose own
  // session just timed out though: fetch().json() would otherwise throw on
  // Slim's plain 404 HTML page and fall into the generic "fetch failed"
  // message, leaving no hint that logging in again is all that's needed.
  function readJsonOrSessionExpired(response) {
    if (response.status === 404) {
      var err = new Error('session_expired');
      err.sessionExpired = true;
      throw err;
    }
    return response.json();
  }

  var presetSelect = form.querySelector('[data-ai-provider-preset]');
  var baseUrlInput = form.querySelector('[data-ai-provider-base-url]');
  var keyInput = form.querySelector('[data-ai-provider-key]');
  var fetchButton = form.querySelector('[data-ai-provider-fetch]');
  var fetchStatus = form.querySelector('[data-ai-provider-fetch-status]');
  var modelInput = form.querySelector('[data-ai-provider-model]');
  var modelList = form.querySelector('[data-ai-provider-model-list]');
  var fetchUrl = form.dataset.fetchUrl;
  var csrfToken = form.dataset.csrfToken;

  function applyPresetBaseUrl() {
    var option = presetSelect.options[presetSelect.selectedIndex];
    if (option && option.dataset.baseUrl) {
      baseUrlInput.value = option.dataset.baseUrl;
    }
  }

  presetSelect.addEventListener('change', applyPresetBaseUrl);
  applyPresetBaseUrl();

  function updateFetchButtonState() {
    fetchButton.disabled = !baseUrlInput.value.trim() || !keyInput.value.trim();
  }

  baseUrlInput.addEventListener('input', updateFetchButtonState);
  keyInput.addEventListener('input', updateFetchButtonState);
  updateFetchButtonState();

  fetchButton.addEventListener('click', function () {
    var baseUrl = baseUrlInput.value.trim();
    var apiKey = keyInput.value.trim();
    if (!baseUrl || !apiKey) {
      return;
    }

    fetchButton.disabled = true;
    fetchStatus.textContent = form.dataset.msgFetching;

    var body = new URLSearchParams();
    body.set('_csrf', csrfToken);
    body.set('base_url', baseUrl);
    body.set('api_key', apiKey);
    body.set('provider', presetSelect.value);

    fetch(fetchUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(readJsonOrSessionExpired)
      .then(function (data) {
        if (!data.ok) {
          fetchStatus.textContent = data.error || form.dataset.msgFetchError;
          return;
        }

        modelList.innerHTML = '';
        data.models.forEach(function (id) {
          var option = document.createElement('option');
          option.value = id;
          modelList.appendChild(option);
        });

        if (!modelInput.value.trim() && data.models.length > 0) {
          modelInput.value = data.models[0];
        }

        fetchStatus.textContent = form.dataset.msgFetchFound.replace('%d', String(data.models.length));
      })
      .catch(function (err) {
        fetchStatus.textContent = (err && err.sessionExpired) ? form.dataset.msgSessionExpired : form.dataset.msgFetchError;
      })
      .finally(function () {
        updateFetchButtonState();
      });
  });

  var list = document.querySelector('[data-ai-provider-list]');
  if (!list) {
    return;
  }

  var listCsrfToken = list.dataset.csrfToken;
  var testUrlTemplate = list.dataset.testUrlTemplate;
  var testSearchUrlTemplate = list.dataset.testSearchUrlTemplate;

  function runTest(button, testUrl, onSuccess) {
    var statusEl = button.closest('.ai-provider-list__item').querySelector('[data-ai-provider-test-status]');
    button.disabled = true;
    statusEl.textContent = list.dataset.msgTesting;

    var body = new URLSearchParams();
    body.set('_csrf', listCsrfToken);

    fetch(testUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(readJsonOrSessionExpired)
      .then(function (data) {
        if (!data.ok) {
          statusEl.textContent = data.error || list.dataset.msgTestError;
          return;
        }
        onSuccess(statusEl, data);
      })
      .catch(function (err) {
        statusEl.textContent = (err && err.sessionExpired) ? list.dataset.msgSessionExpired : list.dataset.msgTestError;
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  list.addEventListener('click', function (event) {
    var testButton = event.target.closest('[data-ai-provider-test]');
    if (testButton) {
      var testUrl = testUrlTemplate.replace('__ID__', testButton.dataset.providerId);
      runTest(testButton, testUrl, function (statusEl, data) {
        statusEl.textContent = list.dataset.msgTestOk.replace('%dms', String(data.latencyMs) + ' ms');
      });
      return;
    }

    var searchButton = event.target.closest('[data-ai-provider-test-search]');
    if (searchButton) {
      var testSearchUrl = testSearchUrlTemplate.replace('__ID__', searchButton.dataset.providerId);
      runTest(searchButton, testSearchUrl, function (statusEl, data) {
        var template = data.searched ? list.dataset.msgTestSearchOkSearched : list.dataset.msgTestSearchOkNotSearched;
        statusEl.textContent = template
          .replace('%dms', String(data.latencyMs) + ' ms')
          .replace('%d', String(data.sourceCount));
      });
    }
  });
});
