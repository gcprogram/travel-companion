<?php
/**
 * Per-date upload statistics table. Rendered inline by _photos_fields.php and
 * on its own by TripPhotoController::mediaStats() (polled by
 * trip-photo-upload.js after an upload, so counts/storage update without a
 * page reload).
 */
/** @var array{rows: list<array{date: string, photos: int, videos: int, bytes: int}>, photos: int, videos: int, bytes: int, pending: int}|null $mediaStats */
?>

<span data-stats-pending="<?= (int) ($mediaStats['pending'] ?? 0) ?>" hidden></span>
<?php if (!empty($mediaStats['rows'])): ?>
  <h3><?= e(t('trip.photos.stats_heading')) ?></h3>
  <table class="admin-table media-stats">
    <thead>
      <tr>
        <th><?= e(t('trip.photos.stats_date')) ?></th>
        <th><?= e(t('trip.photos.stats_photos')) ?></th>
        <th><?= e(t('trip.photos.stats_videos')) ?></th>
        <th><?= e(t('trip.photos.stats_storage')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($mediaStats['rows'] as $row): ?>
        <tr>
          <td><?= e(format_date($row['date'])) ?></td>
          <td><?= (int) $row['photos'] ?></td>
          <td><?= (int) $row['videos'] ?></td>
          <td><?= e(format_bytes($row['bytes'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th><?= e(t('trip.photos.stats_total')) ?></th>
        <th><?= (int) $mediaStats['photos'] ?></th>
        <th><?= (int) $mediaStats['videos'] ?></th>
        <th><?= e(format_bytes($mediaStats['bytes'])) ?></th>
      </tr>
    </tfoot>
  </table>
  <?php if (($mediaStats['pending'] ?? 0) > 0): ?>
    <p class="field-hint"><?= e(t('trip.photos.stats_pending', ['count' => $mediaStats['pending']])) ?></p>
  <?php endif; ?>
  <p class="field-hint"><?= e(t('trip.photos.stats_hint')) ?></p>
<?php endif; ?>
