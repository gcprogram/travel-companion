<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<array<string, mixed>> $entries */
/** @var array<int, list<array<string, mixed>>> $photosByEntry */
/** @var array<int, list<array<string, mixed>>> $videosByEntry */
/** @var array<int, list<array<string, mixed>>> $weatherHoursByEntry */
/** @var bool $canEdit */
/** @var bool $canManageSharing */
/** @var list<array<string, mixed>> $shareTokens */
/** @var string $shareBaseUrl */
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

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-tile-key="<?= e($mapTilerKey ?? '') ?>"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"></div>

<div class="map-lightbox" data-map-lightbox hidden>
  <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
  <div class="map-lightbox__panel">
    <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
    <div class="map-lightbox__body" data-map-lightbox-body></div>
  </div>
</div>

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

        <?php $entryWeatherHours = $weatherHoursByEntry[(int) $entry['id']] ?? []; ?>
        <?php if ($entryWeatherHours !== []): ?>
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
                  <?php foreach ($entryWeatherHours as $wh): ?>
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

<?php if ($canManageSharing): ?>
  <h2><?= e(t('trip.share.heading')) ?></h2>
  <p class="field-hint"><?= e(t('trip.share.hint')) ?></p>

  <?php if ($shareTokens !== []): ?>
    <ul class="share-token-list">
      <?php foreach ($shareTokens as $shareToken): ?>
        <li class="share-token-list__item">
          <div class="share-token-list__info">
            <span class="share-token-list__label">
              <?= e($shareToken['label'] !== null && $shareToken['label'] !== '' ? $shareToken['label'] : t('trip.share.token_unlabeled')) ?>
            </span>
            <span class="share-token-list__permission"><?= e(t('trip.share.permission.' . $shareToken['permission'])) ?></span>
            <span class="field-hint">
              <?= $shareToken['last_used_at'] !== null
                ? e(t('trip.share.last_used', ['date' => format_datetime($shareToken['last_used_at'])]))
                : e(t('trip.share.never_used')) ?>
            </span>
          </div>
          <input type="text" class="share-token-list__link" readonly
                 value="<?= e($shareBaseUrl . $shareToken['token']) ?>" onclick="this.select()">
          <form method="post" action="/share-tokens/<?= (int) $shareToken['id'] ?>/delete"
                data-confirm="<?= e(t('trip.share.token_delete_confirm')) ?>">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-danger btn-small"><?= e(t('trip.share.token_delete')) ?></button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/share-tokens" class="share-token-form">
    <?= $csrf->field() ?>
    <div class="field">
      <label for="share-label"><?= e(t('trip.share.label_label')) ?></label>
      <input type="text" id="share-label" name="label" maxlength="100" placeholder="<?= e(t('trip.share.label_placeholder')) ?>">
    </div>
    <div class="field">
      <label for="share-permission"><?= e(t('trip.share.permission_label')) ?></label>
      <select id="share-permission" name="permission">
        <option value="view"><?= e(t('trip.share.permission.view')) ?></option>
        <option value="edit"><?= e(t('trip.share.permission.edit')) ?></option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('trip.share.create')) ?></button>
  </form>
<?php endif; ?>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
