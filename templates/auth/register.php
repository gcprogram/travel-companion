<?php
/** @var list<string> $errors */
/** @var array<string, string> $old */
$errors ??= [];
$old ??= [];
?>

<h1>Konto erstellen</h1>

<?php if ($errors !== []): ?>
  <div class="errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form class="auth-form" method="post" action="/registrieren">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" required value="<?= e($old['name'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" required autocomplete="username"
           value="<?= e($old['email'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" required autocomplete="new-password" minlength="10">
    <p class="field-hint">Mindestens 10 Zeichen.</p>
  </div>

  <div class="field">
    <label for="password_repeat">Passwort wiederholen</label>
    <input type="password" id="password_repeat" name="password_repeat" required autocomplete="new-password">
  </div>

  <button type="submit" class="btn btn-primary btn-block">Konto erstellen</button>

  <p class="field-hint">Schon ein Konto? <a href="/login">Anmelden</a></p>
</form>
