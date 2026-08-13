<?php
/** @var list<array<string, mixed>> $trips */
/** @var string|null $title */
/** @var string|null $emptyMessage */
?>

<h1><?= e($title ?? t('home.title')) ?></h1>

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
        <div class="trip-card__meta">
          <?= e(format_date_range($trip['date_start'], $trip['date_end'])) ?>
          <?php if (!empty($trip['country'])): ?> · <?= e($trip['country']) ?><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
