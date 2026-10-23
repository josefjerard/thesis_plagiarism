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
    <?php if ($sub['source_type'] === 'text') : ?>
      <h2>Text submission</h2>
      <div class="text-file-tile">
        <b><?= e($sub['image_path']) ?></b>
        <p class="text-file-excerpt"><?= e(text_excerpt($sub['raw_text'])) ?></p>
      </div>
    <?php else : ?>
      <h2>Image</h2>
      <a href="<?= e($imageUrl) ?>" target="_blank">
        <img src="<?= e($imageUrl) ?>" alt="Submitted essay" class="paper">
      </a>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Stats</h2>
    <table class="meta">
      <tr><th>Detected language</th><td><?= e(language_label((string)$sub['language'])) ?></td></tr>
      <tr><th>Words detected</th><td><?= (int)$sub['word_count'] ?></td></tr>
      <?php if ($sub['source_type'] === 'text') : ?>
        <tr><th>Low-confidence words dropped</th><td>0</td></tr>
        <tr><th>Text input confidence</th><td>100%</td></tr>
      <?php else : ?>
        <tr><th>Low-confidence words dropped</th><td><?= (int)$sub['low_conf_words'] ?></td></tr>
        <tr><th>Average confidence</th><td><?= e(sprintf('%.0f%%', $sub['avg_confidence'] * 100)) ?></td></tr>
      <?php endif; ?>
      <tr><th>AI-likelihood</th><td><?= $sub['ai_score'] !== null ? e(sprintf('%.0f%%', $sub['ai_score'] * 100)) : 'AI check unavailable' ?></td></tr>
    </table>
  </div>
</div>

<div class="card">
  <h2><?= $sub['source_type'] === 'text' ? 'Submitted text' : 'Raw OCR transcription' ?> <span class="badge">all words</span></h2>
  <pre class="text-out"><?= e($sub['raw_text']) ?></pre>
</div>

<div class="card">
  <h2>Text used for similarity <?php if ($sub['source_type'] === 'image') : ?><span class="badge">confidence &ge; <?= e(sprintf('%.0f%%', CONFIDENCE_CUTOFF * 100)) ?></span><?php endif; ?></h2>
  <pre class="text-out"><?= e($sub['confident_text']) ?></pre>
</div>

<div class="card">
  <h2>Web source matches <span class="badge"><?= count($webMatches) ?></span></h2>
  <?php $webStatusMessage = web_check_status_message(isset($sub['web_check_status']) ? (string)$sub['web_check_status'] : null); ?>
  <?php if ($webMatches === []) : ?>
    <p class="muted" style="margin:0">
      <?= e($webStatusMessage ?? 'No matches against the local reference corpus.') ?>
    </p>
  <?php else : ?>
    <?php if ($webStatusMessage !== null && $sub['web_check_status'] !== 'ok') : ?>
      <p class="muted"><?= e($webStatusMessage) ?></p>
    <?php endif; ?>
    <?php
      $sent = split_sentences((string)($sub['confident_text'] !== '' ? $sub['confident_text'] : $sub['raw_text']));
    ?>
    <?php foreach ($webMatches as $w) : ?>
      <?php
        $source = (string)$w['source_name'];
        $isWebSource = preg_match('~^https?://~i', $source) === 1;
        $sourceMeta = json_decode((string)$w['matched_passages'], true);
        $sourceTitle = is_string($sourceMeta['title'] ?? null) ? trim($sourceMeta['title']) : '';
        $sourceDomain = $isWebSource ? (string)(parse_url($source, PHP_URL_HOST) ?: $source) : '';
      ?>
      <div class="web-match">
        <p class="web-match-head">
          <strong>
            <?php if ($isWebSource) : ?>
              <a href="<?= e($source) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceTitle !== '' ? $sourceTitle : $source) ?></a>
              <span class="muted"><?= e($sourceDomain) ?></span>
            <?php else : ?>
              <?= e($source) ?>
            <?php endif; ?>
          </strong>
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
                <div>Source snippet: <?= e($p['src']) ?></div>
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