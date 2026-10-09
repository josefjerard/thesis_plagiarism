<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, a.title AS activity_title,
            s1.student_name AS name_a, s1.image_path AS image_a, s1.raw_text AS raw_a, s1.confident_text AS conf_a, s1.avg_confidence AS avg_a, s1.word_count AS words_a, s1.language AS lang_a,
            s2.student_name AS name_b, s2.image_path AS image_b, s2.raw_text AS raw_b, s2.confident_text AS conf_b, s2.avg_confidence AS avg_b, s2.word_count AS words_b, s2.language AS lang_b
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
$sentA = split_sentences((string)$c['conf_a']);
$sentB = split_sentences((string)$c['conf_b']);
[$hlA, $hlB] = highlighted_sentence_map($c['matched_sentences'] ?? null);

$methods = [
    'Character n-gram' => $c['ngram_score'] !== null ? (float)$c['ngram_score'] : null,
    'TF-IDF + cosine'  => $c['tfidf_score'] !== null ? (float)$c['tfidf_score'] : null,
    'Levenshtein'      => $c['lev_score'] !== null ? (float)$c['lev_score'] : null,
    'Semantic'         => $c['semantic_score'] !== null ? (float)$c['semantic_score'] : null,
];

$pageTitle = 'Compare pair';
require __DIR__ . '/includes/head.php';
?>

<h1>Side-by-side review</h1>
<p class="muted">
  Activity: <?= e($c['activity_title']) ?> &middot;
  Languages: <?= e(language_label((string)$c['lang_a'])) ?> / <?= e(language_label((string)$c['lang_b'])) ?> &middot;
  Hybrid similarity: <b><?= e(pct($score)) ?></b>
  <span class="badge <?= $score >= FLAG_THRESHOLD ? 'warn' : 'ok' ?>"><?= e(similarity_label($score)) ?></span>
</p>

<div class="card">
  <h2>Method breakdown</h2>
  <table class="meta">
    <?php foreach ($methods as $label => $value) : ?>
      <tr><th><?= e($label) ?></th><td><?= e(pct($value)) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Hybrid score</th><td><b><?= e(pct($score)) ?></b></td></tr>
  </table>
  <p class="muted" style="margin-bottom:0">
    Sentences with a matching counterpart scoring &ge; <?= e(pct(HIGHLIGHT_THRESHOLD)) ?> are highlighted below.
    Semantic shows "—" when the local Python service is offline.
  </p>
</div>

<div class="grid two">
  <div class="card">
    <h2><?= e($c['name_a']) ?> <span class="badge"><?= e(language_label((string)$c['lang_a'])) ?> &middot; avg conf <?= e(sprintf('%.0f%%', $c['avg_a'] * 100)) ?></span></h2>
    <a href="uploads/<?= e(rawurlencode($c['image_a'])) ?>" target="_blank">
      <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay A" class="paper">
    </a>
    <div class="sentence-view"><?= highlighted_sentences($sentA, $hlA) ?></div>
    <details class="text-out-wrap">
      <summary>Full OCR transcription (<?= (int)$c['words_a'] ?> words)</summary>
      <pre class="text-out"><?= e($c['raw_a']) ?></pre>
    </details>
  </div>
  <div class="card">
    <h2><?= e($c['name_b']) ?> <span class="badge"><?= e(language_label((string)$c['lang_b'])) ?> &middot; avg conf <?= e(sprintf('%.0f%%', $c['avg_b'] * 100)) ?></span></h2>
    <a href="uploads/<?= e(rawurlencode($c['image_b'])) ?>" target="_blank">
      <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay B" class="paper">
    </a>
    <div class="sentence-view"><?= highlighted_sentences($sentB, $hlB) ?></div>
    <details class="text-out-wrap">
      <summary>Full OCR transcription (<?= (int)$c['words_b'] ?> words)</summary>
      <pre class="text-out"><?= e($c['raw_b']) ?></pre>
    </details>
  </div>
</div>

<form method="post" action="compare.php?id=<?= (int)$id ?>" class="card decision-bar">
  <p><b>Your call:</b> after looking at the two essays, was this plagiarism?</p>
  <button type="submit" name="verdict" value="plagiarized" class="btn danger">Plagiarized</button>
  <button type="submit" name="verdict" value="not_plagiarism" class="btn primary">Not plagiarism</button>
  <a class="btn" href="report.php?id=<?= (int)$id ?>">View similarity report</a>
  <a class="btn" href="result.php?id=<?= (int)$c['activity_id'] ?>">Batch results</a>
  <a class="btn" href="review.php">Back to history</a>
</form>

<?php require __DIR__ . '/includes/foot.php'; ?>
