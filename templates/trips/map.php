<?php
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var array{totalPoints: int, trimStart: int, trimEnd: int}|null $track */
/** @var list<array<string, mixed>> $pois */
/** @var array<int, array{photos: list<array<string, mixed>>, videos: list<array<string, mixed>>}> $poiMedia */
$categories = ['museum', 'zoo', 'attraction', 'viewpoint', 'monument', 'sacred_building', 'other'];
?>

<h1><?= e(t('trip.map.title')) ?></h1>
<p class="field-hint"><?= e($trip['title']) ?></p>

<p class="page-actions">
  <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('trip.map.back_to_trip')) ?></a>
</p>

<label class="map-view__toggle">
  <input type="checkbox" data-map-route-toggle checked>
  <?= e(t('trip.map.show_route')) ?>
</label>

<?php if ($canEdit && $track !== null && $track['totalPoints'] > 2): ?>
  <div class="map-trim" data-map-trim>
    <p class="field-hint"><?= e(t('trip.map.trim_map_hint')) ?></p>
    <div class="map-trim__modes">
      <button type="button" class="btn btn-ghost" data-trim-mode="start"><?= e(t('trip.map.trim_mode_start')) ?></button>
      <button type="button" class="btn btn-ghost" data-trim-mode="end"><?= e(t('trip.map.trim_mode_end')) ?></button>
    </div>
    <p class="field-hint" data-trim-status></p>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/trim" data-trim-form hidden>
      <?= $csrf->field() ?>
      <input type="hidden" name="trim_start" value="<?= (int) $track['trimStart'] ?>">
      <input type="hidden" name="trim_end" value="<?= (int) $track['trimEnd'] ?>">
    </form>
  </div>
<?php endif; ?>

<div class="map-view__canvas" id="trip-map"
     data-data-url="/trip/<?= e($trip['slug']) ?>/map/data"
     data-tile-key="<?= e($mapTilerKey ?? '') ?>"
     data-can-edit="<?= $canEdit ? '1' : '' ?>"
     data-csrf-token="<?= e($csrf->token()) ?>"
     data-msg-empty="<?= e(t('trip.map.empty')) ?>"
     data-msg-pause="<?= e(t('trip.map.pause_label')) ?>"
     data-msg-poi-delete="<?= e(t('trip.map.poi_delete')) ?>"
     data-msg-poi-delete-confirm="<?= e(t('trip.map.poi_delete_confirm')) ?>"
     data-msg-trim-picking-start="<?= e(t('trip.map.trim_picking_start')) ?>"
     data-msg-trim-picking-end="<?= e(t('trip.map.trim_picking_end')) ?>"></div>

