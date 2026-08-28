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
    <label for="tags"><?= e(t('trip.form.tags_label')) ?></label>
    <input type="text" id="tags" name="tags" value="<?= e($trip['tags'] ?? '') ?>"
           placeholder="<?= e(t('trip.form.tags_placeholder')) ?>">
    <p class="field-hint"><?= e(t('trip.form.tags_hint')) ?></p>
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

<?php if ($isEdit): ?>
  <?php /*
   * These two AI-suggestion forms must NOT sit inside the <form> above -
   * nested <form> elements are invalid HTML, and browsers resolve it by
   * dropping the inner <form> tag entirely while still processing its
   * </form> as closing whichever form is currently open. In practice that
   * meant the OUTER form (title/description/tags/visibility/Speichern)
   * got silently truncated right after the first nested form's content -
   * the Speichern button ended up outside any <form> at all and did
   * nothing on click. Found while chasing "KI-Reisebeschreibung
   * funktioniert nicht": generate-description was one victim of this,
   * but so was the whole metadata save button. Two independent sibling
   * forms after the real one, instead of nested inside it, fixes both.
   */ ?>
  <?php if (!empty($trip['ai_title_suggestion']) || !empty($trip['ai_tags_suggestion'])): ?>
    <div class="ai-summary">
      <p class="ai-summary__label"><?= e(t('trip.form.ai_suggestion_label')) ?></p>
      <?php if (!empty($trip['ai_title_suggestion'])): ?>
        <p class="ai-summary__text">
          <?= e(t('trip.form.ai_suggested_title')) ?>: <strong id="ai-title-text"><?= e($trip['ai_title_suggestion']) ?></strong>
          <button type="button" class="btn btn-ghost btn-small" data-ai-apply data-target="title"
                  data-source="ai-title-text"><?= e(t('entry.form.ai_summary_apply')) ?></button>
        </p>
      <?php endif; ?>
      <?php if (!empty($trip['ai_tags_suggestion'])): ?>
        <p class="ai-summary__text">
          <?= e(t('trip.form.ai_suggested_tags')) ?>: <strong id="ai-tags-text"><?= e($trip['ai_tags_suggestion']) ?></strong>
          <button type="button" class="btn btn-ghost btn-small" data-ai-apply data-target="tags"
                  data-source="ai-tags-text"><?= e(t('entry.form.ai_summary_apply')) ?></button>
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="field">
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/suggest-meta">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost btn-small"><?= e(t('trip.form.ai_suggest_generate')) ?></button>
      <p class="field-hint"><?= e(t('trip.form.ai_suggest_hint')) ?></p>
    </form>
  </div>

  <?php if (!empty($trip['ai_description_suggestion'])): ?>
    <div class="ai-summary">
      <p class="ai-summary__label"><?= e(t('trip.form.ai_suggestion_label')) ?></p>
      <p class="ai-summary__text">
        <?= e(t('trip.form.ai_suggested_description')) ?>:
        <span id="ai-description-text" class="ai-summary__multiline"><?= e($trip['ai_description_suggestion']) ?></span>
        <button type="button" class="btn btn-ghost btn-small" data-ai-apply data-target="description"
                data-source="ai-description-text"><?= e(t('entry.form.ai_summary_apply')) ?></button>
      </p>
    </div>
  <?php endif; ?>
  <div class="field">
    <form method="post" action="/trips/<?= (int) $trip['id'] ?>/suggest-description" class="ai-description-form">
      <?= $csrf->field() ?>
      <button type="submit" class="btn btn-ghost btn-small"><?= e(t('trip.form.ai_generate_description')) ?></button>
      <p class="field-hint"><?= e(t('trip.form.ai_description_hint')) ?></p>
    </form>
  </div>
<?php endif; ?>
