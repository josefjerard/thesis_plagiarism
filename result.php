<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

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

$compStmt = db()->prepare(
    'SELECT c.*,
            s1.student_name AS name_a, s1.image_path AS image_a,
            s2.student_name AS name_b, s2.image_path AS image_b
     FROM comparisons c
     JOIN submissions s1 ON s1.id = c.submission_a
     JOIN submissions s2 ON s2.id = c.submission_b
     WHERE c.submission_a = ? OR c.submission_b = ?
     ORDER BY c.similarity DESC'
);
$compStmt->execute([$id, $id]);
$comparisons = $compStmt->fetchAll();

$flaggedCount = 0;
foreach ($comparisons as $c) {
    if ($c['similarity'] >= FLAG_THRESHOLD) {
        $flaggedCount++;
    }
}

$imageUrl = 'uploads/' . rawurlencode($sub['image_path']);
$pageTitle = 'Result';
require __DIR__ . '/includes/head.php';
?>

<?php if ($flaggedCount > 0) : ?>
  <div class="box warn">
    <b>Flagged.</b> This submission matches another essay at or above the
    <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?> threshold
    (<?= $flaggedCount ?> pair<?= $flaggedCount === 1 ? '' : 's' ?>). Sent to the admin review queue.
  </div>
<?php else : ?>
  <div class="box ok">No flagged matches at the current threshold.</div>
<?php endif; ?>

<h1>Submission #<?= (int)$sub['id'] ?> — <?= e($sub['student_name']) ?></h1>
<p class="muted">Activity: <?= e($sub['activity_title']) ?> &middot; submitted <?= e($sub['created_at']) ?></p>

<div class="grid two">
  <div class="card">
    <h2>Uploaded image</h2>
    <a href="<?= e($imageUrl) ?>" target="_blank">
      <img src="<?= e($imageUrl) ?>" alt="Submitted essay" class="paper">
    </a>
  </div>
  <div class="card">
    <h2>OCR quality</h2>
    <table class="meta">
      <tr><th>Words detected</th><td><?= (int)$sub['word_count'] ?></td></tr>
      <tr><th>Low-confidence words (dropped)</th><td><?= (int)$sub['low_conf_words'] ?></td></tr>
      <tr><th>Average confidence</th><td><?= e(sprintf('%.0f%%', $sub['avg_confidence'] * 100)) ?></td></tr>
      <tr><th>Words kept for comparison</th><td><?= e((string)(preg_match_all('/\S+/u', (string)$sub['confident_text']) ?: 0)) ?></td></tr>
    </table>
    <a href="<?= e(submission_url($id)) ?>" class="btn">View full transcription</a>
  </div>
</div>

<div class="card">
  <h2>Comparison results</h2>
  <?php if ($comparisons === []) : ?>
    <p class="muted">No other submissions in this activity to compare against yet.</p>
  <?php else : ?>
    <table class="list">
      <thead>
        <tr><th>Score</th><th>Paired with</th><th>Label</th><th>Review status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($comparisons as $c) :
          if ((int)$c['submission_a'] === $id) {
              $otherName  = $c['name_b'];
              $otherImage = $c['image_b'];
          } else {
              $otherName  = $c['name_a'];
              $otherImage = $c['image_a'];
          }
      ?>
        <tr class="<?= $c['similarity'] >= FLAG_THRESHOLD ? 'flagged-row' : '' ?>">
          <td><b><?= e(sprintf('%.0f%%', $c['similarity'] * 100)) ?></b></td>
          <td>
            <img src="uploads/<?= e(rawurlencode($otherImage)) ?>" alt="other" class="thumb">
            <?= e($otherName) ?>
          </td>
          <td><?= e(similarity_label((float)$c['similarity'])) ?></td>
          <td><?= e(ucwords(str_replace('_', ' ', $c['review_status']))) ?></td>
          <td><a class="btn small" href="<?= e(comparison_url((int)$c['id'])) ?>">Review pair</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>