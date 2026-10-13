<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$subStmt = $pdo->prepare('SELECT s.*, a.title AS activity_title
     FROM submissions s JOIN activities a ON a.id = s.activity_id
     WHERE s.id = ?');
$id = (int)($_GET['id'] ?? 0);
$subStmt->execute([$id]);
$sub = $subStmt->fetch();
if ($sub === false) {
    exit('Submission not found.');
}

$webStmt = $pdo->prepare('SELECT * FROM web_sources WHERE submission_id = ? ORDER BY similarity DESC');
$webStmt->execute([$id]);
$webMatches = $webStmt->fetchAll();

$imageUrl = 'uploads/' . rawurlencode($sub['image_path']);
$statusLabel = ['pending' => 'Undecided', 'flagged' => 'Flagged', 'reviewed' => 'Decided'][$sub['status']] ?? ucfirst((string)$sub['status']);
$pageTitle = 'Submission #' . $id;
require __DIR__ . '/includes/head.php';
?>

<h1>Submission #<?= (int)$sub['id'] ?> — <?= e($sub['student_name']) ?></h1>
<p class="muted">
  Activity: <?= e($sub['activity_title']) ?> &middot;
  Status: <?= e($statusLabel) ?> &middot;
  Submitted: <?= e($sub['created_at']) ?>
</p>
<p><a class="btn small" href="result.php?id=<?= (int)$sub['activity_id'] ?>">Back to activity results</a></p>

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
      <tr><th>Detected language</th><td><?= e(language_label((string)$sub['language'])) ?></td></tr>
      <tr><th>Words detected</th><td><?= (int)$sub['word_count'] ?></td></tr>
      <tr><th>Low-confidence words dropped</th><td><?= (int)$sub['low_conf_words'] ?></td></tr>
      <tr><th>Average confidence</th><td><?= e(sprintf('%.0f%%', $sub['avg_confidence'] * 100)) ?></td></tr>
      <tr><th>AI-likelihood</th><td><?= $sub['ai_score'] !== null ? e(sprintf('%.0f%%', $sub['ai_score'] * 100)) : '— (no detector model yet)' ?></td></tr>
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

<div class="card">
  <h2>Web source matches <span class="badge"><?= count($webMatches) ?></span></h2>
  <?php if ($webMatches === []) : ?>
    <p class="muted" style="margin:0">No matches against the local reference corpus.</p>
  <?php else : ?>
    <?php
      $sent = split_sentences((string)($sub['confident_text'] !== '' ? $sub['confident_text'] : $sub['raw_text']));
    ?>
    <?php foreach ($webMatches as $w) : ?>
      <div class="web-match">
        <p class="web-match-head">
          <strong><?= e($w['source_name']) ?></strong>
          <span class="badge warn">similarity <?= e(pct((float)$w['similarity'])) ?></span>
        </p>
        <table class="meta">
          <tr><th>Method scores</th><td>
            N <?= e(pct($w['ngram_score'] !== null ? (float)$w['ngram_score'] : null)) ?>
            &middot; T <?= e(pct($w['tfidf_score'] !== null ? (float)$w['tfidf_score'] : null)) ?>
            &middot; L <?= e(pct($w['lev_score'] !== null ? (float)$w['lev_score'] : null)) ?>
          </td></tr>
        </table>
        <?php
          $passages = json_decode((string)$w['matched_passages'], true);
          $passages = is_array($passages['passages'] ?? null) ? $passages['passages'] : [];
        ?>
        <?php if ($passages !== []) : ?>
          <button type="button" class="btn small" onclick="this.nextElementSibling.hidden = !this.nextElementSibling.hidden">Show overlap</button>
          <ul class="web-passages" hidden>
            <?php foreach ($passages as $p) :
                $essaySentence = $sent[(int)$p['essay_idx']] ?? '';
            ?>
              <li>
                <div class="muted">Essay: <?= e($essaySentence) ?></div>
                <div>Source: <?= e($p['src']) ?></div>
                <div class="muted">score <?= e(pct((float)$p['score'])) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>