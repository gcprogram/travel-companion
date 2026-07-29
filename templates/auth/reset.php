<?php
/** @var string $token */
/** @var list<string> $errors */
$errors ??= [];
?>

<h1>Neues Passwort setzen</h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/passwort-reset">
  <?= $csrf->field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">

  <div class="field">
    <label for="password">Neues Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="10">
    <p class="field-hint">Mindestens 10 Zeichen.</p>
  </div>

  <div class="field">
    <label for="password_repeat">Passwort wiederholen</label>
    <input type="password" id="password_repeat" name="password_repeat" required autocomplete="new-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block">Passwort speichern</button>
</form>
