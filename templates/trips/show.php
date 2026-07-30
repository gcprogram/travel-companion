<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<array<string, mixed>> $entries */
/** @var array<int, list<array<string, mixed>>> $photosByEntry */
/** @var array<int, list<array<string, mixed>>> $videosByEntry */
/** @var bool $canEdit */
?>

<h1>
  <?= e($trip['title']) ?>
  <?php if ($trip['visibility'] === 'private'): ?>
    <span class="trip-card__badge"><?= e(t('trip.badge_private')) ?></span>
  <?php endif; ?>
</h1>

<p class="trip-card__meta">
  <?= e(format_date_range($trip['date_start'], $trip['date_end'])) ?>
  <?php if (!empty($trip['country'])): ?> · <?= e($trip['country']) ?><?php endif; ?>
  <?php if (!empty($trip['operator'])): ?> · <?= e($trip['operator']) ?><?php endif; ?>
  · <?= e(t('trip.show.by')) ?> <?= e($trip['author_name']) ?>
</p>

<?php if (!empty($trip['description'])): ?>
  <p><?= nl2br(e($trip['description'])) ?></p>
<?php endif; ?>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/map"><?= e(t('trip.show.map_link')) ?></a>
</p>

<?php if ($stations !== []): ?>
  <h2><?= e(t('trip.show.route_heading')) ?></h2>
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

<h2><?= e(t('trip.show.diary_heading')) ?></h2>

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

        <?php $entryPhotos = array_filter($photosByEntry[(int) $entry['id']] ?? [], static fn (array $p): bool => $p['status'] === 'ready'); ?>
        <?php if ($entryPhotos !== []): ?>
          <ul class="photo-gallery">
            <?php foreach ($entryPhotos as $photo): ?>
              <li class="photo-gallery__item">
                <a href="/photos/<?= (int) $photo['id'] ?>/web" target="_blank" rel="noopener">
                  <img src="/photos/<?= (int) $photo['id'] ?>/thumb" alt="" loading="lazy">
                </a>
                <?php if ($photo['lat'] !== null): ?>
                  <span class="geo-badge" title="<?= e(t('media.geotagged_hint')) ?>">📍</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php $entryVideos = array_filter($videosByEntry[(int) $entry['id']] ?? [], static fn (array $v): bool => $v['type'] === 'youtube' || $v['status'] === 'ready'); ?>
        <?php if ($entryVideos !== []): ?>
          <div class="video-list">
            <?php foreach ($entryVideos as $video): ?>
              <?php if ($video['type'] === 'youtube'): ?>
                <div class="video-embed">
                  <iframe src="https://www.youtube-nocookie.com/embed/<?= e((string) $video['youtube_id']) ?>"
                          loading="lazy" allowfullscreen
                          allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"></iframe>
                </div>
              <?php else: ?>
                <div class="video-embed-wrap">
                  <video controls preload="none" poster="/videos/<?= (int) $video['id'] ?>/poster" class="video-embed">
                    <source src="/videos/<?= (int) $video['id'] ?>" type="video/mp4">
                  </video>
                  <?php if ($video['lat'] !== null): ?>
                    <span class="geo-badge" title="<?= e(t('media.geotagged_hint')) ?>">📍</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <div class="page-actions">
            <a class="btn btn-ghost" href="/entries/<?= (int) $entry['id'] ?>/edit"><?= e(t('entry.edit')) ?></a>
            <form method="post" action="/entries/<?= (int) $entry['id'] ?>/delete"
                  data-confirm="<?= e(t('entry.delete_confirm')) ?>">
              <?= $csrf->field() ?>
              <button type="submit" class="btn btn-danger"><?= e(t('entry.delete')) ?></button>
            </form>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php elseif (!$canEdit): ?>
  <p class="empty-state"><?= e(t('trip.show.no_entries')) ?></p>
<?php endif; ?>

<?php if ($canEdit): ?>
  <div class="page-actions">
    <a class="btn btn-primary" href="/trips/<?= (int) $trip['id'] ?>/entries/new"><?= e(t('trip.show.add_entry')) ?></a>
  </div>
<?php endif; ?>

<?php if ($canEdit): ?>
  <div class="page-actions">
    <a class="btn btn-ghost" href="/trips/<?= (int) $trip['id'] ?>/edit"><?= e(t('trip.show.edit')) ?></a>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/delete"
          data-confirm="<?= e(t('trip.show.delete_confirm')) ?>">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-danger"><?= e(t('trip.show.delete')) ?></button>
    </form>
  </div>
<?php endif; ?>
