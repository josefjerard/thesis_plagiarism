<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$pdo = db();
$activities = $pdo->query('SELECT id, title FROM activities ORDER BY title')->fetchAll();
$filter = (int)($_GET['activity'] ?? 0);

$sql = 'SELECT c.id AS comparison_id, c.similarity, c.review_status, c.created_at,
               a.id AS activity_id, a.title AS activity_title,
               s1.id AS id_a, s1.student_name AS name_a, s1.image_path AS image_a,
               s2.id AS id_b, s2.student_name AS name_b, s2.image_path AS image_b
        FROM comparisons c
        JOIN activities a ON a.id = c.activity_id
        JOIN submissions s1 ON s1.id = c.submission_a
        JOIN submissions s2 ON s2.id = c.submission_b
        WHERE c.review_status = \'pending\' AND c.similarity >= ' . FLAG_THRESHOLD;

$params = [];
if ($filter > 0) {
    $sql .= ' AND c.activity_id = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY c.similarity DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$flagged = $stmt->fetchAll();

$resolvedStmt = $pdo->prepare(
    'SELECT c.id, c.similarity, c.review_status, c.reviewed_at,
            s1.student_name AS name_a, s1.image_path AS image_a,
            s2.student_name AS name_b, s2.image_path AS image_b
     FROM comparisons c
     JOIN submissions s1 ON s1.id = c.submission_a
     JOIN submissions s2 ON s2.id = c.submission_b
     WHERE c.review_status != \'pending\'
     ORDER BY c.reviewed_at DESC
     LIMIT 10'
);
$resolvedStmt->execute();
$resolved = $resolvedStmt->fetchAll();

$pageTitle = 'Admin review';
require __DIR__ . '/includes/head.php';
?>

<h1>Admin review queue</h1>
<p class="muted">
  Flagged pairs (similarity &ge; <?= e(sprintf('%.0f%%', FLAG_THRESHOLD * 100)) ?>) awaiting a human decision.
  The score only prioritizes the queue — the admin makes the final call.
</p>

<form method="get" action="review.php" class="card">
  <div class="field inline">
    <label for="activity">Filter by activity</label>
    <select name="activity" id="activity">
      <option value="0">All activities</option>
      <?php foreach ($activities as $a) : ?>
        <option value="<?= (int)$a['id'] ?>" <?= $filter === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Apply</button>
    <?php if ($filter > 0) : ?><a class="btn" href="review.php">Clear</a><?php endif; ?>
  </div>
</form>

<div class="card">
  <h2>Pending (<?= count($flagged) ?>)</h2>
  <?php if ($flagged === []) : ?>
    <p class="muted">Nothing to review right now.</p>
  <?php else : ?>
    <table class="list">
      <thead>
        <tr><th>Score</th><th>Essay A</th><th>Essay B</th><th>Activity</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($flagged as $c) : ?>
        <tr class="flagged-row">
          <td><b><?= e(sprintf('%.0f%%', $c['similarity'] * 100)) ?></b></td>
          <td>
            <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay" class="thumb">
            <a href="<?= e(submission_url((int)$c['id_a'])) ?>"><?= e($c['name_a']) ?></a>
          </td>
          <td>
            <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay" class="thumb">
            <a href="<?= e(submission_url((int)$c['id_b'])) ?>"><?= e($c['name_b']) ?></a>
          </td>
          <td><?= e($c['activity_title']) ?></td>
          <td><a class="btn" href="<?= e(comparison_url((int)$c['comparison_id'])) ?>">Review side by side</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recently decided</h2>
  <?php if ($resolved === []) : ?>
    <p class="muted">No reviewed pairs yet.</p>
  <?php else : ?>
    <table class="list">
      <thead><tr><th>Score</th><th>Pair</th><th>Decision</th><th>Reviewed</th></tr></thead>
      <tbody>
      <?php foreach ($resolved as $c) : ?>
        <tr>
          <td><b><?= e(sprintf('%.0f%%', $c['similarity'] * 100)) ?></b></td>
          <td>
            <img src="uploads/<?= e(rawurlencode($c['image_a'])) ?>" alt="essay" class="thumb"> <?= e($c['name_a']) ?>
            vs
            <img src="uploads/<?= e(rawurlencode($c['image_b'])) ?>" alt="essay" class="thumb"> <?= e($c['name_b']) ?>
          </td>
          <td><span class="badge <?= $c['review_status'] === 'plagiarized' ? 'warn' : 'ok' ?>"><?= e(ucwords(str_replace('_', ' ', $c['review_status']))) ?></span></td>
          <td><?= e($c['reviewed_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>