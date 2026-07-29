<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName ?? 'Travel Companion') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="container site-header__bar">
      <a class="site-header__brand" href="/">🧭 <?= e($appName ?? 'Travel Companion') ?></a>
      <nav class="site-header__nav">
        <?php if (!empty($currentUser)): ?>
          <a href="/trips/new"><?= e(t('nav.new_trip')) ?></a>
          <span><?= e($currentUser['name']) ?></span>
          <form method="post" action="/logout">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-ghost"><?= e(t('nav.logout')) ?></button>
          </form>
        <?php else: ?>
          <a href="/login"><?= e(t('nav.login')) ?></a>
        <?php endif; ?>
        <div class="lang-switch">
          <?php foreach (\App\Support\Translator::supportedLocales() as $locale): ?>
            <a href="/lang/<?= e($locale) ?>" class="<?= current_locale() === $locale ? 'is-active' : '' ?>"><?= e(strtoupper($locale)) ?></a>
          <?php endforeach; ?>
        </div>
      </nav>
    </div>
  </header>

  <main class="container">
    <?php foreach ($flash->pull() as $message): ?>
      <div class="flash flash-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
    <?php endforeach; ?>

    <?= $content ?>
  </main>

  <footer class="site-footer">
    <?= e($appName ?? 'Travel Companion') ?> — <?= e(t('footer.tagline')) ?>
  </footer>
</body>
</html>
