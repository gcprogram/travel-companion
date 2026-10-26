<?php
/**
 * Share-link management (view/edit tokens). Section "Teilen" of the trip setup
 * page (manage.php) - owner/admin only ($canManageSharing).
 */
/** @var array<string, mixed> $trip */
/** @var bool $canManageSharing */
/** @var list<array<string, mixed>> $shareTokens */
/** @var string $shareBaseUrl */
?>

<?php if ($canManageSharing): ?>
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
<?php else: ?>
  <p class="field-hint"><?= e(t('trip.manage.share_owner_only')) ?></p>
<?php endif; ?>
