<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/helpers.php';

$pageTitle = 'About';
require __DIR__ . '/includes/head.php';
?>

<section class="page-head">
  <h1>About the System</h1>
  <p class="lead muted wide">
    Each uploaded image is transcribed with OCR, then every same-language pair in the
    batch is scored with four similarity methods and combined into a single hybrid
    score. The score prioritizes pairs for review — it is not a verdict on plagiarism.
  </p>
</section>

<div class="card">
  <h2>How it scores</h2>
  <table class="meta">
    <tr><th>Shingle size</th><td><?= (int)SHINGLE_SIZE ?> characters (<?= e(strtoupper(SIM_METHOD)) ?> coefficient)</td></tr>
    <tr><th>Methods</th><td>character n-gram, TF-IDF + cosine, sentence Levenshtein, semantic</td></tr>
    <tr><th>Hybrid weights</th><td>n-gram <?= e(sprintf('%.0f%%', W_NGRAM * 100)) ?> &middot; TF-IDF <?= e(sprintf('%.0f%%', W_TFIDF * 100)) ?> &middot; Levenshtein <?= e(sprintf('%.0f%%', W_LEV * 100)) ?> &middot; semantic <?= e(sprintf('%.0f%%', W_SEMANTIC * 100)) ?></td></tr>
    <tr><th>Similarity levels</th><td>high &ge; <?= e(sprintf('%.0f%%', LEVEL_HIGH * 100)) ?> &middot; moderate &ge; <?= e(sprintf('%.0f%%', LEVEL_MODERATE * 100)) ?></td></tr>
    <tr><th>Flag threshold</th><td>similarity &ge; <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?></td></tr>
    <tr><th>OCR confidence cutoff</th><td>words below <?= e(sprintf('%.0f%%', CONFIDENCE_CUTOFF * 100)) ?> confidence are excluded from comparison</td></tr>
    <tr><th>Semantic service</th><td><?= SEMANTIC_SERVICE_URL !== '' ? e(SEMANTIC_SERVICE_URL) : 'disabled' ?></td></tr>
  </table>
  <p class="muted" style="margin-bottom:0">
    Only essays in the same language are compared. If fewer than two readable
    essays are available, no comparison is performed.
  </p>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>
