<?php
/**
 * One-time setup: creates the tables + uploads dir based on config.php.
 * Visit /thesis_plagiarism/install.php once, then DELETE this file.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

$ok = [];
try {
    $pdo = db();
    $schema = file_get_contents(__DIR__ . '/database.sql');
    $pdo->exec($schema);
    $ok[] = 'Database tables created (activities, submissions, comparisons).';
} catch (Throwable $ex) {
    echo '<div class="box err">DB error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
    echo '<p>If the database <b>' . htmlspecialchars(DB_NAME) . '</b> does not exist yet, create it first in phpMyAdmin, then reload this page.</p>';
    exit;
}

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
if (!is_writable(UPLOAD_DIR)) {
    $ok[] = 'Warning: uploads/ is not writable (' . htmlspecialchars(UPLOAD_DIR) . ').';
} else {
    $ok[] = 'Uploads directory ready.';
}

$count = (int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
if ($count === 0) {
    $stmt = $pdo->prepare('INSERT INTO activities (title) VALUES (?)');
    $stmt->execute(['Essay Activity 1']);
    $ok[] = 'Seeded a sample activity "Essay Activity 1".';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installer</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
  <h1>Setup complete</h1>
  <?php foreach ($ok as $msg) : ?>
    <div class="box ok"><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
  <p>
    <a href="index.php">Go to the upload page</a>
    &middot;
    <a href="review.php">Go to the admin review queue</a>
  </p>
  <p class="muted">Security: delete <code>install.php</code> now.</p>
</div>
</body>
</html>