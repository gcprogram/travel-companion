<?php
/** @var string $token */
/** @var list<string> $errors */
$errors ??= [];
?>

<h1><?= e(t('auth.reset.title')) ?></h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/reset-password">
  <?= $csrf->field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">

  <div class="field">
    <label for="password"><?= e(t('auth.reset.password')) ?></label>
    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="10">
    <p class="field-hint"><?= e(t('auth.reset.password_hint')) ?></p>
  </div>

  <div class="field">
    <label for="password_repeat"><?= e(t('auth.reset.password_repeat')) ?></label>
    <input type="password" id="password_repeat" name="password_repeat" required autocomplete="new-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.reset.submit')) ?></button>
</form>
