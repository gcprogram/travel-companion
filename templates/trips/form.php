<?php
/** @var array<string, mixed>|null $trip */
/** @var list<array<string, mixed>> $stations */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $trip !== null && isset($trip['id']);
$action = $isEdit ? '/reisen/' . (int) $trip['id'] : '/reisen';
?>

<h1><?= $isEdit ? 'Reise bearbeiten' : 'Neue Reise' ?></h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required
           value="<?= e($trip['title'] ?? '') ?>" placeholder="z.B. Norwegen 2026">
  </div>

  <div class="field">
    <label for="country">Land</label>
    <input type="text" id="country" name="country" value="<?= e($trip['country'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="operator">Reiseveranstalter</label>
    <input type="text" id="operator" name="operator" value="<?= e($trip['operator'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="date_start">Beginn</label>
    <input type="date" id="date_start" name="date_start" value="<?= e($trip['date_start'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="date_end">Ende</label>
    <input type="date" id="date_end" name="date_end" value="<?= e($trip['date_end'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="description">Beschreibung</label>
    <textarea id="description" name="description"><?= e($trip['description'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label>Sichtbarkeit</label>
    <div class="field-radio-group">
      <label>
        <input type="radio" name="visibility" value="private"
          <?= (($trip['visibility'] ?? 'private') === 'private') ? 'checked' : '' ?>>
        Privat
      </label>
      <label>
        <input type="radio" name="visibility" value="public"
          <?= (($trip['visibility'] ?? '') === 'public') ? 'checked' : '' ?>>
        Öffentlich
      </label>
    </div>
  </div>

  <h2>Route</h2>
  <div data-station-list>
    <?php foreach ($stations as $station): ?>
      <div class="station-row">
        <div class="field">
          <label>Ort</label>
          <input type="text" name="station_name[]" value="<?= e($station['name']) ?>" placeholder="z.B. Flåm">
        </div>
        <div class="field">
          <label>Ankunft</label>
          <input type="date" name="station_date[]" value="<?= e($station['arrival_date'] ?? '') ?>">
        </div>
        <button type="button" class="btn btn-ghost station-row__remove" data-station-remove>Entfernen</button>
      </div>
    <?php endforeach; ?>
  </div>

  <template data-station-template>
    <div class="station-row">
      <div class="field">
        <label>Ort</label>
        <input type="text" name="station_name[]" placeholder="z.B. Flåm">
      </div>
      <div class="field">
        <label>Ankunft</label>
        <input type="date" name="station_date[]">
      </div>
      <button type="button" class="btn btn-ghost station-row__remove" data-station-remove>Entfernen</button>
    </div>
  </template>

  <button type="button" class="btn btn-ghost" data-station-add>+ Station hinzufügen</button>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary">Speichern</button>
    <?php if ($isEdit): ?>
      <a class="btn btn-ghost" href="/reise/<?= e($trip['slug']) ?>">Abbrechen</a>
    <?php endif; ?>
  </div>
</form>

<script src="/assets/js/trip-form.js"></script>
