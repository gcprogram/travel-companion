<h1>Passwort vergessen</h1>

<p>Gib deine E-Mail-Adresse ein. Wenn dazu ein Konto existiert, schicken wir dir einen Link zum Zurücksetzen.</p>

<form class="auth-form" method="post" action="/passwort-vergessen">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" required autocomplete="username">
  </div>

  <button type="submit" class="btn btn-primary btn-block">Link anfordern</button>
</form>
