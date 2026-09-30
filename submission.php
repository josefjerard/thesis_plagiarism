<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT s.*, a.title AS activity_title
     FROM submissions s JOIN activities a ON a.id = s.activity_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sub = $stmt->fetch();
if ($sub === false) {
    exit('Submission not found.');
}

$imageUrl = 'uploads/' . rawurlencode($sub['image_path']);
$pageTitle = 'Submission #' . $id;
require __DIR__ . '/includes/head.php';
?>

<h1>Submission #<?= (int)$sub['id'] ?> — <?= e($sub['student_name']) ?></h1>
<p class="muted">
  Activity: <?= e($sub['activity_title']) ?> &middot;
  Status: <?= e($sub['status']) ?> &middot;
  Submitted: <?= e($sub['created_at']) ?>
</p>

<div class="grid two">
  <div class="card">
    <h2>Image</h2>
    <a href="<?= e($imageUrl) ?>" target="_blank">
      <img src="<?= e($imageUrl) ?>" alt="Submitted essay" class="paper">
    </a>
  </div>
  <div class="card">
    <h2>Stats</h2>
    <table class="meta">
      <tr><th>Words detected</th><td><?= (int)$sub['word_count'] ?></td></tr>
      <tr><th>Low-confidence words dropped</th><td><?= (int)$sub['low_conf_words'] ?></td></tr>
      <tr><th>Average confidence</th><td><?= e(sprintf('%.0f%%', $sub['avg_confidence'] * 100)) ?></td></tr>
    </table>
  </div>
</div>

<div class="card">
  <h2>Raw OCR transcription <span class="badge">all words</span></h2>
  <pre class="text-out"><?= e($sub['raw_text']) ?></pre>
</div>

<div class="card">
  <h2>Text used for similarity <span class="badge">confidence &ge; <?= e(sprintf('%.0f%%', CONFIDENCE_CUTOFF * 100)) ?></span></h2>
  <pre class="text-out"><?= e($sub['confident_text']) ?></pre>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>