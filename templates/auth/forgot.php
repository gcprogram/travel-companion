<h1><?= e(t('auth.forgot.title')) ?></h1>

<p><?= e(t('auth.forgot.intro')) ?></p>

<form class="auth-form" method="post" action="/forgot-password">
  <?= $csrf->field() ?>

  <div class="field">
    <label for="email"><?= e(t('auth.forgot.email')) ?></label>
    <input type="email" id="email" name="email" required autocomplete="username">
  </div>

  <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.forgot.submit')) ?></button>
</form>
