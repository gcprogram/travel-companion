<?php
/** @var array<string, mixed> $trip */
/** @var array<string, mixed>|null $entry */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $entry !== null && isset($entry['id']);
$action = $isEdit ? '/entries/' . (int) $entry['id'] : '/trips/' . (int) $trip['id'] . '/entries';
$moods = ['very_bad', 'bad', 'neutral', 'good', 'very_good'];
$lat = $entry['lat'] ?? null;
$lng = $entry['lng'] ?? null;
?>

<h1><?= $isEdit ? e(t('entry.form.title_edit')) : e(t('entry.form.title_new')) ?></h1>
<p class="field-hint"><?= e($trip['title']) ?></p>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="entry_date"><?= e(t('entry.form.date_label')) ?></label>
    <input type="date" id="entry_date" name="entry_date" required
           value="<?= e($entry['entry_date'] ?? date('Y-m-d')) ?>">
  </div>

  <div class="field">
    <label for="title"><?= e(t('entry.form.title_label')) ?></label>
    <input type="text" id="title" name="title" value="<?= e($entry['title'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="body"><?= e(t('entry.form.body_label')) ?></label>
    <textarea id="body" name="body" required><?= e($entry['body'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label><?= e(t('entry.form.mood_label')) ?></label>
    <div class="field-radio-group field-radio-group--mood">
      <?php foreach ($moods as $value): ?>
        <label>
          <input type="radio" name="mood" value="<?= e($value) ?>"
            <?= (($entry['mood'] ?? null) === $value) ? 'checked' : '' ?>>
          <?= mood_emoji($value) ?> <?= e(t('mood.' . $value)) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="field">
    <label><?= e(t('entry.form.rating_label')) ?></label>
    <div class="rating-input">
      <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>"
          <?= ((int) ($entry['rating'] ?? 0) === $i) ? 'checked' : '' ?>>
        <label for="rating-<?= $i ?>">★</label>
      <?php endfor; ?>
    </div>
  </div>

  <div class="field">
    <label><?= e(t('entry.form.location_label')) ?></label>
    <div class="location-input">
      <button type="button" class="btn btn-ghost" data-geolocate
              data-msg-unsupported="<?= e(t('entry.form.geo_unsupported')) ?>"
              data-msg-locating="<?= e(t('entry.form.geo_locating')) ?>"
              data-msg-error="<?= e(t('entry.form.geo_error')) ?>"
              data-msg-captured="<?= e(t('entry.form.location_captured')) ?>">📍 <?= e(t('entry.form.locate_button')) ?></button>
      <span class="field-hint" data-geolocate-status>
        <?= $lat !== null
          ? e(t('entry.form.location_captured', ['lat' => (string) $lat, 'lng' => (string) $lng]))
          : e(t('entry.form.location_none')) ?>
      </span>
      <input type="hidden" id="lat" name="lat" value="<?= e((string) ($lat ?? '')) ?>">
      <input type="hidden" id="lng" name="lng" value="<?= e((string) ($lng ?? '')) ?>">
    </div>
  </div>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('entry.form.save')) ?></button>
    <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('entry.form.cancel')) ?></a>
  </div>
</form>

<script src="/assets/js/day-entry-form.js"></script>
