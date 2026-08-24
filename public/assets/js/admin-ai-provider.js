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

    fetch(fetchUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (response) { return response.json(); })
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
      .catch(function () {
        fetchStatus.textContent = form.dataset.msgFetchError;
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

  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-ai-provider-test]');
    if (!button) {
      return;
    }

    var statusEl = button.closest('.ai-provider-list__item').querySelector('[data-ai-provider-test-status]');
    var testUrl = testUrlTemplate.replace('__ID__', button.dataset.providerId);

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
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) {
          statusEl.textContent = data.error || list.dataset.msgTestError;
          return;
        }
        statusEl.textContent = list.dataset.msgTestOk
          .replace('%dms', String(data.latencyMs) + ' ms');
      })
      .catch(function () {
        statusEl.textContent = list.dataset.msgTestError;
      })
      .finally(function () {
        button.disabled = false;
      });
  });
});
