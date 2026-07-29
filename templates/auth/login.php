<?php
/** @var list<string> $errors */
/** @var array<string, string> $old */
$errors ??= [];
$old ??= [];
?>

<h1><?= e(t('auth.login.title')) ?></h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/login">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="email"><?= e(t('auth.login.email')) ?></label>
    <input type="email" id="email" name="email" required autocomplete="username"
           value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password"><?= e(t('auth.login.password')) ?></label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.login.submit')) ?></button>

  <p class="field-hint">
    <a href="/forgot-password"><?= e(t('auth.login.forgot')) ?></a> ·
    <a href="/register"><?= e(t('auth.login.register')) ?></a>
  </p>
</form>
