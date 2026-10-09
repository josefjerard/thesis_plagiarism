<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/ocr.php';
require __DIR__ . '/includes/similarity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Bad request.');
}

$files = $_FILES['essay_images'] ?? null;
$names = $_POST['student_names'] ?? [];

if ($files === null || !is_array($files['name'] ?? null)) {
    flash_set('Please choose at least two essay images.');
    redirect('index.php');
}

$allowed = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];

if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    flash_set('Server storage directory is not writable. Run install.php once.');
    redirect('index.php');
}

// ---- Phase 1: validate and OCR every selected file, keep only readable ones ----
$good       = [];
$rejected   = [];
$totalFiles = count($files['name']);

for ($i = 0; $i < $totalFiles; $i++) {
    $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $originalName = (string)($files['name'][$i] ?? '');
    $label = trim((string)($names[$i] ?? ''));
    if ($label === '') {
        $label = pathinfo($originalName, PATHINFO_FILENAME);
    }
    if ($label === '') {
        $label = 'Essay ' . ($i + 1);
    }

    if ($error !== UPLOAD_ERR_OK) {
        $rejected[] = $label . ': upload error.';
        continue;
    }

    $tmp = (string)($files['tmp_name'][$i] ?? '');
    if ((int)($files['size'][$i] ?? 0) > MAX_UPLOAD_BYTES) {
        $rejected[] = $label . ': image is too large (max ' . human_bytes(MAX_UPLOAD_BYTES) . ').';
        continue;
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false || !isset($allowed[$imageInfo[2]])) {
        $rejected[] = $label . ': unsupported or corrupt file (JPG, PNG, or WEBP only).';
        continue;
    }

    try {
        $ocr = vision_ocr((string)file_get_contents($tmp));
    } catch (Throwable $ex) {
        $rejected[] = $label . ': OCR failed — ' . $ex->getMessage();
        continue;
    }

    if (!image_is_readable($ocr)) {
        $rejected[] = $label . ': unreadable image (only ' . $ocr['word_count'] . ' words at ' .
                      sprintf('%.0f%%', $ocr['avg_confidence'] * 100) . ' average confidence).';
        continue;
    }

    $good[] = [
        'label'    => $label,
        'ext'      => $allowed[$imageInfo[2]],
        'tmp'      => $tmp,
        'ocr'      => $ocr,
        'language' => detect_language($ocr['confident_text'] !== '' ? $ocr['confident_text'] : $ocr['raw_text']),
    ];
}

// ---- Phase 2: need at least two readable essays to compare ----
if (count($good) < 2) {
    $message = 'At least two readable essays are required. ';
    if ($rejected !== []) {
        $message .= 'Rejected: ' . implode(' | ', $rejected);
    }
    flash_set($message);
    redirect('index.php');
}

// ---- Phase 3: create the batch, store the essays, then compare within the batch ----
$pdo = db();
$batchTitle = 'Batch — ' . date('M j, Y H:i') . ' — ' . count($good) . ' essays';
$pdo->prepare('INSERT INTO activities (title) VALUES (?)')->execute([$batchTitle]);
$batchId = (int)$pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO submissions (activity_id, student_name, image_path, raw_text, confident_text, language, word_count, low_conf_words, avg_confidence)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($good as $item) {
    $fileName = random_storage_name($item['ext']);
    $dest = UPLOAD_DIR . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($item['tmp'], $dest)) {
        flash_set('Could not store one of the uploaded files. Check uploads/ permissions.');
        redirect('index.php');
    }

    $insert->execute([
        $batchId,
        $item['label'],
        $fileName,
        $item['ocr']['raw_text'],
        $item['ocr']['confident_text'],
        $item['language'],
        $item['ocr']['word_count'],
        $item['ocr']['low_conf_words'],
        $item['ocr']['avg_confidence'],
    ]);
}

$highestBySubmission = store_batch_comparisons($batchId);

$flag = $pdo->prepare('UPDATE submissions SET status = ? WHERE id = ?');
foreach ($highestBySubmission as $submissionId => $highest) {
    if ($highest >= FLAG_THRESHOLD) {
        $flag->execute(['flagged', $submissionId]);
    }
}

redirect('result.php?id=' . $batchId);
