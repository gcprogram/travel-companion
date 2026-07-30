<?php
/** @var array<string, mixed> $trip */
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
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"></div>

<div class="map-lightbox" data-map-lightbox hidden>
  <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
  <div class="map-lightbox__panel">
    <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
    <div class="map-lightbox__body" data-map-lightbox-body></div>
  </div>
</div>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
