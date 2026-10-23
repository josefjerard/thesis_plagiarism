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
            s1.student_name AS name_a, s1.image_path AS image_a, s1.source_type AS type_a, s1.language AS lang_a,
            s2.student_name AS name_b, s2.image_path AS image_b, s2.source_type AS type_b, s2.language AS lang_b
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

$unavailableAiCount = 0;
$highAiCount = 0;
foreach ($submissions as $submission) {
    if ($submission['ai_score'] === null) {
        $unavailableAiCount++;
    } elseif ((float)$submission['ai_score'] >= AI_FLAG_THRESHOLD) {
        $highAiCount++;
    }
}

$webStmt = db()->prepare(
    'SELECT ws.*, s.student_name AS essay_name
     FROM web_sources ws
     JOIN submissions s ON s.id = ws.submission_id
     WHERE s.activity_id = ?
     ORDER BY ws.similarity DESC'
);
$webStmt->execute([$batchId]);
$webMatches = $webStmt->fetchAll();

$pageTitle = 'Activity results';
require __DIR__ . '/includes/head.php';
?>

<?php if (count($submissions) < 2) : ?>
  <div class="box info">
    Only one essay was uploaded, so there is nothing to compare it with. Upload at least two essays to run the similarity check.
  </div>
<?php elseif ($flaggedCount > 0) : ?>
  <div class="box warn">
    <b>Flagged.</b> <?= $flaggedCount ?> pair<?= $flaggedCount === 1 ? '' : 's' ?> in this activity match at or
    above the <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?> threshold and are flagged.
  </div>
<?php else : ?>
  <div class="box ok">No essay pairs reached the similarity threshold. This does not include the AI check.</div>
<?php endif; ?>

<?php if ($unavailableAiCount > 0) : ?>
  <div class="box info">
    AI check did not run for <?= $unavailableAiCount ?> essay<?= $unavailableAiCount === 1 ? '' : 's' ?>.
    The detector may not be trained or ai_service may be offline.
  </div>
<?php endif; ?>

<?php if ($highAiCount > 0) : ?>
  <div class="box warn">
    <?= $highAiCount ?> essay<?= $highAiCount === 1 ? '' : 's' ?> have a high AI-likelihood.
  </div>
<?php endif; ?>

<h1>Activity results</h1>
<p class="muted">
  <?= e($batch['title']) ?> &middot;
  <?= count($submissions) ?> essay<?= count($submissions) === 1 ? '' : 's' ?> &middot;
  uploaded <?= e($batch['created_at']) ?>
</p>

