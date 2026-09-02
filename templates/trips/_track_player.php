<?php
/**
 * Track-Player (Stefan's idea, PLAN.md): a play button below the big
 * interactive map that animates playback of the GPS track. Shared between
 * the standalone map page (map.php) and the "Route" section of the
 * unified edit page (manage.php) - both render the identical #trip-map
 * canvas trip-map.js builds on, so this partial (unlike those two
 * templates' own long-standing markup duplication) starts out shared
 * rather than copy-pasted, since there's no legacy reason to split it.
 *
 * Visible to every viewer (Stefan's ask: also on the public/shared view),
 * not gated by $canEdit - only the "exit and edit this point" link at the
 * end of a playthrough needs edit rights, and track-player.js hides that
 * one button itself based on data-route-edit-url being empty.
 *
 * @var array<string, mixed> $trip
 * @var bool $canEdit
 * @var array<string, string> $trackPlayerConfig
 */
?>
<div class="track-player" data-track-player
     data-config="<?= e(json_encode($trackPlayerConfig)) ?>"
     data-route-edit-url="<?= $canEdit ? e('/trip/' . $trip['slug'] . '/route-edit') : '' ?>"
     data-msg-no-nearby="<?= e(t('trip.map.trackplayer_no_nearby')) ?>"
     data-msg-distance="<?= e(t('trip.map.trackplayer_distance')) ?>">
  <button type="button" class="btn btn-ghost" data-track-player-start>&#9654; <?= e(t('trip.map.trackplayer_start')) ?></button>
  <div class="track-player__toolbar" data-track-player-toolbar hidden>
    <button type="button" class="route-edit__nav-btn" data-track-player-prev
            aria-label="<?= e(t('trip.map.trackplayer_prev')) ?>" title="<?= e(t('trip.map.trackplayer_prev')) ?>">&#9664;</button>
    <button type="button" class="route-edit__icon-btn" data-track-player-toggle
            data-msg-pause="<?= e(t('trip.map.trackplayer_pause')) ?>" data-msg-resume="<?= e(t('trip.map.trackplayer_resume')) ?>"
            aria-label="<?= e(t('trip.map.trackplayer_pause')) ?>" title="<?= e(t('trip.map.trackplayer_pause')) ?>">&#9208;</button>
    <button type="button" class="route-edit__nav-btn" data-track-player-next
            aria-label="<?= e(t('trip.map.trackplayer_next')) ?>" title="<?= e(t('trip.map.trackplayer_next')) ?>">&#9654;</button>
    <button type="button" class="route-edit__icon-btn" data-track-player-poi
            aria-label="<?= e(t('trip.map.trackplayer_poi')) ?>" title="<?= e(t('trip.map.trackplayer_poi')) ?>">&#128205;</button>
    <span class="track-player__time" data-track-player-time></span>
    <button type="button" class="route-edit__icon-btn" data-track-player-exit
            aria-label="<?= e(t('trip.map.trackplayer_exit')) ?>" title="<?= e(t('trip.map.trackplayer_exit')) ?>">&#10005;</button>
  </div>
  <?php if ($canEdit): ?>
    <p class="field-hint" data-track-player-edit-hint hidden><?= e(t('trip.map.trackplayer_exit_edit_hint')) ?></p>
  <?php endif; ?>
  <p class="field-hint" data-track-player-poi-card hidden></p>
</div>
