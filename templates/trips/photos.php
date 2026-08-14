<?php
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var bool $wizard */
?>

<div class="map-view__header">
  <a class="icon-btn" href="/trip/<?= e($trip['slug']) ?>"
     title="<?= e(t('trip.map.back_to_trip')) ?>" aria-label="<?= e(t('trip.map.back_to_trip')) ?>">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M19 12H5M11 5l-7 7 7 7"/>
    </svg>
  </a>
  <h1><?= e(t('trip.photos.heading', ['title' => $trip['title']])) ?></h1>
</div>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map"><?= e(t('trip.map.back_to_map')) ?></a>
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/pois"><?= e(t('trip.show.pois_link')) ?></a>
</p>

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-tile-key="<?= e($mapTilerKey ?? '') ?>"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"></div>

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

<?php if ($wizard && $canEdit): ?>
  <p class="page-actions">
    <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/pois?wizard=1"><?= e(t('wizard.skip')) ?></a>
    <a class="btn btn-primary" href="/trip/<?= e($trip['slug']) ?>/pois?wizard=1"><?= e(t('wizard.continue')) ?></a>
  </p>
<?php endif; ?>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
<?php if ($canEdit): ?>
  <script src="/assets/js/chunked-upload.js"></script>
  <script src="/assets/js/offline-queue.js"></script>
  <script src="/assets/js/photo-geotag.js"></script>
  <script src="/assets/js/vendor/mp4-muxer.js"></script>
  <script src="/assets/js/video-compress.js"></script>
  <script src="/assets/js/video-geotag.js"></script>
  <script src="/assets/js/trip-photo-upload.js"></script>
<?php endif; ?>
