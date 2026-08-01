<?php
/** @var list<string> $roles */
/** @var list<string> $errors */
/** @var array<string, string> $old */
$errors ??= [];
$old ??= [];
?>

<h1><?= e(t('admin.user_add')) ?></h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/admin/users">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="name"><?= e(t('auth.register.name')) ?></label>
    <input type="text" id="name" name="name" required value="<?= e($old['name'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="email"><?= e(t('auth.register.email')) ?></label>
    <input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password"><?= e(t('auth.register.password')) ?></label>
    <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
    <p class="field-hint"><?= e(t('auth.register.password_hint')) ?></p>
  </div>

  <div class="field">
    <label for="role"><?= e(t('admin.col_role')) ?></label>
    <select id="role" name="role">
      <?php foreach ($roles as $role): ?>
        <option value="<?= e($role) ?>" <?= ($old['role'] ?? 'user') === $role ? 'selected' : '' ?>><?= e(t('admin.role.' . $role)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <p class="field-hint"><?= e(t('admin.user_add_hint')) ?></p>

  <button type="submit" class="btn btn-primary btn-block"><?= e(t('admin.user_add')) ?></button>
  <p class="field-hint"><a href="/admin/users">&larr; <?= e(t('admin.back_to_users')) ?></a></p>
</form>