<div class="card">
  <h2>Essays in this activity</h2>
  <div class="essay-grid">
    <?php foreach ($submissions as $s) : ?>
      <div class="essay-tile">
        <?php if ($s['source_type'] === 'text') : ?>
          <div class="text-file-tile">
            <b>Text submission</b>
            <span><?= e($s['image_path']) ?></span>
            <p class="text-file-excerpt"><?= e(text_excerpt($s['raw_text'])) ?></p>
          </div>
        <?php else : ?>
          <a href="uploads/<?= e(rawurlencode($s['image_path'])) ?>" target="_blank">
            <img src="uploads/<?= e(rawurlencode($s['image_path'])) ?>" alt="<?= e($s['student_name']) ?>">
          </a>
        <?php endif; ?>
        <div class="essay-tile-body">
          <b><?= e($s['student_name']) ?></b>
          <div class="muted">
            <?= e(language_label((string)$s['language'])) ?> &middot;
            avg conf <?= e(sprintf('%.0f%%', $s['avg_confidence'] * 100)) ?>
          </div>
          <div><?= ai_badge($s['ai_score'] !== null ? (float)$s['ai_score'] : null) ?></div>
          <a class="btn small" href="<?= e(submission_url((int)$s['id'])) ?>">Details</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Pairwise comparisons</h2>
  <?php if ($comparisons === []) : ?>
    <p class="muted">No same-language pairs to compare in this activity.</p>
  <?php else : ?>
    <table class="list">
      <thead>
        <tr><th>Hybrid score</th><th>Essay A</th><th>Essay B</th><th>Label</th><th>Decision</th><th></th></tr>
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
            <?php if ($c['type_a'] === 'text') : ?>
              <span class="text-file-tile"><b>Text</b><span><?= e($c['image_a']) ?></span></span>
            <?php else : ?>
              <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay" class="thumb">
            <?php endif; ?>
            <?= e($c['name_a']) ?>
          </td>
          <td>
            <?php if ($c['type_b'] === 'text') : ?>
              <span class="text-file-tile"><b>Text</b><span><?= e($c['image_b']) ?></span></span>
            <?php else : ?>
              <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay" class="thumb">
            <?php endif; ?>
            <?= e($c['name_b']) ?>
          </td>
          <td><?= e(similarity_label((float)$c['similarity'])) ?></td>
          <td>
            <?php if ($c['review_status'] === 'pending') : ?>
              Undecided
            <?php elseif ($c['review_status'] === 'plagiarized') : ?>
              Plagiarized
            <?php else : ?>
              Not plagiarism
            <?php endif; ?>
          </td>
          <td><a class="btn small" href="<?= e(comparison_url((int)$c['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Web source matches</h2>
  <?php if ($webMatches === []) : ?>
    <?php
      $webMessages = [];
      foreach ($submissions as $submission) {
          $message = web_check_status_message(isset($submission['web_check_status']) ? (string)$submission['web_check_status'] : null);
          if ($message === null) {
              $message = WEB_SOURCE_MODE === 'local'
                  ? 'No matches against the reference corpus at the current threshold.'
                  : 'Internet source checks are disabled (set WEB_SOURCE_MODE in config.php).';
          }
          $webMessages[$message] = true;
      }
    ?>
    <?php foreach (array_keys($webMessages) as $message) : ?>
      <p class="muted" style="margin:0"><?= e($message) ?></p>
    <?php endforeach; ?>
  <?php else : ?>
    <?php
      $webFailures = [];
      foreach ($submissions as $submission) {
          $message = web_check_status_message(isset($submission['web_check_status']) ? (string)$submission['web_check_status'] : null);
          if ($message !== null && $submission['web_check_status'] !== 'ok') {
              $webFailures[$message] = true;
          }
      }
    ?>
    <?php foreach (array_keys($webFailures) as $message) : ?>
      <p class="muted"><?= e($message) ?></p>
    <?php endforeach; ?>
    <table class="list">
      <thead>
        <tr><th>Similarity</th><th>Essay</th><th>Source</th><th>Breakdown</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($webMatches as $w) : ?>
        <?php
          $source = (string)$w['source_name'];
          $isWebSource = preg_match('~^https?://~i', $source) === 1;
          $sourceMeta = json_decode((string)$w['matched_passages'], true);
          $sourceTitle = is_string($sourceMeta['title'] ?? null) ? trim($sourceMeta['title']) : '';
          $sourceDomain = $isWebSource ? (string)(parse_url($source, PHP_URL_HOST) ?: $source) : '';
        ?>
        <tr class="<?= (float)$w['similarity'] >= FLAG_THRESHOLD ? 'flagged-row' : '' ?>">
          <td><b><?= e(pct((float)$w['similarity'])) ?></b></td>
          <td><?= e($w['essay_name']) ?></td>
          <td>
            <?php if ($isWebSource) : ?>
              <a href="<?= e($source) ?>" target="_blank" rel="noopener noreferrer"><?= e($sourceTitle !== '' ? $sourceTitle : $source) ?></a>
              <span class="muted"><?= e($sourceDomain) ?></span>
            <?php else : ?>
              <?= e($source) ?>
            <?php endif; ?>
          </td>
          <td class="muted">
            N <?= e(pct($w['ngram_score'] !== null ? (float)$w['ngram_score'] : null)) ?>
            &middot; T <?= e(pct($w['tfidf_score'] !== null ? (float)$w['tfidf_score'] : null)) ?>
            &middot; L <?= e(pct($w['lev_score'] !== null ? (float)$w['lev_score'] : null)) ?>
          </td>
          <td><a class="btn small" href="submission.php?id=<?= (int)$w['submission_id'] ?>">Details</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<p class="muted">
  <a class="btn" href="upload_essays.php">Upload another activity</a>
  <a class="btn" href="index.php">Back to Dashboard</a>
</p>

<?php require __DIR__ . '/includes/foot.php'; ?>
