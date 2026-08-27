<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $candidates */
?>

<h1><?= e(t('trip.review.heading')) ?></h1>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map">&larr; <?= e(t('trip.review.back_to_map')) ?></a>
</p>

<?php if ($candidates === []): ?>
  <p class="empty-state"><?= e(t('trip.review.empty')) ?></p>
<?php else: ?>
  <div id="review-map" class="review-map"
       data-tile-key="<?= e($mapTilerKey ?? '') ?>"
       data-map-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
       data-accept-url="/trips/<?= (int) $trip['id'] ?>/pois/stay"
       data-dismiss-url="/trips/<?= (int) $trip['id'] ?>/pois/stay/dismiss"
       data-sight-visited-url-base="/pois/"
       data-sight-delete-url-base="/pois/"
       data-can-edit="1"
       data-csrf-token="<?= e($csrf->token()) ?>"
       data-candidates='<?= e(json_encode($candidates)) ?>'
       data-msg-done="<?= e(t('trip.review.done')) ?>"
       data-msg-error="<?= e(t('trip.review.error')) ?>"
       data-msg-session-expired="<?= e(t('trip.review.session_expired')) ?>"
       data-fallback-name="<?= e(t('trip.map.stay_fallback_name')) ?>"
       data-kind-stay="<?= e(t('trip.review.candidate_kind_stay')) ?>"
       data-kind-sight="<?= e(t('trip.review.candidate_kind_sight')) ?>"
       data-msg-lightbox-delete-confirm="<?= e(t('trip.map.lightbox_delete_confirm')) ?>"
       data-msg-lightbox-action-error="<?= e(t('trip.map.lightbox_action_error')) ?>"></div>

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

  <ul class="review-photos" data-review-photos hidden></ul>

  <div class="review-bar" data-review-bar>
    <button type="button" class="review-bar__nav" data-review-prev aria-label="<?= e(t('trip.review.prev')) ?>">&#9664;</button>
    <div class="review-bar__info">
      <span class="review-bar__kind" data-review-kind></span>
      <input type="text" class="review-bar__name" data-review-name>
      <span class="review-bar__time" data-review-time></span>
    </div>
    <button type="button" class="review-bar__action review-bar__action--accept" data-review-accept
            aria-label="<?= e(t('trip.review.accept')) ?>">&#10003;</button>
    <button type="button" class="review-bar__action review-bar__action--reject" data-review-reject
            aria-label="<?= e(t('trip.review.reject')) ?>">&#10005;</button>
    <button type="button" class="review-bar__nav" data-review-next aria-label="<?= e(t('trip.review.next')) ?>">&#9654;</button>
  </div>

  <div class="map-lightbox" data-map-lightbox hidden>
    <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
    <div class="map-lightbox__panel">
      <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
      <button type="button" class="map-lightbox__nav map-lightbox__nav--prev" data-map-lightbox-prev aria-label="<?= e(t('trip.map.lightbox_prev')) ?>">&#9664;</button>
      <button type="button" class="map-lightbox__nav map-lightbox__nav--next" data-map-lightbox-next aria-label="<?= e(t('trip.map.lightbox_next')) ?>">&#9654;</button>
      <div class="map-lightbox__body" data-map-lightbox-body></div>
      <div class="map-lightbox__actions" data-map-lightbox-actions>
        <button type="button" class="btn btn-ghost btn-small" data-map-lightbox-rotate="l" title="<?= e(t('trip.map.lightbox_rotate_left')) ?>" aria-label="<?= e(t('trip.map.lightbox_rotate_left')) ?>">&#8634;</button>
        <button type="button" class="btn btn-ghost btn-small" data-map-lightbox-rotate="r" title="<?= e(t('trip.map.lightbox_rotate_right')) ?>" aria-label="<?= e(t('trip.map.lightbox_rotate_right')) ?>">&#8635;</button>
        <span class="map-lightbox__rating" data-map-lightbox-rating>
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <button type="button" data-map-lightbox-star="<?= $i ?>" aria-label="<?= e(t('trip.map.lightbox_star', ['count' => $i])) ?>">&#9733;</button>
          <?php endfor; ?>
        </span>
        <button type="button" class="btn btn-ghost btn-small map-lightbox__delete" data-map-lightbox-delete title="<?= e(t('trip.map.lightbox_delete')) ?>" aria-label="<?= e(t('trip.map.lightbox_delete')) ?>">&#128465;</button>
      </div>
      <div class="map-lightbox__caption" data-map-lightbox-caption></div>
    </div>
  </div>

  <script src="/assets/js/vendor/leaflet.js"></script>
  <script src="/assets/js/trip-map.js"></script>
  <script src="/assets/js/review-carousel.js"></script>
<?php endif; ?>
