<?php
/** @var list<string> $errors */
/** @var array<string, string> $old */
$errors ??= [];
$old ??= [];
?>

<h1><?= e(t('auth.register.title')) ?></h1>
<p class="field-hint"><?= e(t('auth.register.confirm_hint')) ?></p>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/register">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="name"><?= e(t('auth.register.name')) ?></label>
    <input type="text" id="name" name="name" required value="<?= e($old['name'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="email"><?= e(t('auth.register.email')) ?></label>
    <input type="email" id="email" name="email" required autocomplete="username"
           value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password"><?= e(t('auth.register.password')) ?></label>
    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="10">
    <p class="field-hint"><?= e(t('auth.register.password_hint')) ?></p>
  </div>

  <div class="field">
    <label for="password_repeat"><?= e(t('auth.register.password_repeat')) ?></label>
    <input type="password" id="password_repeat" name="password_repeat" required autocomplete="new-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.register.submit')) ?></button>

  <p class="field-hint"><?= e(t('auth.register.has_account')) ?> <a href="/login"><?= e(t('auth.register.login_link')) ?></a></p>
</form>
