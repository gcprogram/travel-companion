<?php
/**
 * Trip-level photo/video upload widget. Shared between the standalone
 * wizard step (photos.php) and the "Fotos" accordion section on the
 * unified edit page (manage.php) - expects $trip, $canEdit (same as
 * photos.php's own controller, TripPhotoController).
 */
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
?>

<?php if ($canEdit): ?>
  <?= $csrf->field() ?>

  <div class="sync-status" data-sync-status hidden
       data-msg-pending-one="<?= e(t('offline.sync_pending_one')) ?>"
       data-msg-pending-many="<?= e(t('offline.sync_pending_many')) ?>"
       data-msg-waiting-wifi="<?= e(t('offline.sync_waiting_wifi')) ?>"
       data-msg-login-required="<?= e(t('offline.sync_login_required')) ?>"
       data-msg-quota-exceeded="<?= e(t('offline.sync_quota_exceeded')) ?>">
    <p class="field-hint">
      <span data-sync-count></span>
      <button type="button" class="btn btn-ghost btn-small" data-sync-now><?= e(t('offline.sync_now')) ?></button>
    </p>
    <label class="field-hint sync-status__wifi-toggle">
      <input type="checkbox" data-wifi-only-toggle>
      <?= e(t('offline.wifi_only_label')) ?>
    </label>
  </div>

  <h2><?= e(t('trip.photos.upload_heading')) ?></h2>
  <p class="field-hint"><?= e(t('trip.photos.upload_hint')) ?></p>

  <div class="field">
    <label class="btn btn-ghost" for="trip-photo-input"><?= e(t('entry.form.photos_add')) ?></label>
    <input type="file" id="trip-photo-input" accept="image/jpeg,image/png,image/webp" multiple
           data-trip-photo-input
           data-resolve-url="/trips/<?= (int) $trip['id'] ?>/entries/for-date"
           class="visually-hidden">
  </div>

  <div class="field">
    <label class="btn btn-ghost" for="trip-video-input"><?= e(t('entry.form.videos_add')) ?></label>
    <input type="file" id="trip-video-input" accept="video/*" multiple
           data-trip-video-input
           data-resolve-url="/trips/<?= (int) $trip['id'] ?>/entries/for-date"
           class="visually-hidden">
  </div>

  <progress class="upload-progress" data-trip-upload-progress max="100" hidden></progress>
  <p class="field-hint" data-trip-upload-status
     data-msg-resolving="<?= e(t('trip.photos.status_resolving')) ?>"
     data-msg-uploading="<?= e(t('entry.form.photo_uploading')) ?>"
     data-msg-compressing="<?= e(t('entry.form.video_compressing')) ?>"
     data-msg-video-unsupported="<?= e(t('entry.form.video_unsupported')) ?>"
     data-msg-login-required="<?= e(t('entry.form.login_required')) ?>"
     data-msg-error="<?= e(t('entry.form.photo_upload_error')) ?>"></p>
<?php endif; ?>
