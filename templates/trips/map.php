<?php
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var array{totalPoints: int, trimStart: int, trimEnd: int}|null $track */
$headExtra = '<link rel="stylesheet" href="/assets/js/vendor/leaflet.css">';
?>

<h1><?= e(t('trip.map.title')) ?></h1>
<p class="field-hint"><?= e($trip['title']) ?></p>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('trip.map.back_to_trip')) ?></a>
</p>

<label class="map-view__toggle">
  <input type="checkbox" data-map-route-toggle checked>
  <?= e(t('trip.map.show_route')) ?>
</label>

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"></div>

<?php if ($canEdit): ?>
  <div class="map-view__track-tools">
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/gpx" enctype="multipart/form-data" class="map-view__gpx-form">
      <?= $csrf->field() ?>
      <label for="gpx-file"><?= e(t('trip.map.gpx_label')) ?></label>
      <input type="file" id="gpx-file" name="gpx" accept=".gpx,application/gpx+xml">
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.gpx_upload')) ?></button>
    </form>

    <?php if ($track !== null): ?>
      <?php if ($track['totalPoints'] > 2): ?>
        <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/trim" class="map-view__trim-form">
          <?= $csrf->field() ?>
          <label>
            <?= e(t('trip.map.trim_start')) ?>
            <input type="range" name="trim_start" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>" value="<?= (int) $track['trimStart'] ?>">
          </label>
          <label>
            <?= e(t('trip.map.trim_end')) ?>
            <input type="range" name="trim_end" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>" value="<?= (int) $track['trimEnd'] ?>">
          </label>
          <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.trim_apply')) ?></button>
        </form>
      <?php endif; ?>

      <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/delete"
            data-confirm="<?= e(t('trip.map.track_delete_confirm')) ?>">
        <?= $csrf->field() ?>
        <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.track_delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="map-lightbox" data-map-lightbox hidden>
  <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
  <div class="map-lightbox__panel">
    <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
    <div class="map-lightbox__body" data-map-lightbox-body></div>
  </div>
</div>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
