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

// --- Hybrid score weights (must sum to 1) ---
// Final = w1*NGram + w2*TFIDF + w3*Lev + w4*Semantic
define('W_NGRAM', 0.35);
define('W_TFIDF', 0.25);
define('W_LEV', 0.20);
define('W_SEMANTIC', 0.20);

// --- Similarity-level cutoffs (used for the human label) ---
define('LEVEL_HIGH', 0.70);
define('LEVEL_MODERATE', 0.30);

// Sentence pairs at/above this score are highlighted in the review view.
define('HIGHLIGHT_THRESHOLD', 0.60);

// --- Semantic similarity service (local Python, sentence-transformers) ---
// Leave SEMANTIC_SERVICE_URL empty to disable the method; the hybrid score
// then renormalizes over the remaining three methods.
define('SEMANTIC_SERVICE_URL', 'http://127.0.0.1:8000');
define('SEMANTIC_TIMEOUT', 30);