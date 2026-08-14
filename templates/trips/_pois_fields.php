<?php
/**
 * Visited-places management: geocaching-GPX import, Overpass discovery,
 * the list itself, manual add form. Shared between the standalone wizard
 * step (pois.php) and the "Besuchte Orte" accordion section on the unified
 * edit page (manage.php) - expects the same variables pois.php's
 * controller (PoiController::index()) already provides.
 */
/** @var array<string, mixed> $trip */
/** @var bool $canEdit */
/** @var list<array<string, mixed>> $pois */
/** @var array<int, array{photos: list<array<string, mixed>>, videos: list<array<string, mixed>>}> $poiMedia */
/** @var array<int, array{distanceMeters: float, closestAt: ?string}> $poiApproach */
/** @var int $poiSearchRadius */
/** @var list<string> $poiSearchCategories */
/** @var list<string> $searchableCategories */
/** @var string $wizardQs */
$categories = ['museum', 'zoo', 'attraction', 'viewpoint', 'monument', 'sacred_building', 'other'];
?>

<?php if ($canEdit): ?>
  <h2><?= e(t('trip.map.geocaching_gpx_heading')) ?></h2>
  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/geocaching-gpx<?= e($wizardQs) ?>"
        enctype="multipart/form-data" class="poi-search-form" data-geocaching-gpx-form>
    <?= $csrf->field() ?>
    <div class="field">
      <label for="geocaching-gpx-file"><?= e(t('trip.map.geocaching_gpx_file_label')) ?></label>
      <input type="file" id="geocaching-gpx-file" name="geocaching_gpx" accept=".gpx,.zip,application/gpx+xml,application/zip">
    </div>
    <div class="field">
      <label for="geocaching-gpx-username"><?= e(t('trip.map.geocaching_gpx_username_label')) ?></label>
      <input type="text" id="geocaching-gpx-username" name="gc_username" data-geocaching-gpx-username>
      <p class="field-hint"><?= e(t('trip.map.geocaching_gpx_username_hint')) ?></p>
    </div>
    <div class="field">
      <label for="geocaching-field-notes"><?= e(t('trip.map.geocaching_field_notes_label')) ?></label>
      <input type="file" id="geocaching-field-notes" name="field_notes" accept=".txt,text/plain">
      <p class="field-hint"><?= e(t('trip.map.geocaching_field_notes_hint')) ?></p>
    </div>
    <button type="submit" class="btn btn-ghost"><?= e(t('trip.map.geocaching_gpx_import')) ?></button>
    <p class="field-hint"><?= e(t('trip.map.geocaching_gpx_hint')) ?></p>
  </form>
<?php endif; ?>

<h2><?= e(t('trip.map.poi_heading')) ?></h2>

<?php if ($canEdit): ?>
  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/discover<?= e($wizardQs) ?>" class="poi-search-form">
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
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois/prune<?= e($wizardQs) ?>" class="page-actions"
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
      <li class="poi-list__item<?= $poi['visited'] ? ' poi-list__item--visited' : '' ?>" data-delete-item>
        <?php $approach = $poiApproach[(int) $poi['id']] ?? null; ?>
        <?php if ($approach !== null && $approach['closestAt'] !== null): ?>
          <span class="poi-list__approach"><?= e(format_short_datetime($approach['closestAt'])) ?></span>
        <?php endif; ?>
        <?php if ($poi['category'] === 'geocache'): ?>
          <span class="poi-list__category">
            <img class="poi-list__cache-icon" src="<?= e(cache_type_icon_url($poi['cache_type'])) ?>"
                 alt="<?= e((string) $poi['cache_type']) ?>" width="20" height="20">
            <?= e((string) $poi['cache_type']) ?>
          </span>
        <?php else: ?>
          <span class="poi-list__category"><?= e(t('trip.map.category.' . $poi['category'])) ?></span>
        <?php endif; ?>
        <span class="poi-list__name">
          <?php if ($poi['category'] === 'geocache' && !empty($poi['gc_code'])): ?>
            <a href="https://coord.info/<?= e((string) $poi['gc_code']) ?>" target="_blank" rel="noopener"><?= e((string) $poi['gc_code']) ?></a> -
          <?php endif; ?>
          <?= e($poi['name']) ?>
          <?php if ($poi['category'] === 'geocache' && ($poi['difficulty'] !== null || $poi['terrain'] !== null)): ?>
            <span class="poi-list__distance">(D<?= e(number_format((float) ($poi['difficulty'] ?? 0), 1)) ?>/T<?= e(number_format((float) ($poi['terrain'] ?? 0), 1)) ?>)</span>
          <?php endif; ?>
          <?php if ($poi['category'] === 'geocache' && $poi['geocache_status'] === 'dnf'): ?>
            <span class="poi-list__distance poi-list__dnf-badge"><?= e(t('trip.map.geocaching_gpx_dnf_badge')) ?></span>
          <?php endif; ?>
          <?php if ($approach !== null): ?>
            <span class="poi-list__distance">(<?= (int) round($approach['distanceMeters']) ?> m)</span>
          <?php endif; ?>
        </span>
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
          <span class="poi-list__actions">
            <form method="post" action="/pois/<?= (int) $poi['id'] ?>/visited<?= e($wizardQs) ?>" class="poi-list__action-form">
              <?= $csrf->field() ?>
              <button type="submit"
                      class="poi-list__icon-btn poi-list__icon-btn--visited<?= $poi['visited'] ? ' is-active' : '' ?>"
                      title="<?= $poi['visited'] ? e(t('trip.map.poi_mark_unvisited')) : e(t('trip.map.poi_mark_visited')) ?>"
                      aria-label="<?= $poi['visited'] ? e(t('trip.map.poi_mark_unvisited')) : e(t('trip.map.poi_mark_visited')) ?>">&#10003;</button>
            </form>
            <form method="post" action="/pois/<?= (int) $poi['id'] ?>/delete<?= e($wizardQs) ?>" class="poi-list__action-form"
                  data-confirm-group="poi_delete" data-delete-inline
                  data-confirm-message="<?= e(t('trip.map.poi_delete_confirm')) ?>"
                  data-confirm-yes="<?= e(t('entry.form.confirm_yes')) ?>"
                  data-confirm-no="<?= e(t('entry.form.confirm_no')) ?>"
                  data-confirm-all="<?= e(t('entry.form.confirm_all')) ?>">
              <?= $csrf->field() ?>
              <button type="submit" class="poi-list__icon-btn poi-list__icon-btn--delete"
                      title="<?= e(t('trip.map.poi_delete')) ?>" aria-label="<?= e(t('trip.map.poi_delete')) ?>">&#10005;</button>
            </form>
          </span>
        <?php elseif ($poi['visited']): ?>
          <span class="poi-list__visited-badge"><?= e(t('trip.map.poi_visited_badge')) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($canEdit): ?>
  <form method="post" action="/trips/<?= (int) $trip['id'] ?>/pois<?= e($wizardQs) ?>" class="map-view__poi-form" data-poi-add-form>
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
