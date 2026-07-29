<?php
/** @var list<string> $errors */
/** @var array<string, string> $old */
$errors ??= [];
$old ??= [];
?>

<h1>Anmelden</h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/login">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" required autocomplete="username"
           value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block">Anmelden</button>

  <p class="field-hint">
    <a href="/passwort-vergessen">Passwort vergessen?</a> ·
    <a href="/registrieren">Konto erstellen</a>
  </p>
</form>
