<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/ocr.php';
require __DIR__ . '/includes/similarity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Bad request.');
}

$activityId   = (int)($_POST['activity_id'] ?? 0);
$studentName  = trim((string)($_POST['student_name'] ?? ''));
$file         = $_FILES['essay_image'] ?? null;

if ($activityId <= 0 || $studentName === '' || $studentName === null) {
    flash_set('Activity and student name are required.');
    redirect('index.php');
}

$stmt = db()->prepare('SELECT id, title FROM activities WHERE id = ?');
$stmt->execute([$activityId]);
$activity = $stmt->fetch();
if ($activity === false) {
    flash_set('Selected activity does not exist.');
    redirect('index.php');
}

// ---- file validation ----
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    flash_set('Upload failed. No file was received.');
    redirect('index.php');
}
if ($file['size'] > MAX_UPLOAD_BYTES) {
    flash_set('Image is too large (max ' . human_bytes(MAX_UPLOAD_BYTES) . ').');
    redirect('index.php');
}

$imageInfo = getimagesize($file['tmp_name']);
$allowed = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];
if ($imageInfo === false || !isset($allowed[$imageInfo[2]])) {
    flash_set('Unsupported file. Please upload a JPG, PNG, or WEBP image.');
    redirect('index.php');
}

// ---- store the uploaded image with a random name ----
if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    flash_set('Server storage directory is not writable. Run install.php once.');
    redirect('index.php');
}
$ext      = $allowed[$imageInfo[2]];
$fileName = random_storage_name($ext);
$dest     = UPLOAD_DIR . DIRECTORY_SEPARATOR . $fileName;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    flash_set('Could not store the uploaded file. Check uploads/ permissions.');
    redirect('index.php');
}

// ---- OCR + readability gate ----
try {
    $ocr = vision_ocr((string)file_get_contents($dest));
} catch (Throwable $ex) {
    @unlink($dest);
    flash_set('OCR failed: ' . $ex->getMessage());
    redirect('index.php');
}

if (!image_is_readable($ocr)) {
    @unlink($dest);
    flash_set('Unreadable image — detected only ' . $ocr['word_count'] . ' words at ' .
              sprintf('%.0f%%', $ocr['avg_confidence'] * 100) . ' avg confidence. Please retake the photo and re-upload.');
    redirect('index.php');
}

// ---- persist submission ----
$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO submissions (activity_id, student_name, image_path, raw_text, confident_text, word_count, low_conf_words, avg_confidence)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $activityId,
    $studentName,
    $fileName,
    $ocr['raw_text'],
    $ocr['confident_text'],
    $ocr['word_count'],
    $ocr['low_conf_words'],
    $ocr['avg_confidence'],
]);
$submissionId = (int)$pdo->lastInsertId();

// ---- compare against the rest of the activity and flag if needed ----
$highest = store_comparisons($submissionId, $activityId, $ocr['confident_text']);

if ($highest !== null && $highest >= FLAG_THRESHOLD) {
    $up = $pdo->prepare('UPDATE submissions SET status = ? WHERE id = ?');
    $up->execute(['flagged', $submissionId]);
}

redirect('result.php?id=' . $submissionId);