<?php
/**
 * Route/track management: GPX/folder/Timeline upload, trim, delete,
 * detected stays. Shared between the standalone wizard step (map.php) and
 * the "Route" accordion section on the unified edit page (manage.php) -
 * expects the same variables map.php's controller (TripMapController) already
 * provides: $trip, $canEdit, $track, $stays, $wizardQs.
 */
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var array{totalPoints: int, trimStart: int, trimEnd: int}|null $track */
/** @var list<array<string, mixed>> $stays */
/** @var string $wizardQs */
?>

<?php if ($canEdit): ?>
  <div class="map-view__track-tools">
    <h2><?= e(t('trip.map.track_tools_heading')) ?></h2>

    <div class="map-view__track-method">
      <strong><?= e(t('trip.map.track_method_gpx')) ?></strong>
      <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/gpx<?= e($wizardQs) ?>" enctype="multipart/form-data" class="map-view__gpx-form">
        <?= $csrf->field() ?>
        <label for="gpx-file"><?= e(t('trip.map.gpx_label')) ?></label>
        <input type="file" id="gpx-file" name="gpx" accept=".gpx,application/gpx+xml">
        <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.gpx_upload')) ?></button>
      </form>
      <p class="field-hint">
        <?= e(t('trip.map.geocaching_gpx_pointer')) ?>
        <a href="/trip/<?= e($trip['slug']) ?>/pois"><?= e(t('trip.show.pois_link')) ?></a>
      </p>
    </div>

    <div class="map-view__track-method">
      <strong><?= e(t('trip.map.track_method_photos')) ?></strong>
      <p class="field-hint"><?= e(t('trip.map.track_method_photos_hint')) ?></p>
      <p class="field-hint"><?= e(t('trip.map.folder_intro')) ?></p>
      <div class="map-view__folder-form">
        <label class="btn btn-ghost" for="track-folder-input"><?= e(t('trip.map.folder_pick')) ?></label>
        <input type="file" id="track-folder-input" webkitdirectory multiple
               data-track-folder-input
               data-submit-url="/trips/<?= (int) $trip['id'] ?>/track/points<?= e($wizardQs) ?>"
               data-csrf-token="<?= e($csrf->token()) ?>"
               data-msg-no-media="<?= e(t('trip.map.folder_no_media')) ?>"
               data-msg-scanning="<?= e(t('trip.map.folder_scanning')) ?>"
               data-msg-no-points="<?= e(t('trip.map.folder_no_points')) ?>"
               data-msg-uploading="<?= e(t('trip.map.folder_uploading')) ?>"
               data-msg-error="<?= e(t('trip.map.folder_error')) ?>"
               class="visually-hidden">
        <p class="field-hint" data-track-folder-status></p>
      </div>
    </div>

    <div class="map-view__track-method">
      <strong><?= e(t('trip.map.track_method_timeline')) ?></strong>
      <p class="field-hint"><?= e(t('trip.map.track_method_timeline_hint')) ?></p>
      <div class="map-view__folder-form">
        <label class="btn btn-ghost" for="timeline-file"><?= e(t('trip.map.timeline_file_label')) ?></label>
        <input type="file" id="timeline-file" accept=".json,application/json"
               data-timeline-file-input
               data-track-submit-url="/trips/<?= (int) $trip['id'] ?>/track/points<?= e($wizardQs) ?>"
               data-stay-url="/trips/<?= (int) $trip['id'] ?>/pois/stay<?= e($wizardQs) ?>"
               data-csrf-token="<?= e($csrf->token()) ?>"
               data-msg-reading="<?= e(t('trip.map.timeline_reading')) ?>"
               data-msg-parsing="<?= e(t('trip.map.timeline_parsing')) ?>"
               data-msg-parsed="<?= e(t('trip.map.timeline_parsed')) ?>"
               data-msg-unrecognized="<?= e(t('trip.map.timeline_unrecognized')) ?>"
               data-msg-uploading="<?= e(t('trip.map.timeline_uploading')) ?>"
               data-msg-error="<?= e(t('trip.map.timeline_error')) ?>"
               data-msg-invalid-json="<?= e(t('trip.map.timeline_invalid_json')) ?>"
               data-msg-visit-unnamed="<?= e(t('trip.map.timeline_visit_unnamed')) ?>"
               data-msg-visit-add="<?= e(t('trip.map.stay_add')) ?>"
               class="visually-hidden">
      </div>
      <div class="timeline-import__range">
        <label><?= e(t('trip.map.timeline_from_label')) ?>
          <input type="date" data-timeline-from value="<?= e($trip['date_start'] ?? '') ?>">
        </label>
        <label><?= e(t('trip.map.timeline_to_label')) ?>
          <input type="date" data-timeline-to value="<?= e($trip['date_end'] ?? '') ?>">
        </label>
      </div>
      <p class="field-hint" data-timeline-status></p>
      <div data-timeline-summary hidden>
        <button type="button" class="btn btn-ghost" data-timeline-submit-track><?= e(t('trip.map.timeline_submit_track')) ?></button>
        <ul class="stay-list" data-timeline-visits></ul>
      </div>
    </div>

    <?php if ($track !== null): ?>
      <?php if ($track['totalPoints'] > 2): ?>
        <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/trim<?= e($wizardQs) ?>" class="map-view__trim-form" data-trim-slider-form>
          <?= $csrf->field() ?>
          <span class="map-view__trim-group">
            <label>
              <?= e(t('trip.map.trim_start')) ?>
              <input type="range" name="trim_start" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>"
                     value="<?= (int) $track['trimStart'] ?>" data-trim-range="start">
            </label>
            <input type="datetime-local" step="1" class="map-view__trim-time" data-trim-time="start">
          </span>
          <span class="map-view__trim-group">
            <label>
              <?= e(t('trip.map.trim_end')) ?>
              <input type="range" name="trim_end" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>"
                     value="<?= (int) $track['trimEnd'] ?>" data-trim-range="end">
            </label>
            <input type="datetime-local" step="1" class="map-view__trim-time" data-trim-time="end">
          </span>
          <button type="submit" class="btn btn-primary"><?= e(t('trip.map.trim_apply')) ?></button>
        </form>
        <p class="field-hint"><?= e(t('trip.map.trim_slider_hint')) ?></p>
      <?php endif; ?>

      <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/delete<?= e($wizardQs) ?>"
            data-confirm="<?= e(t('trip.map.track_delete_confirm')) ?>">
        <?= $csrf->field() ?>
        <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.track_delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canEdit && $stays !== []): ?>
  <h2><?= e(t('trip.map.stays_heading')) ?></h2>
  <p class="field-hint"><?= e(t('trip.map.stays_hint')) ?></p>
  <ul class="stay-list">
    <?php foreach ($stays as $stay): ?>
      <li class="stay-list__item">
        <div>
          <span class="stay-list__location">
            <?php if ($stay['locationName'] !== null): ?>
              <?= e($stay['locationName']) ?>
            <?php elseif ($stay['locationResolved']): ?>
              <?= e(t('trip.map.stay_fallback_name')) ?>
            <?php else: ?>
              <?= e(t('trip.map.stay_resolving')) ?>
            <?php endif; ?>
          </span>
          <span class="stay-list__time">
            <?= e(format_datetime($stay['startedAt'])) ?> – <?= e(format_datetime($stay['endedAt'])) ?>
          </span>
          <span class="field-hint">
            <?= e(t('trip.map.stay_duration', ['minutes' => (string) (int) round($stay['durationSeconds'] / 60)])) ?>
          </span>
        </div>
        <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/stay<?= e($wizardQs) ?>" class="stay-list__actions">
          <?= $csrf->field() ?>
          <input type="hidden" name="lat" value="<?= e((string) $stay['lat']) ?>">
          <input type="hidden" name="lng" value="<?= e((string) $stay['lng']) ?>">
          <input type="hidden" name="started_at" value="<?= e($stay['startedAt']) ?>">
          <input type="hidden" name="ended_at" value="<?= e($stay['endedAt']) ?>">
          <button type="submit" class="btn btn-ghost btn-small"><?= e(t('trip.map.stay_add')) ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
