<?php
/** @var array<string, mixed> $entry */
/** @var list<array<string, mixed>> $photos */
/** @var list<array<string, mixed>> $videos */
/** @var array<int, array{id: int, name: string, category: string}> $poiByPhoto */
/** @var array{average: ?float, count: int} $ratingSummary */
/** @var ?int $ownRating */
/** @var bool $canRate */
/** @var bool $canEdit */
?>

<?php if (!empty($entry['body'])): ?>
  <p><?= nl2br(e($entry['body'])) ?></p>
<?php endif; ?>

<?php if ($entry['weather_temp_c'] !== null): ?>
  <p class="day-entry-card__weather">
    <?= e(weather_description((int) $entry['weather_code'])) ?>, <?= e(number_format((float) $entry['weather_temp_c'], 1, ',', '.')) ?> °C
  </p>
<?php endif; ?>

<div class="entry-rating" data-rating-widget
     data-msg-count-template="<?= e(t('entry.rating_count', ['count' => ':count'])) ?>"
     data-msg-none="<?= e(t('entry.rating_none')) ?>">
  <p class="entry-rating__summary" data-rating-summary>
    <?php if ($ratingSummary['average'] !== null): ?>
      <?= str_repeat('★', (int) round($ratingSummary['average'])) . str_repeat('☆', 5 - (int) round($ratingSummary['average'])) ?>
      <?= e(number_format($ratingSummary['average'], 1)) ?>
      (<?= e(t('entry.rating_count', ['count' => $ratingSummary['count']])) ?>)
    <?php else: ?>
      <?= e(t('entry.rating_none')) ?>
    <?php endif; ?>
  </p>

  <?php if ($canRate): ?>
    <form data-rating-form action="/entries/<?= (int) $entry['id'] ?>/rate" method="post">
      <?= $csrf->field() ?>
      <p class="field-hint"><?= e(t('entry.rating_own_hint')) ?></p>
      <div class="rating-input">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" id="own-rating-<?= (int) $entry['id'] ?>-<?= $i ?>" name="rating" value="<?= $i ?>"
            data-rating-input
            <?= $ownRating === $i ? 'checked' : '' ?>>
          <label for="own-rating-<?= (int) $entry['id'] ?>-<?= $i ?>">★</label>
        <?php endfor; ?>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php if ($weatherHours !== []): ?>
  <details class="weather-hours">
    <summary><?= e(t('entry.weather_hourly_toggle')) ?></summary>
    <div class="weather-hours__scroll">
      <table class="weather-hours__table">
        <thead>
          <tr>
            <th><?= e(t('entry.weather_hour')) ?></th>
            <th></th>
            <th><?= e(t('entry.weather_temp')) ?></th>
            <th><?= e(t('entry.weather_feels_like')) ?></th>
            <th><?= e(t('entry.weather_rain')) ?></th>
            <th><?= e(t('entry.weather_wind')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weatherHours as $wh): ?>
            <tr>
              <td><?= sprintf('%02d:00', (int) $wh['hour']) ?></td>
              <td><?= $wh['weather_code'] !== null ? weather_emoji((int) $wh['weather_code']) : '' ?></td>
              <td><?= $wh['temp_c'] !== null ? e(number_format((float) $wh['temp_c'], 0)) . '°C' : '–' ?></td>
              <td><?= $wh['feels_like_c'] !== null ? e(number_format((float) $wh['feels_like_c'], 0)) . '°C' : '–' ?></td>
              <td><?= $wh['precipitation_probability'] !== null ? (int) $wh['precipitation_probability'] . '%' : '–' ?></td>
              <td><?= $wh['wind_speed_kmh'] !== null ? e(number_format((float) $wh['wind_speed_kmh'], 0)) . ' km/h' : '–' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
<?php endif; ?>

<?php
$entryPhotos = array_values(array_filter($photos, static fn (array $p): bool => $p['status'] === 'ready'));
usort($entryPhotos, static fn (array $a, array $b): int
    => ($a['taken_at'] ?? $a['created_at']) <=> ($b['taken_at'] ?? $b['created_at']));

