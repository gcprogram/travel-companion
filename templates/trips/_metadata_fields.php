<?php
/**
 * Trip metadata form (title/description/visibility). Shared between the
 * standalone create/edit page (form.php) and the "Metadaten" accordion
 * section on the unified edit page (manage.php) - expects $trip, $errors,
 * $csrf (global), same as form.php's own controller (TripController).
 */
/** @var array<string, mixed>|null $trip */
/** @var list<string> $errors */
$errors ??= [];
$isEdit = $trip !== null && isset($trip['id']);
$action = $isEdit ? '/trips/' . (int) $trip['id'] : '/trips';
?>

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
    <label for="description"><?= e(t('trip.form.description_label')) ?></label>
    <textarea id="description" name="description"><?= e($trip['description'] ?? '') ?></textarea>
    <p class="field-hint"><?= e(t('trip.form.description_hint')) ?></p>
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
        <input type="radio" name="visibility" value="member_only"
          <?= (($trip['visibility'] ?? '') === 'member_only') ? 'checked' : '' ?>>
        <?= e(t('trip.form.visibility_member_only')) ?>
      </label>
      <label>
        <input type="radio" name="visibility" value="public"
          <?= (($trip['visibility'] ?? '') === 'public') ? 'checked' : '' ?>>
        <?= e(t('trip.form.visibility_public')) ?>
      </label>
    </div>
    <?php if ($isEdit): ?>
      <p class="field-hint">
        <a href="/trip/<?= e($trip['slug']) ?>#share-tokens"><?= e(t('trip.form.visibility_shared_link')) ?></a>
      </p>
    <?php endif; ?>
  </div>

  <?php if (!$isEdit): ?>
    <p class="field-hint"><?= e(t('trip.form.auto_metadata_hint')) ?></p>
  <?php endif; ?>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary"><?= e(t('trip.form.save')) ?></button>
    <?php if ($isEdit): ?>
      <a class="btn btn-ghost" href="/trip/<?= e($trip['slug']) ?>"><?= e(t('trip.form.cancel')) ?></a>
    <?php endif; ?>
  </div>
</form>
