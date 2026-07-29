<!doctype html>
<html lang="de">
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
          <a href="/reisen/neu">Neue Reise</a>
          <span><?= e($currentUser['name']) ?></span>
          <form method="post" action="/logout">
            <?= $csrf->field() ?>
            <button type="submit" class="btn btn-ghost">Abmelden</button>
          </form>
        <?php else: ?>
          <a href="/login">Anmelden</a>
        <?php endif; ?>
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
    <?= e($appName ?? 'Travel Companion') ?> — dein digitales Reisetagebuch.
  </footer>
</body>
</html>