// Detailed view (trip.show.detail_view_toggle): a sight's name is inserted
// once, right before the first (chronologically) of its own assigned
// photos (PoiAssignmentService/PoiMediaRepository) - not once per photo,
// several photos of the same sight would just repeat it pointlessly.
$shownPoiIds = [];
$lightboxPhotos = [];
foreach ($entryPhotos as $photo) {
    $lightboxPhotos[] = [
        'kind' => 'photo',
        'id' => (int) $photo['id'],
        'thumbUrl' => '/photos/' . $photo['id'] . '/thumb',
        'fullUrl' => '/photos/' . $photo['id'] . '/web',
        'takenAt' => $photo['taken_at'] ?? $photo['created_at'],
        'address' => $photo['ai_address'] ?? null,
        'poiName' => isset($poiByPhoto[(int) $photo['id']]) ? $poiByPhoto[(int) $photo['id']]['name'] : null,
        'rating' => (int) $photo['rating'],
    ];
}
?>
<?php if ($entryPhotos !== []): ?>
  <ul class="photo-gallery" data-lightbox-photos='<?= e(json_encode($lightboxPhotos)) ?>'>
    <?php foreach ($entryPhotos as $photo): ?>
      <?php $poi = $poiByPhoto[(int) $photo['id']] ?? null; ?>
      <?php if ($poi !== null && !isset($shownPoiIds[$poi['id']])): ?>
        <?php $shownPoiIds[$poi['id']] = true; ?>
        <li class="photo-gallery__item photo-gallery__item--poi" data-detail-only>
          <?php if ($poi['category'] === 'geocache'): ?>
            <span class="photo-gallery__poi-card">
              <span class="photo-gallery__poi-minimap" data-poi-minimap
                    data-lat="<?= e((string) $poi['lat']) ?>" data-lng="<?= e((string) $poi['lng']) ?>"
                    data-icon-url="<?= e(cache_type_icon_url($poi['cacheType'])) ?>"></span>
              <span class="photo-gallery__poi-label">
                <?php if ($poi['gcCode'] !== null): ?>
                  <span class="photo-gallery__poi-gccode"><?= e($poi['gcCode']) ?></span>
                <?php endif; ?>
                <?= e($poi['name']) ?>
              </span>
            </span>
          <?php else: ?>
            <span class="photo-gallery__poi-card">
              <span class="photo-gallery__poi-minimap" data-poi-minimap
                    data-lat="<?= e((string) $poi['lat']) ?>" data-lng="<?= e((string) $poi['lng']) ?>"></span>
              <span class="photo-gallery__poi-label"><?= e($poi['name']) ?></span>
            </span>
          <?php endif; ?>
        </li>
      <?php endif; ?>
      <li class="photo-gallery__item">
        <button type="button" class="photo-gallery__link" data-lightbox-photo="<?= (int) $photo['id'] ?>">
          <img class="photo-gallery__thumb" src="/photos/<?= (int) $photo['id'] ?>/thumb" alt="" loading="lazy">
        </button>
        <?php if ($photo['lat'] !== null): ?>
          <span class="geo-badge" title="<?= e(t('media.geotagged_hint')) ?>">📍</span>
        <?php endif; ?>
        <div class="photo-gallery__caption" data-media-caption>
          <p data-media-caption-text><?= e((string) ($photo['caption'] ?? '')) ?></p>
          <?php if ($canEdit): ?>
            <button type="button" class="btn btn-ghost btn-small" data-media-caption-generate
                    data-caption-url="/photos/<?= (int) $photo['id'] ?>/caption">
              <?= e(t('media.caption_generate')) ?>
            </button>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php $entryVideos = array_filter($videos, static fn (array $v): bool => $v['type'] === 'youtube' || $v['status'] === 'ready'); ?>
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
          <div class="photo-gallery__caption" data-media-caption>
            <p data-media-caption-text><?= e((string) ($video['caption'] ?? '')) ?></p>
            <?php if ($canEdit): ?>
              <button type="button" class="btn btn-ghost btn-small" data-media-caption-generate
                      data-caption-url="/videos/<?= (int) $video['id'] ?>/caption">
                <?= e(t('media.caption_generate')) ?>
              </button>
            <?php endif; ?>
          </div>
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
