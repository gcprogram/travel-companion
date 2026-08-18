<?php
/** @var array<string, mixed>|null $trip */
/** @var list<string> $errors */
$isEdit = $trip !== null && isset($trip['id']);
?>

<h1><?= $isEdit ? e(t('trip.form.title_edit')) : e(t('trip.form.title_new')) ?></h1>

<?php include __DIR__ . '/_metadata_fields.php'; ?>

<script src="/assets/js/trip-metadata-ai.js"></script>
