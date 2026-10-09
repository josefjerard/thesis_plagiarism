<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$batchId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT id, title, created_at FROM activities WHERE id = ?');
$stmt->execute([$batchId]);
$batch = $stmt->fetch();
if ($batch === false) {
    exit('Batch not found.');
}

$subStmt = db()->prepare('SELECT * FROM submissions WHERE activity_id = ? ORDER BY id');
$subStmt->execute([$batchId]);
$submissions = $subStmt->fetchAll();

$compStmt = db()->prepare(
    'SELECT c.*,
            s1.student_name AS name_a, s1.image_path AS image_a, s1.language AS lang_a,
            s2.student_name AS name_b, s2.image_path AS image_b, s2.language AS lang_b
     FROM comparisons c
     JOIN submissions s1 ON s1.id = c.submission_a
     JOIN submissions s2 ON s2.id = c.submission_b
     WHERE c.activity_id = ?
     ORDER BY c.similarity DESC'
);
$compStmt->execute([$batchId]);
$comparisons = $compStmt->fetchAll();

$flaggedCount = 0;
foreach ($comparisons as $c) {
    if ((float)$c['similarity'] >= FLAG_THRESHOLD) {
        $flaggedCount++;
    }
}

$pageTitle = 'Batch results';
require __DIR__ . '/includes/head.php';
?>

<?php if ($flaggedCount > 0) : ?>
  <div class="box warn">
    <b>Flagged.</b> <?= $flaggedCount ?> pair<?= $flaggedCount === 1 ? '' : 's' ?> in this batch match at or
    above the <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?> threshold and were sent to the review history.
  </div>
<?php else : ?>
  <div class="box ok">No flagged matches at the current threshold in this batch.</div>
<?php endif; ?>

<h1>Batch results</h1>
<p class="muted">
  <?= e($batch['title']) ?> &middot;
  <?= count($submissions) ?> essay<?= count($submissions) === 1 ? '' : 's' ?> &middot;
  uploaded <?= e($batch['created_at']) ?>
</p>

<div class="card">
  <h2>Essays in this batch</h2>
  <div class="essay-grid">
    <?php foreach ($submissions as $s) : ?>
      <div class="essay-tile">
        <a href="uploads/<?= e(rawurlencode($s['image_path'])) ?>" target="_blank">
          <img src="uploads/<?= e(rawurlencode($s['image_path'])) ?>" alt="<?= e($s['student_name']) ?>">
        </a>
        <div class="essay-tile-body">
          <b><?= e($s['student_name']) ?></b>
          <div class="muted">
            <?= e(language_label((string)$s['language'])) ?> &middot;
            avg conf <?= e(sprintf('%.0f%%', $s['avg_confidence'] * 100)) ?>
          </div>
          <a class="btn small" href="<?= e(submission_url((int)$s['id'])) ?>">Details</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Pairwise comparisons</h2>
  <?php if ($comparisons === []) : ?>
    <p class="muted">No same-language pairs to compare in this batch.</p>
  <?php else : ?>
    <table class="list">
      <thead>
        <tr><th>Hybrid score</th><th>Essay A</th><th>Essay B</th><th>Label</th><th>Review status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($comparisons as $c) : ?>
        <tr class="<?= (float)$c['similarity'] >= FLAG_THRESHOLD ? 'flagged-row' : '' ?>">
          <td>
            <b><?= e(pct((float)$c['similarity'])) ?></b>
            <div class="breakdown">
              N <?= e(pct($c['ngram_score'] !== null ? (float)$c['ngram_score'] : null)) ?>
              &middot; T <?= e(pct($c['tfidf_score'] !== null ? (float)$c['tfidf_score'] : null)) ?>
              &middot; L <?= e(pct($c['lev_score'] !== null ? (float)$c['lev_score'] : null)) ?>
              &middot; S <?= e(pct($c['semantic_score'] !== null ? (float)$c['semantic_score'] : null)) ?>
            </div>
          </td>
          <td>
            <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay" class="thumb">
            <?= e($c['name_a']) ?>
          </td>
          <td>
            <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay" class="thumb">
            <?= e($c['name_b']) ?>
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

<p class="muted">
  <a class="btn" href="upload_essays.php">Upload another batch</a>
  <a class="btn" href="index.php">View history</a>
</p>

<?php require __DIR__ . '/includes/foot.php'; ?>
