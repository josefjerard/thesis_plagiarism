<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, a.title AS activity_title,
            s1.student_name AS name_a, s1.image_path AS image_a, s1.raw_text AS raw_a, s1.confident_text AS conf_a, s1.avg_confidence AS avg_a, s1.word_count AS words_a,
            s2.student_name AS name_b, s2.image_path AS image_b, s2.raw_text AS raw_b, s2.confident_text AS conf_b, s2.avg_confidence AS avg_b, s2.word_count AS words_b
     FROM comparisons c
     JOIN activities a ON a.id = c.activity_id
     JOIN submissions s1 ON s1.id = c.submission_a
     JOIN submissions s2 ON s2.id = c.submission_b
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$c = $stmt->fetch();
if ($c === false) {
    exit('Comparison not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verdict = (string)($_POST['verdict'] ?? '');
    if (in_array($verdict, ['plagiarized', 'not_plagiarism'], true)) {
        $pdo->prepare('UPDATE comparisons SET review_status = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$verdict, $id]);
        refresh_submission_status((int)$c['submission_a']);
        refresh_submission_status((int)$c['submission_b']);
        flash_set('Decision recorded: ' . str_replace('_', ' ', $verdict) . '.');
        redirect('review.php');
    }
    flash_set('Invalid decision.');
    redirect(comparison_url($id));
}

$score = (float)$c['similarity'];
$pageTitle = 'Compare pair';
require __DIR__ . '/includes/head.php';
?>

<h1>Side-by-side review</h1>
<p class="muted">
  Activity: <?= e($c['activity_title']) ?> &middot;
  Similarity: <b><?= e(sprintf('%.0f%%', $score * 100)) ?></b>
  <span class="badge <?= $score >= FLAG_THRESHOLD ? 'warn' : 'ok' ?>"><?= e(similarity_label($score)) ?></span>
</p>

<div class="grid two">
  <div class="card">
    <h2><?= e($c['name_a']) ?> <span class="badge">avg conf <?= e(sprintf('%.0f%%', $c['avg_a'] * 100)) ?></span></h2>
    <a href="uploads/<?= e(rawurlencode($c['image_a'])) ?>" target="_blank">
      <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay A" class="paper">
    </a>
    <details class="text-out-wrap">
      <summary>OCR transcription (<?= (int)$c['words_a'] ?> words)</summary>
      <pre class="text-out"><?= e($c['raw_a']) ?></pre>
    </details>
  </div>
  <div class="card">
    <h2><?= e($c['name_b']) ?> <span class="badge">avg conf <?= e(sprintf('%.0f%%', $c['avg_b'] * 100)) ?></span></h2>
    <a href="uploads/<?= e(rawurlencode($c['image_b'])) ?>" target="_blank">
      <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay B" class="paper">
    </a>
    <details class="text-out-wrap">
      <summary>OCR transcription (<?= (int)$c['words_b'] ?> words)</summary>
      <pre class="text-out"><?= e($c['raw_b']) ?></pre>
    </details>
  </div>
</div>

<form method="post" action="compare.php?id=<?= (int)$id ?>" class="card decision-bar">
  <p><b>Your call:</b> after looking at the two essays, was this plagiarism?</p>
  <button type="submit" name="verdict" value="plagiarized" class="btn danger">Plagiarized</button>
  <button type="submit" name="verdict" value="not_plagiarism" class="btn primary">Not plagiarism</button>
  <a class="btn" href="review.php">Back to queue</a>
</form>

<?php require __DIR__ . '/includes/foot.php'; ?>