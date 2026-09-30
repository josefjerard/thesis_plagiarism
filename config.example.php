<?php
/**
 * Copy this file to config.php and fill in YOUR values.
 * config.php is git-ignored so your API key never reaches version control.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'thesis_plagiarism');
define('DB_USER', 'root');
define('DB_PASS', '');

// Google Cloud Vision API key (restricted). Uploads fail until this is set.
define('GOOGLE_VISION_API_KEY', '');

// The upload page shows two identical cards ("Student 1" and "Student 2").
// Both submit into this single activity, so the two essays are compared against each other.
define('PAIR_ACTIVITY_ID', 1);

// Storage
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);

// --- OCR-error-robust similarity tuning ---
// Size of the character shingles used for comparison.
define('SHINGLE_SIZE', 5);

// Which formula scores a pair: 'dice' or 'jaccard'.
define('SIM_METHOD', 'dice');

// Pairs with similarity >= this are flagged for admin review.
define('FLAG_THRESHOLD', 0.30);

// Words with per-word OCR confidence < this are EXCLUDED from the comparison text.
define('CONFIDENCE_CUTOFF', 0.60);

// Readability gate: below either of these the image is rejected and the student is asked to re-upload.
define('READABILITY_MIN_WORDS', 20);
define('READABILITY_MIN_AVG_CONF', 0.50);