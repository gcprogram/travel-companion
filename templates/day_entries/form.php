<?php
/** @var array<string, mixed> $trip */
/** @var array<string, mixed>|null $entry */
/** @var list<array<string, mixed>> $photos */
/** @var list<array<string, mixed>> $videos */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $entry !== null && isset($entry['id']);
$action = $isEdit ? '/entries/' . (int) $entry['id'] : '/trips/' . (int) $trip['id'] . '/entries';
$moods = ['very_bad', 'bad', 'neutral', 'good', 'very_good'];
$lat = $entry['lat'] ?? null;
$lng = $entry['lng'] ?? null;
?>

<h1><?= $isEdit ? e(t('entry.form.title_edit')) : e(t('entry.form.title_new')) ?></h1>
<p class="field-hint"><?= e($trip['title']) ?></p>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="draft-banner" data-draft-banner hidden>
  <p class="field-hint">
    <?= e(t('offline.draft_found')) ?>
    <button type="button" class="btn btn-ghost btn-small" data-draft-restore><?= e(t('offline.draft_restore')) ?></button>
    <button type="button" class="btn btn-ghost btn-small" data-draft-discard><?= e(t('offline.draft_discard')) ?></button>
  </p>
</div>

<form method="post" action="<?= e($action) ?>" data-entry-draft data-draft-key="entry-draft-<?= e($action) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="entry_date"><?= e(t('entry.form.date_label')) ?></label>
    <input type="date" id="entry_date" name="entry_date" required
           value="<?= e($entry['entry_date'] ?? $defaultDate ?? date('Y-m-d')) ?>">
  </div>

  <div class="field">
    <label for="title"><?= e(t('entry.form.title_label')) ?></label>
    <input type="text" id="title" name="title" value="<?= e($entry['title'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="body"><?= e(t('entry.form.body_label')) ?></label>
    <textarea id="body" name="body" required><?= e($entry['body'] ?? '') ?></textarea>
  </div>

  <?php if ($isEdit): ?>
    <div class="ai-summary">
      <?php if (!empty($entry['ai_summary'])): ?>
        <p class="ai-summary__label"><?= e(t('entry.form.ai_summary_label')) ?></p>
        <p class="ai-summary__text" data-ai-summary-text><?= nl2br(e($entry['ai_summary'])) ?></p>
        <button type="button" class="btn btn-ghost btn-small" data-ai-summary-apply
                data-target="body"><?= e(t('entry.form.ai_summary_apply')) ?></button>
      <?php endif; ?>
      <form method="post" action="/entries/<?= (int) $entry['id'] ?>/summarize">
        <?= $csrf->field() ?>
        <button type="submit" class="btn btn-ghost btn-small"><?= e(t('entry.form.ai_summary_generate')) ?></button>
        <p class="field-hint"><?= e(t('entry.form.ai_summary_hint')) ?></p>
      </form>
    </div>
  <?php endif; ?>

  <div class="field">
    <label><?= e(t('entry.form.mood_label')) ?></label>
    <div class="field-radio-group field-radio-group--mood">
      <?php foreach ($moods as $value): ?>
        <label>
          <input type="radio" name="mood" value="<?= e($value) ?>"
            <?= (($entry['mood'] ?? null) === $value) ? 'checked' : '' ?>>
          <?= mood_emoji($value) ?> <?= e(t('mood.' . $value)) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="field">
    <label for="location_name"><?= e(t('entry.form.location_label')) ?></label>
    <input type="text" id="location_name" name="location_name"
           value="<?= e($entry['location_name'] ?? '') ?>"
           placeholder="<?= e(t('entry.form.location_name_placeholder')) ?>">
    <p class="field-hint"><?= e(t('entry.form.location_name_hint')) ?></p>
    <div class="location-input">
      <button type="button" class="btn btn-ghost" data-geolocate
              data-msg-unsupported="<?= e(t('entry.form.geo_unsupported')) ?>"
              data-msg-locating="<?= e(t('entry.form.geo_locating')) ?>"
              data-msg-error="<?= e(t('entry.form.geo_error')) ?>"
              data-msg-captured="<?= e(t('entry.form.location_captured')) ?>">📍 <?= e(t('entry.form.locate_button')) ?></button>
      <span class="field-hint" data-geolocate-status>
        <?= $lat !== null
          ? e(t('entry.form.location_captured', ['lat' => (string) $lat, 'lng' => (string) $lng]))
          : e(t('entry.form.location_none')) ?>
      </span>
      <input type="hidden" id="lat" name="lat" value="<?= e((string) ($lat ?? '')) ?>">
      <input type="hidden" id="lng" name="lng" value="<?= e((string) ($lng ?? '')) ?>">
    </div>
  </div>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('entry.form.save')) ?></button>
    <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('entry.form.cancel')) ?></a>
  </div>
