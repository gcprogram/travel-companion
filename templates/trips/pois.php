<?php
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var list<array<string, mixed>> $pois */
/** @var array<int, array{photos: list<array<string, mixed>>, videos: list<array<string, mixed>>}> $poiMedia */
/** @var array<int, array{distanceMeters: float, closestAt: ?string}> $poiApproach */
/** @var int $poiSearchRadius */
/** @var list<string> $poiSearchCategories */
/** @var list<string> $searchableCategories */
/** @var bool $wizard */
$wizardQs = $wizard ? '?wizard=1' : '';
?>

<div class="map-view__header">
  <a class="icon-btn" href="/trip/<?= e($trip['slug']) ?>"
     title="<?= e(t('trip.map.back_to_trip')) ?>" aria-label="<?= e(t('trip.map.back_to_trip')) ?>">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M19 12H5M11 5l-7 7 7 7"/>
    </svg>
  </a>
  <h1><?= e(t('trip.pois.heading', ['title' => $trip['title']])) ?></h1>
</div>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map"><?= e(t('trip.map.back_to_map')) ?></a>
  <?php if ($canEdit): ?>
    <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/review"><?= e(t('trip.map.stays_review_link')) ?></a>
  <?php endif; ?>
</p>

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-tile-key="<?= e($mapTilerKey ?? '') ?>"
     data-can-edit="<?= $canEdit ? '1' : '' ?>"
     data-csrf-token="<?= e($csrf->token()) ?>"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"
     data-msg-poi-delete="<?= e(t('trip.map.poi_delete')) ?>"
     data-msg-poi-delete-confirm="<?= e(t('trip.map.poi_delete_confirm')) ?>"></div>

<div class="map-view__toggles">
  <label class="map-view__toggle">
    <input type="checkbox" data-map-route-toggle checked>
    <?= e(t('trip.map.show_route')) ?>
  </label>
  <label class="map-view__toggle">
    <input type="checkbox" data-map-photo-toggle checked>
    <?= e(t('trip.map.show_photos')) ?>
  </label>
  <label class="map-view__toggle">
    <input type="checkbox" data-map-geocache-toggle checked>
    <?= e(t('trip.map.show_geocaches')) ?>
  </label>
</div>

<?php include __DIR__ . '/_pois_fields.php'; ?>

<?php if ($wizard && $canEdit): ?>
  <p class="page-actions">
    <a class="btn btn-primary" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('wizard.finish')) ?></a>
  </p>
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
