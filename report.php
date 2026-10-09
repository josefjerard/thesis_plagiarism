<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT c.*, a.title AS activity_title,
            s1.student_name AS name_a, s1.image_path AS image_a, s1.confident_text AS conf_a, s1.language AS lang_a, s1.avg_confidence AS avg_a,
            s2.student_name AS name_b, s2.image_path AS image_b, s2.confident_text AS conf_b, s2.language AS lang_b, s2.avg_confidence AS avg_b
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

$score  = (float)$c['similarity'];
$sentA  = split_sentences((string)$c['conf_a']);
$sentB  = split_sentences((string)$c['conf_b']);
[$hlA, $hlB] = highlighted_sentence_map($c['matched_sentences'] ?? null);

$matches = json_decode((string)$c['matched_sentences'], true);
$pairs   = is_array($matches['pairs'] ?? null) ? $matches['pairs'] : [];

$methods = [
    'Character n-gram' => $c['ngram_score'] !== null ? (float)$c['ngram_score'] : null,
    'TF-IDF + cosine'  => $c['tfidf_score'] !== null ? (float)$c['tfidf_score'] : null,
    'Levenshtein'      => $c['lev_score'] !== null ? (float)$c['lev_score'] : null,
    'Semantic'         => $c['semantic_score'] !== null ? (float)$c['semantic_score'] : null,
];

$decision = $c['review_status'] === 'pending'
    ? 'Pending review'
    : ucwords(str_replace('_', ' ', (string)$c['review_status']));

$pageTitle = 'Similarity report';
require __DIR__ . '/includes/head.php';
?>

<div class="report-actions no-print">
  <a class="btn" href="<?= e(comparison_url($id)) ?>">Back to review</a>
  <button type="button" class="btn primary" onclick="window.print()">Print / save as PDF</button>
</div>

<div class="card report">
  <h1>Similarity report</h1>
  <p class="muted">
    Generated <?= e(date('Y-m-d H:i')) ?> &middot;
    Activity: <?= e($c['activity_title']) ?> &middot;
    Pair #<?= (int)$c['id'] ?>
  </p>

  <table class="meta report-meta">
    <tr><th>Essay A</th><td><?= e($c['name_a']) ?> (<?= e(language_label((string)$c['lang_a'])) ?>, avg OCR confidence <?= e(sprintf('%.0f%%', $c['avg_a'] * 100)) ?>)</td></tr>
    <tr><th>Essay B</th><td><?= e($c['name_b']) ?> (<?= e(language_label((string)$c['lang_b'])) ?>, avg OCR confidence <?= e(sprintf('%.0f%%', $c['avg_b'] * 100)) ?>)</td></tr>
    <tr><th>Hybrid score</th><td><b><?= e(pct($score)) ?></b> &mdash; <?= e(similarity_label($score)) ?></td></tr>
    <tr><th>Flag threshold</th><td><?= e(pct(FLAG_THRESHOLD)) ?> <?= $score >= FLAG_THRESHOLD ? '(flagged)' : '(below threshold)' ?></td></tr>
    <tr><th>Reviewer decision</th><td><?= e($decision) ?></td></tr>
  </table>

  <h2>Method scores</h2>
  <table class="list">
    <thead><tr><th>Method</th><th>What it measures</th><th>Score</th></tr></thead>
    <tbody>
      <tr><td>Character n-gram (Dice)</td><td>Copied or closely matching wording; OCR-tolerant</td><td><b><?= e(pct($methods['Character n-gram'])) ?></b></td></tr>
      <tr><td>TF-IDF + cosine</td><td>Overlap in important vocabulary</td><td><b><?= e(pct($methods['TF-IDF + cosine'])) ?></b></td></tr>
      <tr><td>Levenshtein (sentence)</td><td>How close matched sentences are, character by character</td><td><b><?= e(pct($methods['Levenshtein'])) ?></b></td></tr>
      <tr><td>Semantic (embeddings)</td><td>Similar meaning with different wording</td><td><b><?= e(pct($methods['Semantic'])) ?></b></td></tr>
      <tr class="flagged-row"><td>Hybrid</td><td>Weighted combination of the four methods</td><td><b><?= e(pct($score)) ?></b></td></tr>
    </tbody>
  </table>

  <h2>Matching sentences</h2>
  <?php if ($pairs === []) : ?>
    <p class="muted">No sentence-level matches were recorded for this pair.</p>
  <?php else : ?>
    <table class="list">
      <thead><tr><th>#</th><th>Essay A</th><th>Essay B</th><th>Leven.</th><th>Semantic</th></tr></thead>
      <tbody>
      <?php foreach ($pairs as $i => $p) :
          $aText = $sentA[(int)$p['a']] ?? '';
          $bText = $sentB[(int)$p['b']] ?? '';
      ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= e($aText) ?></td>
          <td><?= e($bText) ?></td>
          <td><?= e(pct(isset($p['lev']) && $p['lev'] !== null ? (float)$p['lev'] : null)) ?></td>
          <td><?= e(pct(isset($p['sem']) && $p['sem'] !== null ? (float)$p['sem'] : null)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Highlighted essays</h2>
  <div class="grid two">
    <div>
      <h3><?= e($c['name_a']) ?></h3>
      <div class="sentence-view"><?= highlighted_sentences($sentA, $hlA) ?></div>
    </div>
    <div>
      <h3><?= e($c['name_b']) ?></h3>
      <div class="sentence-view"><?= highlighted_sentences($sentB, $hlB) ?></div>
    </div>
  </div>

  <p class="muted report-disclaimer">
    This report supports the reviewer. A high score is evidence, not proof of
    plagiarism — the final judgment always belongs to the reviewer.
  </p>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
