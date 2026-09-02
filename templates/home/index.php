<?php
/** @var list<array<string, mixed>> $trips */
/** @var string|null $title */
/** @var string|null $emptyMessage */
$hasAnyPreview = false;
foreach ($trips as $trip) {
    if (!empty($trip['trackPreview'])) {
        $hasAnyPreview = true;
        break;
    }
}
$promoFeatures = ['diary', 'map', 'geocaching', 'ai', 'sharing', 'mobile'];
?>

<?php if (empty($currentUser)): ?>
  <section class="home-promo">
    <h1 class="home-promo__heading"><?= e(t('home.promo_heading')) ?></h1>
    <p class="home-promo__intro"><?= e(t('home.promo_intro')) ?></p>
    <ul class="home-promo__features">
      <?php foreach ($promoFeatures as $feature): ?>
        <li class="home-promo__feature">
          <strong><?= e(t('home.promo_feature_' . $feature . '_label')) ?></strong>
          <span><?= e(t('home.promo_feature_' . $feature)) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <h2><?= e(t('home.promo_public_heading')) ?></h2>
<?php else: ?>
  <h1><?= e($title ?? t('home.title')) ?></h1>
<?php endif; ?>

<?php if ($trips === []): ?>
  <div class="empty-state">
    <p><?= e($emptyMessage ?? t('home.empty')) ?></p>
    <?php if (!empty($currentUser)): ?>
      <a class="btn btn-primary" href="/trips/new"><?= e(t('home.create_first')) ?></a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="trip-list">
    <?php foreach ($trips as $trip): ?>
      <a class="card trip-card" href="/trip/<?= e($trip['slug']) ?>">
        <h2 class="trip-card__title">
          <?= e($trip['title']) ?>
          <?php if ($trip['visibility'] === 'private'): ?>
            <span class="trip-card__badge"><?= e(t('trip.badge_private')) ?></span>
          <?php elseif ($trip['visibility'] === 'member_only'): ?>
            <span class="trip-card__badge"><?= e(t('trip.badge_member_only')) ?></span>
          <?php endif; ?>
        </h2>
        <?php if (!empty($trip['trackPreview'])): ?>
          <div class="trip-card__map" data-trip-preview-map
               data-points="<?= e(json_encode($trip['trackPreview'])) ?>"
               data-tile-key="<?= e($mapTilerKey ?? '') ?>"></div>
        <?php else: ?>
          <div class="trip-card__map trip-card__map--empty"></div>
        <?php endif; ?>
        <div class="trip-card__meta">
          <?= e(format_date_range($trip['date_start'], $trip['date_end'])) ?>
          <?php if (!empty($trip['country'])): ?> · <?= e($trip['country']) ?><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($hasAnyPreview ?? false): ?>
  <script src="/assets/js/vendor/leaflet.js"></script>
  <script src="/assets/js/trip-list-map.js"></script>
<?php endif; ?>
