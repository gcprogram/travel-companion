<?php
/** @var array<string, string> $values */
/** @var bool $placesApiKeyConfigured */
/** @var bool $translateApiKeyConfigured */
/** @var bool $aiApiKeyConfigured */
?>

<h1><?= e(t('admin.settings_title')) ?></h1>
<p class="page-actions">
  <a href="/admin/users">&larr; <?= e(t('admin.back_to_users')) ?></a>
</p>

<form class="auth-form" method="post" action="/admin/settings">
  <?= $csrf->field() ?>

  <h2><?= e(t('admin.settings_registration_heading')) ?></h2>

  <div class="field">
    <label for="registration_mode"><?= e(t('admin.settings_registration_mode')) ?></label>
    <select id="registration_mode" name="registration_mode">
      <option value="email" <?= $values['registration.mode'] === 'email' ? 'selected' : '' ?>>
        <?= e(t('admin.settings_mode_email')) ?>
      </option>
      <option value="admin_approval" <?= $values['registration.mode'] === 'admin_approval' ? 'selected' : '' ?>>
        <?= e(t('admin.settings_mode_admin_approval')) ?>
      </option>
    </select>
  </div>

  <div class="field">
    <label for="token_ttl_seconds"><?= e(t('admin.settings_token_ttl')) ?></label>
    <input type="number" id="token_ttl_seconds" name="token_ttl_seconds" min="60" step="1"
           value="<?= e($values['registration.token_ttl_seconds']) ?>">
    <p class="field-hint"><?= e(t('admin.settings_token_ttl_hint')) ?></p>
  </div>

  <h2><?= e(t('admin.settings_quota_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_quota_hint')) ?></p>

  <div class="field">
    <label for="quota_storage_user"><?= e(t('admin.settings_quota_storage_user')) ?></label>
    <input type="number" id="quota_storage_user" name="quota_storage_user" min="0" step="1"
           value="<?= e((string) round(((int) $values['quota.storage.user']) / 1024 / 1024, 1)) ?>">
  </div>

  <div class="field">
    <label for="quota_storage_ai_user"><?= e(t('admin.settings_quota_storage_ai_user')) ?></label>
    <input type="number" id="quota_storage_ai_user" name="quota_storage_ai_user" min="0" step="1"
           value="<?= e((string) round(((int) $values['quota.storage.ai_user']) / 1024 / 1024, 1)) ?>">
  </div>

  <div class="field">
    <label for="quota_storage_manager"><?= e(t('admin.settings_quota_storage_manager')) ?></label>
    <input type="number" id="quota_storage_manager" name="quota_storage_manager" min="0" step="1"
           value="<?= e((string) round(((int) $values['quota.storage.manager']) / 1024 / 1024, 1)) ?>">
  </div>

  <div class="field">
    <label for="quota_ai_ai_user"><?= e(t('admin.settings_quota_ai_ai_user')) ?></label>
    <input type="number" id="quota_ai_ai_user" name="quota_ai_ai_user" min="0" step="1"
           value="<?= e($values['quota.ai.ai_user']) ?>">
  </div>

  <div class="field">
    <label for="quota_ai_manager"><?= e(t('admin.settings_quota_ai_manager')) ?></label>
    <input type="number" id="quota_ai_manager" name="quota_ai_manager" min="0" step="1"
           value="<?= e($values['quota.ai.manager']) ?>">
  </div>

  <h2><?= e(t('admin.settings_poi_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_poi_hint')) ?></p>

  <div class="field">
    <label for="poi_search_radius"><?= e(t('admin.settings_poi_search_radius')) ?></label>
    <input type="number" id="poi_search_radius" name="poi_search_radius" min="50" max="5000" step="50"
           value="<?= e($values['poi.search_radius_meters']) ?>">
    <p class="field-hint"><?= e(t('admin.settings_poi_search_radius_hint')) ?></p>
  </div>

  <div class="field">
    <label for="poi_photo_match"><?= e(t('admin.settings_poi_photo_match')) ?></label>
    <input type="number" id="poi_photo_match" name="poi_photo_match" min="10" max="2000" step="10"
           value="<?= e($values['poi.photo_match_meters']) ?>">
    <p class="field-hint"><?= e(t('admin.settings_poi_photo_match_hint')) ?></p>
  </div>

  <div class="field">
    <label for="poi_geocache_import_radius"><?= e(t('admin.settings_geocache_import_radius')) ?></label>
    <input type="number" id="poi_geocache_import_radius" name="poi_geocache_import_radius" min="100" max="50000" step="100"
           value="<?= e($values['poi.geocache_import_radius_meters']) ?>">
    <p class="field-hint"><?= e(t('admin.settings_geocache_import_radius_hint')) ?></p>
  </div>

  <fieldset class="field">
    <legend><?= e(t('admin.settings_poi_categories')) ?></legend>
    <?php $enabled = array_map('trim', explode(',', $values['poi.categories'])); ?>
    <div class="poi-search-form__categories">
      <?php foreach ($searchableCategories as $category): ?>
        <label>
          <input type="checkbox" name="poi_categories[]" value="<?= e($category) ?>"
            <?= in_array($category, $enabled, true) ? 'checked' : '' ?>>
          <?= e(t('trip.map.category.' . $category)) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <h2><?= e(t('admin.settings_places_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_places_hint')) ?></p>

  <div class="field">
    <label for="google_places_api_key"><?= e(t('admin.settings_places_key_label')) ?></label>
    <input type="password" id="google_places_api_key" name="google_places_api_key" autocomplete="off"
           placeholder="<?= $placesApiKeyConfigured ? e(t('admin.settings_places_key_configured')) : '' ?>">
    <p class="field-hint"><?= e(t('admin.settings_places_key_hint')) ?></p>
    <?php if ($placesApiKeyConfigured): ?>
      <label>
        <input type="checkbox" name="google_places_api_key_clear" value="1">
        <?= e(t('admin.settings_places_key_clear')) ?>
      </label>
    <?php endif; ?>
  </div>

  <h2><?= e(t('admin.settings_translate_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_translate_hint')) ?></p>

  <div class="field">
    <label for="google_translate_api_key"><?= e(t('admin.settings_translate_key_label')) ?></label>
    <input type="password" id="google_translate_api_key" name="google_translate_api_key" autocomplete="off"
           placeholder="<?= $translateApiKeyConfigured ? e(t('admin.settings_translate_key_configured')) : '' ?>">
    <p class="field-hint"><?= e(t('admin.settings_translate_key_hint')) ?></p>
    <?php if ($translateApiKeyConfigured): ?>
      <label>
        <input type="checkbox" name="google_translate_api_key_clear" value="1">
        <?= e(t('admin.settings_translate_key_clear')) ?>
      </label>
    <?php endif; ?>
  </div>

  <h2><?= e(t('admin.settings_ai_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_ai_hint')) ?></p>

  <div class="field">
    <label for="ai_slot_main"><?= e(t('admin.settings_ai_slot_main_label')) ?></label>
    <select id="ai_slot_main" name="ai_slot_main">
      <option value="0"><?= e(t('admin.settings_ai_slot_none')) ?></option>
      <?php foreach ($aiProviders as $config): ?>
        <option value="<?= (int) $config['id'] ?>" <?= $aiSlotMain === (int) $config['id'] ? 'selected' : '' ?>>
          <?= e($config['label']) ?> (<?= e($config['model']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <p class="field-hint"><?= e(t('admin.settings_ai_slot_main_hint')) ?></p>
  </div>

  <button type="submit" class="btn btn-primary"><?= e(t('admin.save')) ?></button>
</form>

<h2><?= e(t('admin.settings_ai_providers_heading')) ?></h2>
<p class="field-hint"><?= e(t('admin.settings_ai_providers_hint')) ?></p>

<?php if ($aiProviders !== []): ?>
  <ul class="ai-provider-list">
    <?php foreach ($aiProviders as $config): ?>
      <li class="ai-provider-list__item">
        <div>
          <strong><?= e($config['label']) ?></strong>
          <span class="field-hint"><?= e($config['base_url']) ?> · <?= e($config['model']) ?></span>
        </div>
        <form method="post" action="/admin/settings/ai-providers/<?= (int) $config['id'] ?>/delete"
              data-confirm="<?= e(t('admin.settings_ai_provider_delete_confirm', ['label' => $config['label']])) ?>">
          <?= $csrf->field() ?>
          <button type="submit" class="btn btn-ghost btn-small"><?= e(t('admin.settings_ai_provider_delete')) ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<form class="auth-form" method="post" action="/admin/settings/ai-providers" data-ai-provider-form
      data-fetch-url="/admin/settings/ai-providers/fetch-models"
      data-csrf-token="<?= e($csrf->token()) ?>"
      data-msg-fetching="<?= e(t('admin.settings_ai_fetch_fetching')) ?>"
      data-msg-fetch-error="<?= e(t('admin.settings_ai_fetch_error')) ?>"
      data-msg-fetch-found="<?= e(t('admin.settings_ai_fetch_found')) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="ai_provider_label"><?= e(t('admin.settings_ai_provider_label_label')) ?></label>
    <input type="text" id="ai_provider_label" name="label" data-ai-provider-label required>
  </div>

  <div class="field">
    <label for="ai_provider_preset"><?= e(t('admin.settings_ai_provider_preset_label')) ?></label>
    <select id="ai_provider_preset" name="provider" data-ai-provider-preset>
      <?php foreach ($aiProviderPresets as $key => $preset): ?>
        <option value="<?= e($key) ?>" data-base-url="<?= e($preset['baseUrl']) ?>">
          <?= e($preset['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="field">
    <label for="ai_provider_base_url"><?= e(t('admin.settings_ai_provider_base_url_label')) ?></label>
    <input type="url" id="ai_provider_base_url" name="base_url" data-ai-provider-base-url required>
  </div>

  <div class="field">
    <label for="ai_provider_key"><?= e(t('admin.settings_ai_provider_key_label')) ?></label>
    <input type="password" id="ai_provider_key" name="api_key" autocomplete="off" data-ai-provider-key required>
  </div>

  <p class="field-hint">
    <button type="button" class="btn btn-ghost" data-ai-provider-fetch><?= e(t('admin.settings_ai_fetch_button')) ?></button>
    <span data-ai-provider-fetch-status></span>
  </p>

  <div class="field">
    <label for="ai_provider_model"><?= e(t('admin.settings_ai_provider_model_label')) ?></label>
    <input type="text" id="ai_provider_model" name="model" data-ai-provider-model required
           list="ai-provider-model-list"
           placeholder="<?= e(t('admin.settings_ai_provider_model_placeholder')) ?>">
    <datalist id="ai-provider-model-list" data-ai-provider-model-list></datalist>
  </div>

  <button type="submit" class="btn btn-primary"><?= e(t('admin.settings_ai_provider_add')) ?></button>
</form>

<script src="/assets/js/admin-ai-provider.js"></script>

<h2><?= e(t('admin.settings_maintenance_heading')) ?></h2>
<form method="post" action="/admin/settings/clear-geocode-cache">
  <?= $csrf->field() ?>
  <button type="submit" class="btn btn-ghost"><?= e(t('admin.settings_clear_geocode_cache')) ?></button>
  <p class="field-hint"><?= e(t('admin.settings_clear_geocode_cache_hint')) ?></p>
</form>

<form method="post" action="/admin/settings/clear-poi-translation-cache">
  <?= $csrf->field() ?>
  <button type="submit" class="btn btn-ghost"><?= e(t('admin.settings_clear_poi_translation_cache')) ?></button>
  <p class="field-hint"><?= e(t('admin.settings_clear_poi_translation_cache_hint')) ?></p>
</form>
