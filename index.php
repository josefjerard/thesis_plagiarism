<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/similarity.php';

$pdo = db();

// ---- filters: how the history is narrowed down ----
$filters = [
    'all'          => ['label' => 'All',          'pair' => '1=1',                                              'params' => []],
    'flagged'      => ['label' => 'Flagged',      'pair' => 'c.similarity >= ?',                                 'params' => [FLAG_THRESHOLD]],
    'needs_review' => ['label' => 'Needs review', 'pair' => "c.review_status = 'pending' AND c.similarity >= ?", 'params' => [FLAG_THRESHOLD]],
    'reviewed'     => ['label' => 'Reviewed',     'pair' => "c.review_status <> 'pending'",                      'params' => []],
];
$filter = (string)($_GET['filter'] ?? 'all');
if (!isset($filters[$filter])) {
    $filter = 'all';
}
$activePair   = $filters[$filter]['pair'];
$activeParams = $filters[$filter]['params'];

// ---- summary across every session ----
$summaryStmt = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*) FROM activities)                                            AS sessions,
        (SELECT COUNT(*) FROM submissions)                                           AS essays,
        (SELECT COUNT(*) FROM comparisons)                                           AS pairs,
        (SELECT COUNT(*) FROM comparisons WHERE similarity >= ?)                     AS flagged,
        (SELECT COUNT(*) FROM comparisons WHERE review_status = \'pending\'
            AND similarity >= ?)                                                     AS pending'
);
$summaryStmt->execute([FLAG_THRESHOLD, FLAG_THRESHOLD]);
$summary = $summaryStmt->fetch() ?: ['sessions' => 0, 'essays' => 0, 'pairs' => 0, 'flagged' => 0, 'pending' => 0];

