<?php
/** @var string $message */
/** @var string $redirectTo */
?>

<div class="bounce-page">
  <p><?= e($message) ?></p>
  <p><a href="<?= e($redirectTo) ?>"><?= e(t('errors.bounce_continue')) ?></a></p>
</div>