<?php if ($canEdit): ?>
  <div class="map-view__track-tools">
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/gpx" enctype="multipart/form-data" class="map-view__gpx-form">
      <?= $csrf->field() ?>
      <label for="gpx-file"><?= e(t('trip.map.gpx_label')) ?></label>
      <input type="file" id="gpx-file" name="gpx" accept=".gpx,application/gpx+xml">
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.gpx_upload')) ?></button>
    </form>

    <p class="field-hint"><?= e(t('trip.map.folder_intro')) ?></p>
    <div class="map-view__folder-form">
      <label class="btn btn-ghost" for="track-folder-input"><?= e(t('trip.map.folder_pick')) ?></label>
      <input type="file" id="track-folder-input" webkitdirectory multiple
             data-track-folder-input
             data-submit-url="/trips/<?= (int) $trip['id'] ?>/track/points"
             data-csrf-token="<?= e($csrf->token()) ?>"
             data-msg-no-media="<?= e(t('trip.map.folder_no_media')) ?>"
             data-msg-scanning="<?= e(t('trip.map.folder_scanning')) ?>"
             data-msg-no-points="<?= e(t('trip.map.folder_no_points')) ?>"
             data-msg-uploading="<?= e(t('trip.map.folder_uploading')) ?>"
             data-msg-error="<?= e(t('trip.map.folder_error')) ?>"
             class="visually-hidden">
      <p class="field-hint" data-track-folder-status></p>
    </div>

    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/fill-gaps">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.track_gap_fill')) ?></button>
      <p class="field-hint"><?= e(t('trip.map.track_gap_fill_hint')) ?></p>
    </form>

    <?php if ($track !== null): ?>
      <?php if ($track['totalPoints'] > 2): ?>
        <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/trim" class="map-view__trim-form">
          <?= $csrf->field() ?>
          <label>
            <?= e(t('trip.map.trim_start')) ?>
            <input type="range" name="trim_start" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>" value="<?= (int) $track['trimStart'] ?>">
          </label>
          <label>
            <?= e(t('trip.map.trim_end')) ?>
            <input type="range" name="trim_end" min="0" max="<?= (int) $track['totalPoints'] - 1 ?>" value="<?= (int) $track['trimEnd'] ?>">
          </label>
          <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.trim_apply')) ?></button>
        </form>
      <?php endif; ?>

      <form method="post" action="/trips/<?= (int) $trip['id'] ?>/track/delete"
            data-confirm="<?= e(t('trip.map.track_delete_confirm')) ?>">
        <?= $csrf->field() ?>
        <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.track_delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canEdit && $stays !== []): ?>
  <h2><?= e(t('trip.map.stays_heading')) ?></h2>
  <p class="field-hint"><?= e(t('trip.map.stays_hint')) ?></p>
  <ul class="stay-list">
    <?php foreach ($stays as $stay): ?>
      <li class="stay-list__item">
        <div>
          <span class="stay-list__time">
            <?= e(format_datetime($stay['startedAt'])) ?> – <?= e(format_datetime($stay['endedAt'])) ?>
          </span>
          <span class="field-hint">
            <?= e(t('trip.map.stay_duration', ['minutes' => (string) (int) round($stay['durationSeconds'] / 60)])) ?>
          </span>
        </div>
        <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/stay" class="stay-list__actions">
          <?= $csrf->field() ?>
          <input type="hidden" name="lat" value="<?= e((string) $stay['lat']) ?>">
          <input type="hidden" name="lng" value="<?= e((string) $stay['lng']) ?>">
          <input type="hidden" name="started_at" value="<?= e($stay['startedAt']) ?>">
          <input type="hidden" name="ended_at" value="<?= e($stay['endedAt']) ?>">
          <button type="submit" class="btn btn-ghost btn-small"><?= e(t('trip.map.stay_add')) ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2><?= e(t('trip.map.poi_heading')) ?></h2>

<?php if ($canEdit): ?>
  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/discover" class="poi-search-form">
    <?= $csrf->field() ?>
    <div class="field">
      <label for="poi-radius"><?= e(t('trip.map.poi_radius_label')) ?></label>
      <input type="number" id="poi-radius" name="radius_meters" min="50" max="5000" step="50"
             value="<?= (int) $poiSearchRadius ?>">
      <p class="field-hint"><?= e(t('trip.map.poi_radius_hint')) ?></p>
    </div>
    <fieldset class="field">
      <legend><?= e(t('trip.map.poi_categories_label')) ?></legend>
      <div class="poi-search-form__categories">
        <?php foreach ($searchableCategories as $category): ?>
          <label>
            <input type="checkbox" name="categories[]" value="<?= e($category) ?>"
              <?= in_array($category, $poiSearchCategories, true) ? 'checked' : '' ?>>
            <?= e(t('trip.map.category.' . $category)) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.poi_discover')) ?></button>
    <p class="field-hint"><?= e(t('trip.map.poi_discover_hint')) ?></p>
  </form>

  <?php if ($pois !== []): ?>
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/prune" class="page-actions"
          data-confirm="<?= e(t('trip.map.poi_prune_confirm')) ?>">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.poi_prune')) ?></button>
    </form>
    <p class="field-hint"><?= e(t('trip.map.poi_prune_hint')) ?></p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($pois === []): ?>
  <p class="empty-state"><?= e(t('trip.map.poi_empty')) ?></p>
