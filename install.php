<?php
/**
 * One-time setup: creates the tables + uploads dir based on config.php.
 * Visit /thesis_plagiarism/install.php once, then DELETE this file.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';

/** True if $column already exists on $table. */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Adds any columns introduced after the first release (safe to re-run). */
function migrate_columns(PDO $pdo): array
{
    $wanted = [
        'submissions' => [
            'language' => "VARCHAR(8) NOT NULL DEFAULT 'en' AFTER confident_text",
        ],
        'comparisons' => [
            'ngram_score'       => 'DECIMAL(6,4) NULL AFTER similarity',
            'tfidf_score'       => 'DECIMAL(6,4) NULL AFTER ngram_score',
            'lev_score'         => 'DECIMAL(6,4) NULL AFTER tfidf_score',
            'semantic_score'    => 'DECIMAL(6,4) NULL AFTER lev_score',
            'hybrid_score'      => 'DECIMAL(6,4) NULL AFTER semantic_score',
            'matched_sentences' => 'MEDIUMTEXT NULL AFTER hybrid_score',
        ],
    ];

    $applied = [];
    foreach ($wanted as $table => $columns) {
        foreach ($columns as $columnName => $definition) {
            if (column_exists($pdo, $table, $columnName)) {
                continue;
            }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$columnName` $definition");
            $applied[] = "$table.$columnName";
        }
    }
    return $applied;
}

$ok = [];
try {
    $pdo = db();
    $schema = file_get_contents(__DIR__ . '/database.sql');
    $pdo->exec($schema);
    $ok[] = 'Database tables created (activities, submissions, comparisons).';

    $applied = migrate_columns($pdo);
    if ($applied !== []) {
        $ok[] = 'Added missing columns: ' . implode(', ', $applied) . '.';
    }
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
    <a href="review.php">Go to the review history</a>
  </p>
  <p class="muted">Security: delete <code>install.php</code> now.</p>
</div>
</body>
</html>