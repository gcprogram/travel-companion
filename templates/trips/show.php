<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<array<string, mixed>> $entries */
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

<h2>Tagebuch</h2>

<?php if ($entries !== []): ?>
  <ul class="day-entry-list">
    <?php foreach ($entries as $entry): ?>
      <li class="day-entry-card">
        <div class="day-entry-card__header">
          <span class="day-entry-card__date"><?= e(format_date($entry['entry_date'])) ?></span>
          <?php if (!empty($entry['title'])): ?>
            <span class="day-entry-card__title"><?= e($entry['title']) ?></span>
          <?php endif; ?>
          <?php if ($entry['mood'] !== null): ?>
            <span class="day-entry-card__mood" title="<?= e(mood_label($entry['mood'])) ?>"><?= mood_emoji($entry['mood']) ?></span>
          <?php endif; ?>
          <?php if ($entry['rating'] !== null): ?>
            <span class="day-entry-card__rating"><?= str_repeat('★', (int) $entry['rating']) . str_repeat('☆', 5 - (int) $entry['rating']) ?></span>
          <?php endif; ?>
        </div>

        <?php if (!empty($entry['body'])): ?>
          <p><?= nl2br(e($entry['body'])) ?></p>
        <?php endif; ?>

        <?php if ($entry['weather_temp_c'] !== null): ?>
          <p class="day-entry-card__weather">
            <?= e(weather_description((int) $entry['weather_code'])) ?>, <?= e(number_format((float) $entry['weather_temp_c'], 1, ',', '.')) ?> °C
          </p>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <div class="page-actions">
            <a class="btn btn-ghost" href="/tagebuch/<?= (int) $entry['id'] ?>/bearbeiten">Bearbeiten</a>
            <form method="post" action="/tagebuch/<?= (int) $entry['id'] ?>/loeschen"
                  onsubmit="return confirm('Diesen Tagebucheintrag wirklich löschen?');">
              <?= $csrf->field() ?>
              <button type="submit" class="btn btn-danger">Löschen</button>
            </form>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php elseif (!$canEdit): ?>
  <p class="empty-state">Noch keine Tagebucheinträge.</p>
<?php endif; ?>

<?php if ($canEdit): ?>
  <div class="page-actions">
    <a class="btn btn-primary" href="/reisen/<?= (int) $trip['id'] ?>/tagebuch/neu">+ Tagebucheintrag</a>
  </div>
<?php endif; ?>

<div class="empty-state">
  <p>Fotos und Videos kommen als Nächstes dazu.</p>
</div>

<?php if ($canEdit): ?>
  <div class="page-actions">
    <a class="btn btn-ghost" href="/reisen/<?= (int) $trip['id'] ?>/bearbeiten">Reise bearbeiten</a>
    <form method="post" action="/reisen/<?= (int) $trip['id'] ?>/loeschen"
          onsubmit="return confirm('Diese Reise wirklich löschen?');">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-danger">Reise löschen</button>
    </form>
  </div>
<?php endif; ?>
