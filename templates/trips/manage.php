<?php
/** @var array<string, mixed> $trip */
/** @var list<string> $errors */
/** @var bool $canEdit */
/** @var string $wizardQs */
/** @var array{totalPoints: int, trimStart: int, trimEnd: int}|null $track */
/** @var list<array<string, mixed>> $stays */
/** @var list<array<string, mixed>> $pois */
/** @var array<int, array{photos: list<array<string, mixed>>, videos: list<array<string, mixed>>}> $poiMedia */
/** @var array<int, array{distanceMeters: float, closestAt: ?string}> $poiApproach */
/** @var int $poiSearchRadius */
/** @var list<string> $poiSearchCategories */
/** @var list<string> $searchableCategories */
?>

<div class="map-view__header">
  <a class="icon-btn" href="/trip/<?= e($trip['slug']) ?>"
     title="<?= e(t('trip.map.back_to_trip')) ?>" aria-label="<?= e(t('trip.map.back_to_trip')) ?>">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M19 12H5M11 5l-7 7 7 7"/>
    </svg>
  </a>
  <h1><?= e(t('trip.manage.heading', ['title' => $trip['title']])) ?></h1>
</div>

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-tile-key="<?= e($mapTilerKey ?? '') ?>"
     data-can-edit="<?= $canEdit ? '1' : '' ?>"
     data-csrf-token="<?= e($csrf->token()) ?>"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"
     data-msg-poi-delete="<?= e(t('trip.map.poi_delete')) ?>"
     data-msg-poi-delete-confirm="<?= e(t('trip.map.poi_delete_confirm')) ?>"></div>

<label class="map-view__toggle map-view__toggle--under-map">
  <input type="checkbox" data-map-route-toggle checked>
  <?= e(t('trip.map.show_route')) ?>
</label>

<div data-accordion-group>
  <div class="accordion-section" data-accordion-section>
    <button type="button" class="accordion-section__toggle" data-accordion-toggle aria-expanded="false">
      <?= e(t('trip.manage.section_metadata')) ?>
      <span class="accordion-section__chevron" aria-hidden="true">▾</span>
    </button>
    <div class="accordion-section__body" data-accordion-body hidden>
      <?php include __DIR__ . '/_metadata_fields.php'; ?>
    </div>
  </div>

  <div class="accordion-section" data-accordion-section>
    <button type="button" class="accordion-section__toggle" data-accordion-toggle aria-expanded="false">
      <?= e(t('trip.manage.section_route')) ?>
      <span class="accordion-section__chevron" aria-hidden="true">▾</span>
    </button>
    <div class="accordion-section__body" data-accordion-body hidden>
      <?php include __DIR__ . '/_route_fields.php'; ?>
    </div>
  </div>

  <div class="accordion-section" data-accordion-section>
    <button type="button" class="accordion-section__toggle" data-accordion-toggle aria-expanded="false">
      <?= e(t('trip.manage.section_photos')) ?>
      <span class="accordion-section__chevron" aria-hidden="true">▾</span>
    </button>
    <div class="accordion-section__body" data-accordion-body hidden>
      <?php include __DIR__ . '/_photos_fields.php'; ?>
    </div>
  </div>

  <div class="accordion-section" data-accordion-section>
    <button type="button" class="accordion-section__toggle" data-accordion-toggle aria-expanded="false">
      <?= e(t('trip.manage.section_pois')) ?>
      <span class="accordion-section__chevron" aria-hidden="true">▾</span>
    </button>
    <div class="accordion-section__body" data-accordion-body hidden>
      <?php include __DIR__ . '/_pois_fields.php'; ?>
    </div>
  </div>
</div>

<div class="map-lightbox" data-map-lightbox hidden>
  <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
  <div class="map-lightbox__panel">
    <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
    <div class="map-lightbox__body" data-map-lightbox-body></div>
  </div>
</div>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
<script src="/assets/js/exclusive-accordion.js"></script>
<script src="/assets/js/photo-geotag.js"></script>
<script src="/assets/js/video-geotag.js"></script>
<script src="/assets/js/track-folder-scan.js"></script>
<script src="/assets/js/google-timeline-import.js"></script>
<script src="/assets/js/chunked-upload.js"></script>
<script src="/assets/js/offline-queue.js"></script>
<script src="/assets/js/vendor/mp4-muxer.js"></script>
<script src="/assets/js/video-compress.js"></script>
<script src="/assets/js/trip-photo-upload.js"></script>
<script src="/assets/js/geocaching-gpx-import.js"></script>
<script src="/assets/js/trip-metadata-ai.js"></script>
