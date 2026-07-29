<?php
/** @var array<string, mixed>|null $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $trip !== null && isset($trip['id']);
$action = $isEdit ? '/trips/' . (int) $trip['id'] : '/trips';
?>

<h1><?= $isEdit ? e(t('trip.form.title_edit')) : e(t('trip.form.title_new')) ?></h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="title"><?= e(t('trip.form.title_label')) ?></label>
    <input type="text" id="title" name="title" required
           value="<?= e($trip['title'] ?? '') ?>" placeholder="<?= e(t('trip.form.title_placeholder')) ?>">
  </div>

  <div class="field">
    <label for="country"><?= e(t('trip.form.country_label')) ?></label>
    <input type="text" id="country" name="country" value="<?= e($trip['country'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="operator"><?= e(t('trip.form.operator_label')) ?></label>
    <input type="text" id="operator" name="operator" value="<?= e($trip['operator'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="date_start"><?= e(t('trip.form.start_label')) ?></label>
    <input type="date" id="date_start" name="date_start" value="<?= e($trip['date_start'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="date_end"><?= e(t('trip.form.end_label')) ?></label>
    <input type="date" id="date_end" name="date_end" value="<?= e($trip['date_end'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="description"><?= e(t('trip.form.description_label')) ?></label>
    <textarea id="description" name="description"><?= e($trip['description'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label><?= e(t('trip.form.visibility_label')) ?></label>
    <div class="field-radio-group">
      <label>
        <input type="radio" name="visibility" value="private"
          <?= (($trip['visibility'] ?? 'private') === 'private') ? 'checked' : '' ?>>
        <?= e(t('trip.form.visibility_private')) ?>
      </label>
      <label>
        <input type="radio" name="visibility" value="public"
          <?= (($trip['visibility'] ?? '') === 'public') ? 'checked' : '' ?>>
        <?= e(t('trip.form.visibility_public')) ?>
      </label>
    </div>
  </div>

  <h2><?= e(t('trip.form.route_heading')) ?></h2>
  <div data-station-list>
    <?php foreach ($stations as $station): ?>
      <div class="station-row">
        <div class="field">
          <label><?= e(t('trip.form.station_place')) ?></label>
          <input type="text" name="station_name[]" value="<?= e($station['name']) ?>" placeholder="<?= e(t('trip.form.station_place_placeholder')) ?>">
        </div>
        <div class="field">
          <label><?= e(t('trip.form.station_arrival')) ?></label>
          <input type="date" name="station_date[]" value="<?= e($station['arrival_date'] ?? '') ?>">
        </div>
        <button type="button" class="btn btn-ghost station-row__remove" data-station-remove><?= e(t('trip.form.station_remove')) ?></button>
      </div>
    <?php endforeach; ?>
  </div>

  <template data-station-template>
    <div class="station-row">
      <div class="field">
        <label><?= e(t('trip.form.station_place')) ?></label>
        <input type="text" name="station_name[]" placeholder="<?= e(t('trip.form.station_place_placeholder')) ?>">
      </div>
      <div class="field">
        <label><?= e(t('trip.form.station_arrival')) ?></label>
        <input type="date" name="station_date[]">
      </div>
      <button type="button" class="btn btn-ghost station-row__remove" data-station-remove><?= e(t('trip.form.station_remove')) ?></button>
    </div>
  </template>

  <button type="button" class="btn btn-ghost" data-station-add><?= e(t('trip.form.station_add')) ?></button>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('trip.form.save')) ?></button>
    <?php if ($isEdit): ?>
      <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('trip.form.cancel')) ?></a>
    <?php endif; ?>
  </div>
</form>

<script src="/assets/js/trip-form.js"></script>
