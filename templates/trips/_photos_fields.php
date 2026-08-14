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

  <p class="field-hint" data-trip-upload-status
     data-msg-resolving="<?= e(t('trip.photos.status_resolving')) ?>"
     data-msg-uploading="<?= e(t('entry.form.photo_uploading')) ?>"
     data-msg-compressing="<?= e(t('entry.form.video_compressing')) ?>"
     data-msg-video-unsupported="<?= e(t('entry.form.video_unsupported')) ?>"
     data-msg-login-required="<?= e(t('entry.form.login_required')) ?>"
     data-msg-error="<?= e(t('entry.form.photo_upload_error')) ?>"></p>
<?php endif; ?>
