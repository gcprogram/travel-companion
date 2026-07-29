<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stations */
/** @var bool $canEdit */
?>

<h1>
  <?= e($trip['title']) ?>
  <?php if ($trip['visibility'] === 'private'): ?>
    <span class="trip-card__badge">privat</span>
  <?php endif; ?>
</h1>

<p class="trip-card__meta">
  <?= e(format_date_range($trip['date_start'], $trip['date_end'])) ?>
  <?php if (!empty($trip['country'])): ?> · <?= e($trip['country']) ?><?php endif; ?>
  <?php if (!empty($trip['operator'])): ?> · <?= e($trip['operator']) ?><?php endif; ?>
  · von <?= e($trip['author_name']) ?>
</p>

<?php if (!empty($trip['description'])): ?>
  <p><?= nl2br(e($trip['description'])) ?></p>
<?php endif; ?>

<?php if ($stations !== []): ?>
  <h2>Route</h2>
  <ul class="route">
    <?php foreach ($stations as $station): ?>
      <li>
        <?php if (!empty($station['arrival_date'])): ?>
          <span class="station-date"><?= e(format_date($station['arrival_date'])) ?></span>
        <?php endif; ?>
        <span class="station-name"><?= e($station['name']) ?></span>
        <?php if (!empty($station['notes'])): ?>
          <p><?= nl2br(e($station['notes'])) ?></p>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="empty-state">
  <p>Tagesblogs, Fotos und Videos kommen in Phase 2 dazu.</p>
</div>

<?php if ($canEdit): ?>
  <div class="page-actions">
    <a class="btn btn-ghost" href="/reisen/<?= (int) $trip['id'] ?>/bearbeiten">Bearbeiten</a>
    <form method="post" action="/reisen/<?= (int) $trip['id'] ?>/loeschen"
          onsubmit="return confirm('Diese Reise wirklich löschen?');">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-danger">Löschen</button>
    </form>
  </div>
<?php endif; ?>