// ---- batches (comparison sessions), newest first ----
$batchSql =
    'SELECT a.id, a.title, a.created_at,
        (SELECT COUNT(*) FROM submissions s WHERE s.activity_id = a.id)                             AS essays,
        (SELECT COUNT(*) FROM comparisons c WHERE c.activity_id = a.id)                             AS pairs,
        (SELECT COUNT(*) FROM comparisons c WHERE c.activity_id = a.id AND c.similarity >= ?)       AS flagged,
        (SELECT COUNT(*) FROM comparisons c WHERE c.activity_id = a.id AND c.review_status = \'pending\'
            AND c.similarity >= ?)                                                                  AS pending,
        (SELECT COUNT(*) FROM comparisons c WHERE c.activity_id = a.id AND c.review_status <> \'pending\') AS reviewed,
        (SELECT MAX(c.similarity) FROM comparisons c WHERE c.activity_id = a.id)                    AS max_score
     FROM activities a';

$batchParams = [FLAG_THRESHOLD, FLAG_THRESHOLD];
if ($filter !== 'all') {
    $batchSql .= ' WHERE EXISTS (SELECT 1 FROM comparisons c WHERE c.activity_id = a.id AND ' . $activePair . ')';
    $batchParams = array_merge($batchParams, $activeParams);
}
$batchSql .= ' ORDER BY a.created_at DESC, a.id DESC';

$batchStmt = $pdo->prepare($batchSql);
$batchStmt->execute($batchParams);
$batches = $batchStmt->fetchAll();

// ---- all pairs for the visible batches, in one query ----
$pairsByBatch = [];
if ($batches !== []) {
    $ids = array_map(static fn(array $b): int => (int)$b['id'], $batches);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $pairSql =
        'SELECT c.id, c.activity_id, c.similarity, c.review_status, c.reviewed_at,
                s1.id AS id_a, s1.student_name AS name_a, s1.image_path AS image_a,
                s2.id AS id_b, s2.student_name AS name_b, s2.image_path AS image_b
         FROM comparisons c
         JOIN submissions s1 ON s1.id = c.submission_a
         JOIN submissions s2 ON s2.id = c.submission_b
         WHERE c.activity_id IN (' . $placeholders . ') AND ' . $activePair . '
         ORDER BY c.activity_id, c.similarity DESC';

    $pairStmt = $pdo->prepare($pairSql);
    $pairStmt->execute(array_merge($ids, $activeParams));
    foreach ($pairStmt->fetchAll() as $row) {
        $pairsByBatch[(int)$row['activity_id']][] = $row;
    }
}

$pageTitle = 'History';
require __DIR__ . '/includes/head.php';
?>

<div class="stat-grid">
  <div class="stat"><div class="stat-value"><?= (int)$summary['sessions'] ?></div><div class="stat-label">Sessions</div></div>
  <div class="stat"><div class="stat-value"><?= (int)$summary['essays'] ?></div><div class="stat-label">Essays scanned</div></div>
  <div class="stat"><div class="stat-value"><?= (int)$summary['pairs'] ?></div><div class="stat-label">Pairs compared</div></div>
  <div class="stat is-warn"><div class="stat-value"><?= (int)$summary['flagged'] ?></div><div class="stat-label">Flagged pairs</div></div>
</div>


<div class="tabs">
  <?php foreach ($filters as $key => $meta) : ?>
    <a class="tab <?= $filter === $key ? 'active' : '' ?>" href="index.php?filter=<?= e($key) ?>"><?= e($meta['label']) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($batches === []) : ?>
  <div class="card">
    <p class="muted" style="margin:0">
      <?= $filter === 'all'
          ? 'No comparison sessions yet. Upload a batch of essays to get started.'
          : 'No sessions match this filter.' ?>
    </p>
  </div>
<?php else : ?>
  <?php foreach ($batches as $b) :
      $batchId  = (int)$b['id'];
      $pending  = (int)$b['pending'];
      $flagged  = (int)$b['flagged'];
      $maxScore = $b['max_score'] !== null ? (float)$b['max_score'] : null;
      $rows     = $pairsByBatch[$batchId] ?? [];

      if ($pending > 0) {
          $statusBadge = '<span class="badge warn">' . $pending . ' pending</span>';
      } elseif ($flagged > 0) {
          $statusBadge = '<span class="badge ok">Reviewed</span>';
      } else {
          $statusBadge = '<span class="badge">No flags</span>';
      }
  ?>
    <details class="batch" <?= $pending > 0 ? 'open' : '' ?>>
      <summary>
        <span class="batch-title"><?= e($b['title']) ?></span>
        <span class="batch-meta"><?= e($b['created_at']) ?></span>
        <span class="badge"><?= (int)$b['essays'] ?> essays</span>
        <span class="badge"><?= (int)$b['pairs'] ?> pairs</span>
        <?php if ($flagged > 0) : ?><span class="badge warn"><?= $flagged ?> flagged</span><?php endif; ?>
        <?= $statusBadge ?>
        <span class="batch-spacer"></span>
        <?php if ($maxScore !== null) : ?>
          <span class="batch-meta">max <b><?= e(pct($maxScore)) ?></b> · <?= e(similarity_label($maxScore)) ?></span>
        <?php endif; ?>
        <a class="btn small" href="result.php?id=<?= $batchId ?>" onclick="event.stopPropagation()">Results</a>
      </summary>
      <div class="batch-inner">
        <?php if ($rows === []) : ?>
          <p class="muted" style="margin:12px 0 0">No pairs match this filter in this session.</p>
        <?php else : ?>
          <table class="list">
            <thead>
              <tr><th>Score</th><th>Essay A</th><th>Essay B</th><th>Label</th><th>Status</th><th>Reviewed</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $c) : ?>
              <tr class="<?= (float)$c['similarity'] >= FLAG_THRESHOLD ? 'flagged-row' : '' ?>">
                <td>
                  <b><?= e(pct((float)$c['similarity'])) ?></b>
                </td>
                <td>
                  <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay" class="thumb">
                  <a href="<?= e(submission_url((int)$c['id_a'])) ?>"><?= e($c['name_a']) ?></a>
                </td>
                <td>
                  <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay" class="thumb">
                  <a href="<?= e(submission_url((int)$c['id_b'])) ?>"><?= e($c['name_b']) ?></a>
                </td>
                <td><?= e(similarity_label((float)$c['similarity'])) ?></td>
                <td>
                  <?php if ($c['review_status'] === 'pending') : ?>
                    <span class="badge warn">Pending</span>
                  <?php else : ?>
                    <span class="badge <?= $c['review_status'] === 'plagiarized' ? 'warn' : 'ok' ?>"><?= e(ucwords(str_replace('_', ' ', $c['review_status']))) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e($c['reviewed_at'] ?? '—') ?></td>
                <td><a class="btn small" href="<?= e(comparison_url((int)$c['id'])) ?>">Review</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/foot.php'; ?>
