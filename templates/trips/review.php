<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stays */
?>

<h1><?= e(t('trip.review.heading')) ?></h1>

<?php if ($stays === []): ?>
  <p class="empty-state"><?= e(t('trip.review.empty')) ?></p>
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map">&larr; <?= e(t('trip.review.back_to_map')) ?></a>
<?php else: ?>
  <div id="review-map" class="review-map"
       data-tile-key="<?= e($mapTilerKey ?? '') ?>"
       data-map-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
       data-accept-url="/trips/<?= (int) $trip['id'] ?>/pois/stay"
       data-dismiss-url="/trips/<?= (int) $trip['id'] ?>/pois/stay/dismiss"
       data-csrf-token="<?= e($csrf->token()) ?>"
       data-stays='<?= e(json_encode(array_map(static fn (array $s): array => [
           'lat' => $s['lat'],
           'lng' => $s['lng'],
           'name' => $s['locationName'],
           'startedAt' => $s['startedAt'],
           'endedAt' => $s['endedAt'],
           'durationSeconds' => $s['durationSeconds'],
       ], $stays))) ?>'
       data-msg-done="<?= e(t('trip.review.done')) ?>"
       data-msg-error="<?= e(t('trip.review.error')) ?>"
       data-fallback-name="<?= e(t('trip.map.stay_fallback_name')) ?>"></div>

  <div class="review-bar" data-review-bar>
    <button type="button" class="review-bar__nav" data-review-prev aria-label="<?= e(t('trip.review.prev')) ?>">&#9664;</button>
    <div class="review-bar__info">
      <input type="text" class="review-bar__name" data-review-name>
      <span class="review-bar__time" data-review-time></span>
    </div>
    <button type="button" class="review-bar__action review-bar__action--accept" data-review-accept
            aria-label="<?= e(t('trip.review.accept')) ?>">&#10003;</button>
    <button type="button" class="review-bar__action review-bar__action--reject" data-review-reject
            aria-label="<?= e(t('trip.review.reject')) ?>">&#10005;</button>
    <button type="button" class="review-bar__nav" data-review-next aria-label="<?= e(t('trip.review.next')) ?>">&#9654;</button>
  </div>

  <script src="/assets/js/vendor/leaflet.js"></script>
  <script src="/assets/js/stay-review.js"></script>
<?php endif; ?>
