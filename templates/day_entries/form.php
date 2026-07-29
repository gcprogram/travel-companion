<?php
/** @var array<string, mixed> $trip */
/** @var array<string, mixed>|null $entry */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $entry !== null && isset($entry['id']);
$action = $isEdit ? '/tagebuch/' . (int) $entry['id'] : '/reisen/' . (int) $trip['id'] . '/tagebuch';
$moods = [
    'sehr_schlecht' => '😞 Sehr schlecht',
    'schlecht' => '🙁 Schlecht',
    'neutral' => '😐 Neutral',
    'gut' => '🙂 Gut',
    'sehr_gut' => '😄 Sehr gut',
];
$lat = $entry['lat'] ?? null;
$lng = $entry['lng'] ?? null;
?>

<h1><?= $isEdit ? 'Tagebucheintrag bearbeiten' : 'Neuer Tagebucheintrag' ?></h1>
<p class="field-hint"><?= e($trip['title']) ?></p>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="entry_date">Datum</label>
    <input type="date" id="entry_date" name="entry_date" required
           value="<?= e($entry['entry_date'] ?? date('Y-m-d')) ?>">
  </div>

  <div class="field">
    <label for="title">Titel (optional)</label>
    <input type="text" id="title" name="title" value="<?= e($entry['title'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="body">Was ist heute passiert?</label>
    <textarea id="body" name="body" required><?= e($entry['body'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label>Stimmung</label>
    <div class="field-radio-group field-radio-group--mood">
      <?php foreach ($moods as $value => $label): ?>
        <label>
          <input type="radio" name="mood" value="<?= e($value) ?>"
            <?= (($entry['mood'] ?? null) === $value) ? 'checked' : '' ?>>
          <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="field">
    <label>Bewertung</label>
    <div class="rating-input">
      <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>"
          <?= ((int) ($entry['rating'] ?? 0) === $i) ? 'checked' : '' ?>>
        <label for="rating-<?= $i ?>">★</label>
      <?php endfor; ?>
    </div>
  </div>

  <div class="field">
    <label>Standort</label>
    <div class="location-input">
      <button type="button" class="btn btn-ghost" data-geolocate>📍 Standort erfassen</button>
      <span class="field-hint" data-geolocate-status>
        <?= $lat !== null ? 'Erfasst: ' . e((string) $lat) . ', ' . e((string) $lng) : 'Noch kein Standort erfasst.' ?>
      </span>
      <input type="hidden" id="lat" name="lat" value="<?= e((string) ($lat ?? '')) ?>">
      <input type="hidden" id="lng" name="lng" value="<?= e((string) ($lng ?? '')) ?>">
    </div>
  </div>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary">Speichern</button>
    <a class="btn btn-ghost" href="/reise/<?= e($trip['slug']) ?>">Abbrechen</a>
  </div>
</form>

<script src="/assets/js/day-entry-form.js"></script>