<?php else: ?>
  <ul class="poi-list">
    <?php foreach ($pois as $poi): ?>
      <li class="poi-list__item<?= $poi['visited'] ? ' poi-list__item--visited' : '' ?>">
        <span class="poi-list__category"><?= e(t('trip.map.category.' . $poi['category'])) ?></span>
        <span class="poi-list__name"><?= e($poi['name']) ?></span>
        <?php if (!empty($poi['notes'])): ?>
          <p class="field-hint"><?= nl2br(e($poi['notes'])) ?></p>
        <?php endif; ?>

        <?php $media = $poiMedia[(int) $poi['id']] ?? ['photos' => [], 'videos' => []]; ?>
        <?php if ($media['photos'] !== [] || $media['videos'] !== []): ?>
          <ul class="poi-list__media">
            <?php foreach ($media['photos'] as $photo): ?>
              <li>
                <a href="/photos/<?= (int) $photo['id'] ?>/web" target="_blank" rel="noopener">
                  <img src="/photos/<?= (int) $photo['id'] ?>/thumb" alt="" loading="lazy">
                </a>
              </li>
            <?php endforeach; ?>
            <?php foreach ($media['videos'] as $video): ?>
              <li>
                <a href="/videos/<?= (int) $video['id'] ?>" target="_blank" rel="noopener">
                  <img src="/videos/<?= (int) $video['id'] ?>/poster" alt="" loading="lazy">
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($canEdit): ?>
          <form method="post" action="/pois/<?= (int) $poi['id'] ?>/visited" class="poi-list__actions">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-ghost btn-small">
              <?= $poi['visited'] ? e(t('trip.map.poi_mark_unvisited')) : e(t('trip.map.poi_mark_visited')) ?>
            </button>
          </form>
          <form method="post" action="/pois/<?= (int) $poi['id'] ?>/delete" class="poi-list__actions"
                data-confirm="<?= e(t('trip.map.poi_delete_confirm')) ?>">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-ghost btn-small"><?= e(t('trip.map.poi_delete')) ?></button>
          </form>
        <?php elseif ($poi['visited']): ?>
          <span class="poi-list__visited-badge"><?= e(t('trip.map.poi_visited_badge')) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($canEdit): ?>
  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois" class="map-view__poi-form" data-poi-add-form>
    <?= $csrf->field() ?>
    <div class="field">
      <label for="poi-name"><?= e(t('trip.map.poi_name_label')) ?></label>
      <input type="text" id="poi-name" name="name" required>
    </div>
    <div class="field">
      <label for="poi-category"><?= e(t('trip.map.poi_category_label')) ?></label>
      <select id="poi-category" name="category">
        <?php foreach ($categories as $category): ?>
          <option value="<?= e($category) ?>"><?= e(t('trip.map.category.' . $category)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="poi-visit-date"><?= e(t('trip.map.poi_date_label')) ?></label>
      <input type="date" id="poi-visit-date" name="visit_date">
    </div>
    <div class="field">
      <label for="poi-notes"><?= e(t('trip.map.poi_notes_label')) ?></label>
      <textarea id="poi-notes" name="notes"></textarea>
    </div>
    <div class="field">
      <button type="button" class="btn btn-ghost" data-poi-pick-on-map data-msg-picking="<?= e(t('trip.map.poi_pick_picking')) ?>"><?= e(t('trip.map.poi_pick_on_map')) ?></button>
      <span class="field-hint" data-poi-pick-status><?= e(t('trip.map.poi_pick_none')) ?></span>
      <input type="hidden" name="lat" data-poi-lat-input>
      <input type="hidden" name="lng" data-poi-lng-input>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('trip.map.poi_add')) ?></button>
  </form>
<?php endif; ?>

<div class="map-lightbox" data-map-lightbox hidden>
  <div class="map-lightbox__backdrop" data-map-lightbox-close></div>
  <div class="map-lightbox__panel">
    <button type="button" class="map-lightbox__close" data-map-lightbox-close aria-label="<?= e(t('trip.map.lightbox_close')) ?>">&times;</button>
    <div class="map-lightbox__body" data-map-lightbox-body></div>
  </div>
</div>

<script src="/assets/js/vendor/leaflet.js"></script>
<script src="/assets/js/trip-map.js"></script>
<?php if ($canEdit): ?>
  <script src="/assets/js/photo-geotag.js"></script>
  <script src="/assets/js/video-geotag.js"></script>
  <script src="/assets/js/track-folder-scan.js"></script>
<?php endif; ?>
