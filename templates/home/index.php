<?php /** @var list<array<string, mixed>> $trips */ ?>

<h1>Unsere Reisen</h1>

<?php if ($trips === []): ?>
  <div class="empty-state">
    <p>Noch keine Reisen hier.</p>
    <?php if (!empty($currentUser)): ?>
      <a class="btn btn-primary" href="/reisen/neu">Erste Reise anlegen</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="trip-list">
    <?php foreach ($trips as $trip): ?>
      <a class="card trip-card" href="/reise/<?= e($trip['slug']) ?>">
        <h2 class="trip-card__title">
          <?= e($trip['title']) ?>
          <?php if ($trip['visibility'] === 'private'): ?>
            <span class="trip-card__badge">privat</span>
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
