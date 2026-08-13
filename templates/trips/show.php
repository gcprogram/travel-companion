<?php
/** @var array<string, mixed> $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<array<string, mixed>> $entries */
/** @var array<int, array{day: ?array{tempC: float, code: ?int}, night: ?array{tempC: float, code: ?int}}> $weatherSummaryByEntry */
/** @var array<int, array{average: float, count: int}> $ratingSummaryByEntry */
/** @var bool $canEdit */
/** @var bool $canManageSharing */
/** @var list<array<string, mixed>> $shareTokens */
/** @var string $shareBaseUrl */
?>

<h1>
  <?= e($trip['title']) ?>
  <?php if ($trip['visibility'] === 'private'): ?>
    <span class="trip-card__badge"><?= e(t('trip.badge_private')) ?></span>
  <?php elseif ($trip['visibility'] === 'member_only'): ?>
    <span class="trip-card__badge"><?= e(t('trip.badge_member_only')) ?></span>
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
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>/pois"><?= e(t('trip.show.pois_link')) ?></a>
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
      <li class="day-entry-card" data-day-entry-card
          data-entry-id="<?= (int) $entry['id'] ?>"
          data-entry-date="<?= e($entry['entry_date']) ?>"
          data-msg-loading="<?= e(t('entry.panel_loading')) ?>"
          data-msg-error="<?= e(t('entry.panel_error')) ?>">
        <button type="button" class="day-entry-card__header" data-day-entry-toggle aria-expanded="false">
          <span class="day-entry-card__header-row">
            <span class="day-entry-card__date"><?= e(format_date($entry['entry_date'])) ?></span>
            <?php if (!empty($entry['title'])): ?>
              <span class="day-entry-card__title"><?= e($entry['title']) ?></span>
            <?php endif; ?>
            <?php if ($entry['mood'] !== null): ?>
              <span class="day-entry-card__mood" title="<?= e(mood_label($entry['mood'])) ?>"><?= mood_emoji($entry['mood']) ?></span>
            <?php endif; ?>
            <?php $ratingSummary = $ratingSummaryByEntry[(int) $entry['id']] ?? null; ?>
            <?php if ($ratingSummary !== null): ?>
              <?php $ratingRounded = (int) round($ratingSummary['average']); ?>
              <span class="day-entry-card__rating" title="<?= e(t('entry.rating_count', ['count' => $ratingSummary['count']])) ?>">
                <?= str_repeat('★', $ratingRounded) . str_repeat('☆', 5 - $ratingRounded) ?>
              </span>
            <?php endif; ?>
            <span class="day-entry-card__chevron" aria-hidden="true">▾</span>
          </span>
          <?php $weatherSummary = $weatherSummaryByEntry[(int) $entry['id']] ?? ['day' => null, 'night' => null]; ?>
          <?php if ($weatherSummary['day'] !== null || $weatherSummary['night'] !== null): ?>
            <span class="day-entry-card__weather-summary">
              <?php if ($weatherSummary['day'] !== null): ?>
                <span title="<?= e(t('entry.weather_day')) ?>">
                  <?= weather_emoji($weatherSummary['day']['code']) ?> <?= e(number_format($weatherSummary['day']['tempC'], 0)) ?>°C
                </span>
              <?php endif; ?>
              <?php if ($weatherSummary['night'] !== null): ?>
                <span title="<?= e(t('entry.weather_night')) ?>">
                  🌙 <?= e(number_format($weatherSummary['night']['tempC'], 0)) ?>°C
                </span>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </button>
        <div class="day-entry-card__body" data-day-entry-body hidden></div>
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
  <h2 id="share-tokens"><?= e(t('trip.share.heading')) ?></h2>
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
<script src="/assets/js/day-entry-accordion.js"></script>
<script src="/assets/js/day-entry-rating.js"></script>
