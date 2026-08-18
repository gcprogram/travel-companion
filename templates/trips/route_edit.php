<?php
/**
 * "Route editieren": trim slider + "Tracks löschen" moved here verbatim
 * from _route_fields.php (Teil-9-Redesign Phase 4), plus new single-point
 * surgery (delete/insert one trackpoint, undo the last such edit, reset
 * back to before this editing session's first one) via
 * TrackEditController/route-editor.js. Edit-only page, no read-only view.
 */
/** @var array<string, mixed> $trip */
/** @var array{totalPoints: int, trimStart: int, trimEnd: int}|null $track */
?>

<div class="map-view__header">
  <a class="icon-btn" href="/trip/<?= e($trip['slug']) ?>"
     title="<?= e(t('trip.map.back_to_trip')) ?>" aria-label="<?= e(t('trip.map.back_to_trip')) ?>">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M19 12H5M11 5l-7 7 7 7"/>
    </svg>
  </a>
  <h1><?= e(t('trip.route_edit.heading', ['title' => $trip['title']])) ?></h1>
</div>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map"><?= e(t('trip.route_edit.back_link')) ?></a>
</p>

<?php if ($track === null): ?>
  <p class="empty-state"><?= e(t('trip.route_edit.no_track')) ?></p>
<?php else: ?>
  <div id="route-edit-map" class="map-view__canvas"
       data-data-url="/trip/<?= e($trip['slug']) ?>/route-edit/data"
       data-tile-key="<?= e($mapTilerKey ?? '') ?>"
       data-csrf-token="<?= e($csrf->token()) ?>"
       data-delete-url="/trips/<?= (int) $trip['id'] ?>/track/points/__ID__/delete"
       data-insert-url="/trips/<?= (int) $trip['id'] ?>/track/points/insert"
       data-msg-delete-confirm="<?= e(t('trip.route_edit.delete_confirm')) ?>"
       data-msg-select-adjacent="<?= e(t('trip.route_edit.add_mode_hint')) ?>"
       data-msg-place-point="<?= e(t('trip.route_edit.place_point_hint')) ?>"
       data-msg-not-adjacent="<?= e(t('trip.route_edit.select_adjacent_error')) ?>"
       data-msg-error="<?= e(t('trip.review.error')) ?>"></div>

  <div class="route-edit__toolbar">
    <button type="button" class="btn btn-ghost route-edit__mode-btn" data-route-edit-mode="delete">
      <?= e(t('trip.route_edit.delete_mode')) ?>
    </button>
    <button type="button" class="btn btn-ghost route-edit__mode-btn" data-route-edit-mode="add">
      <?= e(t('trip.route_edit.add_mode')) ?>
    </button>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/undo">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.route_edit.undo')) ?></button>
    </form>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/reset"
          data-confirm="<?= e(t('trip.route_edit.reset_confirm')) ?>">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.route_edit.reset')) ?></button>
    </form>
  </div>
  <p class="field-hint" data-route-edit-status></p>

  <?php if ($track['totalPoints'] > 2): ?>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/trim" class="map-view__trim-form" data-trim-slider-form>
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

  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/delete"
        data-confirm="<?= e(t('trip.map.track_delete_confirm')) ?>">
    <?= $csrf->field() ?>
    <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.track_delete')) ?></button>
  </form>

  <script src="/assets/js/vendor/leaflet.js"></script>
  <script src="/assets/js/route-editor.js"></script>
<?php endif; ?>
