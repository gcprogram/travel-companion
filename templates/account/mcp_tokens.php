<?php
/**
 * @var list<array<string, mixed>> $tokens
 * @var string|null $newToken
 * @var string $mcpEndpoint
 */
?>

<h1><?= e(t('account.mcp_heading')) ?></h1>
<p class="field-hint"><?= e(t('account.mcp_intro')) ?></p>
<p class="field-hint"><?= e(t('account.mcp_endpoint_label')) ?>: <code><?= e($mcpEndpoint) ?></code></p>

<?php if ($newToken !== null): ?>
  <div class="card">
    <p><strong><?= e(t('account.mcp_token_created')) ?></strong></p>
    <p class="field-hint"><?= e(t('account.mcp_token_shown_once')) ?></p>
    <p><code class="account-mcp__token"><?= e($newToken) ?></code></p>
  </div>
<?php endif; ?>

<form method="post" action="/account/mcp-tokens">
  <?= $csrf->field() ?>
  <div class="field">
    <label for="label"><?= e(t('account.mcp_token_label_field')) ?></label>
    <input type="text" id="label" name="label" placeholder="<?= e(t('account.mcp_token_label_placeholder')) ?>" maxlength="100">
  </div>
  <button type="submit" class="btn btn-primary"><?= e(t('account.mcp_token_create')) ?></button>
</form>

<h2><?= e(t('account.mcp_tokens_heading')) ?></h2>
<?php if ($tokens === []): ?>
  <p class="empty-state"><?= e(t('account.mcp_tokens_empty')) ?></p>
<?php else: ?>
  <ul class="account-mcp__list">
    <?php foreach ($tokens as $token): ?>
      <li class="account-mcp__item<?= $token['revoked_at'] !== null ? ' account-mcp__item--revoked' : '' ?>">
        <div>
          <strong><?= e($token['label']) ?></strong>
          <?php if ($token['revoked_at'] !== null): ?>
            <span class="trip-card__badge"><?= e(t('account.mcp_token_revoked_badge')) ?></span>
          <?php endif; ?>
          <p class="field-hint">
            <?= e(t('account.mcp_token_created_at', ['date' => format_datetime($token['created_at'])])) ?>
            <?php if ($token['last_used_at'] !== null): ?>
              · <?= e(t('account.mcp_token_last_used', ['date' => format_datetime($token['last_used_at'])])) ?>
            <?php else: ?>
              · <?= e(t('account.mcp_token_never_used')) ?>
            <?php endif; ?>
          </p>
        </div>
        <?php if ($token['revoked_at'] === null): ?>
          <form method="post" action="/account/mcp-tokens/<?= (int) $token['id'] ?>/revoke"
                data-confirm="<?= e(t('account.mcp_token_revoke_confirm')) ?>">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-ghost"><?= e(t('account.mcp_token_revoke')) ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
