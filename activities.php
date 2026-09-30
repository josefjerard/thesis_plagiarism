<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title !== '') {
        $stmt = $pdo->prepare('INSERT INTO activities (title) VALUES (?)');
        $stmt->execute([$title]);
        flash_set('Activity created.');
        redirect('activities.php');
    }
    flash_set('Title is required.');
    redirect('activities.php');
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM activities WHERE id = ?');
    $stmt->execute([(int)$_GET['delete']]);
    flash_set('Activity deleted (its submissions and comparisons were removed too).');
    redirect('activities.php');
}

$activities = $pdo->query(
    'SELECT a.id, a.title, a.created_at,
            (SELECT COUNT(*) FROM submissions s WHERE s.activity_id = a.id) AS submissions_count,
            (SELECT COUNT(*) FROM comparisons c
              JOIN submissions s1 ON s1.id = c.submission_a
              JOIN submissions s2 ON s2.id = c.submission_b
             WHERE c.activity_id = a.id AND c.similarity >= ' . FLAG_THRESHOLD . ' AND c.review_status = \'pending\') AS pending_flagged
     FROM activities a ORDER BY a.id DESC'
)->fetchAll();

$pageTitle = 'Activities';
require __DIR__ . '/includes/head.php';
?>

<h1>Activities</h1>
<p class="muted">Similarity is always scoped per activity — essays are only compared with other submissions in the same activity.</p>

<form method="post" action="activities.php" class="card">
  <div class="field inline">
    <label for="title">New activity</label>
    <input type="text" name="title" id="title" maxlength="255" placeholder="e.g. Reflection Essay 2" required>
    <button type="submit" class="btn primary">Create</button>
  </div>
</form>

<div class="card">
  <table class="list">
    <thead>
      <tr><th>Title</th><th>Submissions</th><th>Pending flagged pairs</th><th>Created</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($activities as $a) : ?>
      <tr>
        <td><?= e($a['title']) ?></td>
        <td><?= (int)$a['submissions_count'] ?></td>
        <td><?= (int)$a['pending_flagged'] ?></td>
        <td><?= e($a['created_at']) ?></td>
        <td><a class="btn small danger" href="activities.php?delete=<?= (int)$a['id'] ?>"
               onclick="return confirm('Delete this activity and all of its data?')">Delete</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($activities === []) : ?>
      <tr><td colspan="5" class="muted">No activities yet. Create one above.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>