<?php
/** @var list<array<string, mixed>> $rows */
/** @var list<string> $roles */
/** @var int $currentUserId */
?>

<h1><?= e(t('admin.users_title')) ?></h1>

<p class="page-actions">
  <a class="btn btn-primary" href="/admin/users/new"><?= e(t('admin.user_add')) ?></a>
  <a class="btn btn-ghost" href="/admin/settings"><?= e(t('admin.settings_link')) ?></a>
</p>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th><?= e(t('admin.col_user')) ?></th>
        <th><?= e(t('admin.col_role')) ?></th>
        <th><?= e(t('admin.col_status')) ?></th>
        <th><?= e(t('admin.col_registered')) ?></th>
        <th><?= e(t('admin.col_last_login')) ?></th>
        <th><?= e(t('admin.col_logins')) ?></th>
        <th><?= e(t('admin.col_storage')) ?></th>
        <th><?= e(t('admin.col_trips')) ?></th>
        <th><?= e(t('admin.col_entries')) ?></th>
        <th><?= e(t('admin.col_photos')) ?></th>
        <th><?= e(t('admin.col_videos')) ?></th>
        <th><?= e(t('admin.col_tracks')) ?></th>
        <th><?= e(t('admin.col_ai_tokens')) ?></th>
        <th><?= e(t('admin.col_actions')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $user = $row['user']; $userId = (int) $user['id']; $isSelf = $userId === $currentUserId; ?>
        <tr>
          <td>
            <strong><?= e($user['name']) ?></strong><br>
            <span class="field-hint"><?= e($user['email']) ?></span>
          </td>
          <td>
            <form method="post" action="/admin/users/<?= $userId ?>/role" class="admin-table__inline-form">
              <?= $csrf->field() ?>
              <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
                <?php foreach ($roles as $role): ?>
                  <option value="<?= e($role) ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= e(t('admin.role.' . $role)) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$isSelf): ?>
                <button type="submit" class="btn btn-ghost btn-small"><?= e(t('admin.save')) ?></button>
              <?php endif; ?>
            </form>
          </td>
          <td><span class="admin-status admin-status--<?= e($row['status']) ?>"><?= e(t('admin.status.' . $row['status'])) ?></span></td>
          <td><?= e(format_datetime($user['created_at'])) ?></td>
          <td><?= $user['last_login_at'] !== null ? e(format_datetime($user['last_login_at'])) : '—' ?></td>
          <td><?= (int) $user['login_count'] ?></td>
          <td>
            <?= e(format_bytes((int) $row['storageUsedBytes'])) ?>
            / <?= $row['storageQuotaBytes'] !== null ? e(format_bytes((int) $row['storageQuotaBytes'])) : e(t('admin.unlimited')) ?>
          </td>
          <td><?= (int) $row['tripCount'] ?></td>
          <td><?= (int) $row['entryCount'] ?></td>
          <td><?= (int) $row['photoCount'] ?></td>
          <td><?= (int) $row['videoCount'] ?></td>
          <td><?= (int) $row['trackCount'] ?></td>
          <td><?= number_format((int) $user['ai_tokens_used'], 0, ',', '.') ?></td>
          <td class="admin-table__actions">
            <?php if ($row['status'] === 'pending_approval'): ?>
              <form method="post" action="/admin/users/<?= $userId ?>/approve">
                <?= $csrf->field() ?>
                <button type="submit" class="btn btn-ghost btn-small"><?= e(t('admin.approve')) ?></button>
              </form>
            <?php elseif (!$isSelf): ?>
              <form method="post" action="/admin/users/<?= $userId ?>/active">
                <?= $csrf->field() ?>
                <input type="hidden" name="active" value="<?= $user['is_active'] ? '0' : '1' ?>">
                <button type="submit" class="btn btn-ghost btn-small">
                  <?= $user['is_active'] ? e(t('admin.deactivate')) : e(t('admin.reactivate')) ?>
                </button>
              </form>
            <?php endif; ?>

            <details class="admin-table__more">
              <summary><?= e(t('admin.more')) ?></summary>

              <form method="post" action="/admin/users/<?= $userId ?>/quota" class="admin-table__inline-form">
                <?= $csrf->field() ?>
                <label>
                  <?= e(t('admin.quota_override_label')) ?>
                  <input type="number" name="bytes" min="0" step="1" placeholder="<?= e(t('admin.quota_override_placeholder')) ?>">
                </label>
                <button type="submit" class="btn btn-ghost btn-small"><?= e(t('admin.save')) ?></button>
              </form>

              <?php if (!$isSelf): ?>
                <form method="post" action="/admin/users/<?= $userId ?>/transfer" class="admin-table__inline-form">
                  <?= $csrf->field() ?>
                  <label>
                    <?= e(t('admin.transfer_label')) ?>
                    <select name="target_user_id">
                      <?php foreach ($rows as $other): ?>
                        <?php if ((int) $other['user']['id'] !== $userId): ?>
                          <option value="<?= (int) $other['user']['id'] ?>"><?= e($other['user']['name']) ?></option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <button type="submit" class="btn btn-ghost btn-small"
                          data-confirm="<?= e(t('admin.transfer_confirm')) ?>"><?= e(t('admin.transfer_action')) ?></button>
                </form>

                <form method="post" action="/admin/users/<?= $userId ?>/delete" class="admin-table__inline-form">
                  <?= $csrf->field() ?>
                  <button type="submit" class="btn btn-danger btn-small"
                          data-confirm="<?= e(t('admin.delete_confirm')) ?>"><?= e(t('admin.delete_action')) ?></button>
                </form>
              <?php endif; ?>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
