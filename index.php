<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$stmt = db()->prepare('SELECT id, title FROM activities WHERE id = ?');
$stmt->execute([PAIR_ACTIVITY_ID]);
$activity = $stmt->fetch();

$students = [1 => 'Student 1', 2 => 'Student 2'];

$pageTitle = 'Upload essay';
require __DIR__ . '/includes/head.php';
?>

<h1>Submit an essay</h1>
<p class="muted">
  Each student uploads a clear photo of their handwritten essay. Both essays are
  transcribed, compared against each other, and flagged for admin review when
  they match too closely.
</p>

<?php if (GOOGLE_VISION_API_KEY === '') : ?>
  <div class="box err">
    <b>OCR key not configured.</b> Copy <code>config.example.php</code> to
    <code>config.php</code> and add your Google Cloud Vision API key. Uploads are disabled until then.
  </div>
<?php endif; ?>

<div class="grid two">
<?php foreach ($students as $num => $label) : ?>
  <div class="card">
    <h2><?= e($label) ?></h2>
    <?php if ($activity !== false) : ?>
      <p class="muted">Activity: <?= e($activity['title']) ?></p>
      <form method="post" action="upload.php" enctype="multipart/form-data">
        <input type="hidden" name="activity_id" value="<?= (int)PAIR_ACTIVITY_ID ?>">
        <input type="hidden" name="student_name" value="<?= e($label) ?>">

        <div class="field">
          <label for="essay_image_<?= $num ?>">Handwritten essay photo (JPG / PNG / WEBP)</label>
          <input type="file" name="essay_image" id="essay_image_<?= $num ?>" accept="image/jpeg,image/png,image/webp" required>
          <div class="hint">Max <?= e(human_bytes(MAX_UPLOAD_BYTES)) ?>. In clear light, text filling the frame — blurry or unreadable photos are rejected.</div>
        </div>

        <button type="submit" class="btn primary">Upload essay</button>
      </form>
    <?php else : ?>
      <p class="muted">
        Activity #<?= (int)PAIR_ACTIVITY_ID ?> doesn't exist. Create it under
        <a href="activities.php">Activities</a> or update
        <code>PAIR_ACTIVITY_ID</code> in <code>config.php</code>.
      </p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<div class="card">
  <h2>Current scoring settings</h2>
  <table class="meta">
    <tr><th>Shingle size</th><td><?= (int)SHINGLE_SIZE ?> characters</td></tr>
    <tr><th>Method</th><td><?= e(strtoupper(SIM_METHOD)) ?> coefficient</td></tr>
    <tr><th>Flag threshold</th><td>similarity &ge; <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?></td></tr>
    <tr><th>OCR confidence cutoff</th><td>words below <?= e(sprintf('%.0f%%', CONFIDENCE_CUTOFF * 100)) ?> confidence are excluded from comparison</td></tr>
  </table>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>