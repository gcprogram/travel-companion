<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName ?? 'Travel Companion') ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="manifest" href="/manifest.json">
  <link rel="icon" href="/assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
  <meta name="theme-color" content="#2f6f5e">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="<?= e($appName ?? 'Travel Companion') ?>">
  <?= $headExtra ?? '' ?>
</head>
<body>
  <header class="site-header">
    <div class="container site-header__bar">
      <a class="site-header__brand" href="/">🧭 <?= e($appName ?? 'Travel Companion') ?></a>
      <nav class="site-header__nav">
        <?php if (!empty($currentUser)): ?>
          <a href="/trips/new"><?= e(t('nav.new_trip')) ?></a>
          <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="/admin/users"><?= e(t('nav.admin')) ?></a>
          <?php endif; ?>
          <a href="/my-trips"><?= e($currentUser['name']) ?></a>
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

  <script src="/assets/js/confirm-submit.js"></script>
  <script src="/assets/js/confirm-remember.js"></script>
  <script src="/assets/js/pwa-register.js"></script>
</body>
</html>
