<?php
/** @var array<string, string> $values */
/** @var bool $placesApiKeyConfigured */
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

  <h2><?= e(t('admin.settings_ai_heading')) ?></h2>
  <p class="field-hint"><?= e(t('admin.settings_ai_hint')) ?></p>

  <div class="field">
    <label for="ai_base_url"><?= e(t('admin.settings_ai_base_url_label')) ?></label>
    <input type="url" id="ai_base_url" name="ai_base_url" value="<?= e($values['ai.base_url']) ?>">
    <p class="field-hint"><?= e(t('admin.settings_ai_base_url_hint')) ?></p>
  </div>

  <div class="field">
    <label for="ai_model"><?= e(t('admin.settings_ai_model_label')) ?></label>
    <input type="text" id="ai_model" name="ai_model" value="<?= e($values['ai.model']) ?>">
  </div>

  <div class="field">
    <label for="ai_api_key"><?= e(t('admin.settings_ai_key_label')) ?></label>
    <input type="password" id="ai_api_key" name="ai_api_key" autocomplete="off"
           placeholder="<?= $aiApiKeyConfigured ? e(t('admin.settings_ai_key_configured')) : '' ?>">
    <p class="field-hint"><?= e(t('admin.settings_ai_key_hint')) ?></p>
    <?php if ($aiApiKeyConfigured): ?>
      <label>
        <input type="checkbox" name="ai_api_key_clear" value="1">
        <?= e(t('admin.settings_ai_key_clear')) ?>
      </label>
    <?php endif; ?>
  </div>

  <button type="submit" class="btn btn-primary"><?= e(t('admin.save')) ?></button>
</form>

<h2><?= e(t('admin.settings_maintenance_heading')) ?></h2>
<form method="post" action="/admin/settings/clear-geocode-cache">
  <?= $csrf->field() ?>
  <button type="submit" class="btn btn-ghost"><?= e(t('admin.settings_clear_geocode_cache')) ?></button>
  <p class="field-hint"><?= e(t('admin.settings_clear_geocode_cache_hint')) ?></p>
</form>