</form>

<?php if ($isEdit): ?>
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

  <h2><?= e(t('entry.form.photos_heading')) ?></h2>
  <p class="field-hint"><?= e(t('entry.form.photos_hint')) ?></p>

  <ul class="photo-gallery" data-photo-gallery data-entry-id="<?= (int) $entry['id'] ?>"
      data-msg-queued="<?= e(t('entry.form.queued_offline')) ?>"
      data-msg-remove="<?= e(t('offline.queue_remove')) ?>"
      data-msg-remove-confirm="<?= e(t('offline.queue_remove_confirm')) ?>">
    <?php foreach ($photos as $photo): ?>
      <li class="photo-gallery__item" data-delete-item>
        <?php if ($photo['status'] === 'ready'): ?>
          <a href="/photos/<?= (int) $photo['id'] ?>/web" target="_blank" rel="noopener">
            <img class="photo-gallery__thumb" src="/photos/<?= (int) $photo['id'] ?>/thumb" alt="" loading="lazy">
          </a>
          <?php if ($photo['lat'] !== null): ?>
            <span class="geo-badge" title="<?= e(t('media.geotagged_hint')) ?>">📍</span>
          <?php endif; ?>
        <?php elseif ($photo['status'] === 'failed'): ?>
          <div class="photo-gallery__placeholder photo-gallery__placeholder--failed"><?= e(t('entry.form.photo_failed')) ?></div>
        <?php else: ?>
          <div class="photo-gallery__placeholder"><?= e(t('entry.form.photo_processing')) ?></div>
        <?php endif; ?>
        <form method="post" action="/photos/<?= (int) $photo['id'] ?>/delete"
              data-confirm-group="photo_delete" data-delete-inline
              data-confirm-message="<?= e(t('entry.form.photo_delete_confirm')) ?>"
              data-confirm-yes="<?= e(t('entry.form.confirm_yes')) ?>"
              data-confirm-no="<?= e(t('entry.form.confirm_no')) ?>"
              data-confirm-all="<?= e(t('entry.form.confirm_all')) ?>">
          <?= $csrf->field() ?>
          <button type="submit" class="btn btn-ghost photo-gallery__remove"><?= e(t('entry.form.photo_delete')) ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="field">
    <label class="btn btn-ghost" for="photo-input"><?= e(t('entry.form.photos_add')) ?></label>
    <input type="file" id="photo-input" accept="image/jpeg,image/png,image/webp" multiple
           data-photo-input
           data-entry-id="<?= (int) $entry['id'] ?>"
           data-upload-url="/entries/<?= (int) $entry['id'] ?>/photos"
           data-msg-preparing="<?= e(t('entry.form.photo_preparing')) ?>"
           data-msg-uploading="<?= e(t('entry.form.photo_uploading')) ?>"
           data-msg-queued="<?= e(t('entry.form.queued_offline')) ?>"
           data-msg-error="<?= e(t('entry.form.photo_upload_error')) ?>"
           data-msg-login-required="<?= e(t('entry.form.login_required')) ?>"
           class="visually-hidden">
    <progress class="upload-progress" data-photo-progress max="100" hidden></progress>
    <p class="field-hint" data-photo-status></p>
  </div>

  <h2><?= e(t('entry.form.videos_heading')) ?></h2>
  <p class="field-hint"><?= e(t('entry.form.videos_hint')) ?></p>

  <ul class="photo-gallery" data-video-gallery data-entry-id="<?= (int) $entry['id'] ?>"
      data-msg-queued="<?= e(t('entry.form.queued_offline')) ?>"
      data-msg-remove="<?= e(t('offline.queue_remove')) ?>"
      data-msg-remove-confirm="<?= e(t('offline.queue_remove_confirm')) ?>">
    <?php foreach ($videos as $video): ?>
      <li class="photo-gallery__item">
        <?php if ($video['type'] === 'youtube'): ?>
          <a href="https://www.youtube-nocookie.com/watch?v=<?= e((string) $video['youtube_id']) ?>" target="_blank" rel="noopener">
            <img class="photo-gallery__thumb" src="https://i.ytimg.com/vi/<?= e((string) $video['youtube_id']) ?>/hqdefault.jpg" alt="" loading="lazy">
          </a>
        <?php elseif ($video['status'] === 'ready'): ?>
          <a href="/videos/<?= (int) $video['id'] ?>" target="_blank" rel="noopener" class="photo-gallery__video-link">
            <img class="photo-gallery__thumb" src="/videos/<?= (int) $video['id'] ?>/poster" alt="" loading="lazy">
          </a>
          <?php if ($video['lat'] !== null): ?>
            <span class="geo-badge" title="<?= e(t('media.geotagged_hint')) ?>">📍</span>
          <?php endif; ?>
        <?php elseif ($video['status'] === 'failed'): ?>
          <div class="photo-gallery__placeholder photo-gallery__placeholder--failed"><?= e(t('entry.form.video_failed')) ?></div>
        <?php else: ?>
          <div class="photo-gallery__placeholder"><?= e(t('entry.form.video_processing')) ?></div>
        <?php endif; ?>
        <form method="post" action="/videos/<?= (int) $video['id'] ?>/delete"
              data-confirm="<?= e(t('entry.form.video_delete_confirm')) ?>">
          <?= $csrf->field() ?>
          <button type="submit" class="btn btn-ghost photo-gallery__remove"><?= e(t('entry.form.video_delete')) ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="field">
    <label class="btn btn-ghost" for="video-input"><?= e(t('entry.form.videos_add')) ?></label>
    <input type="file" id="video-input" accept="video/*"
           data-video-input
           data-entry-id="<?= (int) $entry['id'] ?>"
           data-upload-url="/entries/<?= (int) $entry['id'] ?>/videos"
           data-msg-unsupported="<?= e(t('entry.form.video_unsupported')) ?>"
           data-msg-compressing="<?= e(t('entry.form.video_compressing')) ?>"
           data-msg-uploading="<?= e(t('entry.form.video_uploading')) ?>"
           data-msg-too-long="<?= e(t('entry.form.video_too_long')) ?>"
           data-msg-codec-unsupported="<?= e(t('entry.form.video_codec_unsupported')) ?>"
           data-msg-queued="<?= e(t('entry.form.queued_offline')) ?>"
           data-msg-error="<?= e(t('entry.form.video_upload_error')) ?>"
           data-msg-login-required="<?= e(t('entry.form.login_required')) ?>"
           class="visually-hidden">
    <progress class="upload-progress" data-video-progress max="100" hidden></progress>
    <p class="field-hint" data-video-status></p>
  </div>

  <form method="post" action="/entries/<?= (int) $entry['id'] ?>/videos/youtube" class="video-youtube-form">
    <?= $csrf->field() ?>
    <div class="field">
      <label for="youtube_url"><?= e(t('entry.form.youtube_label')) ?></label>
      <input type="url" id="youtube_url" name="youtube_url" placeholder="<?= e(t('entry.form.youtube_placeholder')) ?>">
    </div>
    <button type="submit" class="btn btn-ghost"><?= e(t('entry.form.youtube_add')) ?></button>
  </form>

  <script src="/assets/js/chunked-upload.js"></script>
  <script src="/assets/js/offline-queue.js"></script>
  <script src="/assets/js/offline-gallery.js"></script>
  <script src="/assets/js/photo-upload.js"></script>
  <script src="/assets/js/vendor/mp4-muxer.js"></script>
  <script src="/assets/js/video-compress.js"></script>
  <script src="/assets/js/video-geotag.js"></script>
  <script src="/assets/js/video-upload.js"></script>
<?php endif; ?>

<script src="/assets/js/day-entry-form.js"></script>
