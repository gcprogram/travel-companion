<?php
/** @var string $title */
/** @var string $message */
/** @var string $homeLink */
?>

<h1><?= e($title) ?></h1>
<p><?= e($message) ?></p>

<p class="page-actions">
  <a class="btn btn-primary" href="<?= e($homeLink) ?>"><?= e(t('error.back_link')) ?></a>
</p>
