<?php
declare(strict_types=1);

/**
 * OCR-error-robust similarity.
 *
 * Instead of comparing word-level TF-IDF (which breaks when OCR makes a
 * character error), both essays are reduced to a SET of character shingles
 * (e.g. 5-charograms). A single OCR typo only destroys 1-2 shingles, so the
 * score degrades gracefully instead of collapsing to zero.
 */

/** Lowercase, strip non-alphanumerics, collapse whitespace. ASCII-safe after this step. */
function normalize_text(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text) ?? '';
}

/** Builds the set of sliding-window character shingles of size $n. */
function char_shingles(string $text, ?int $n = null): array
{
    $n = $n ?? SHINGLE_SIZE;
    $text = normalize_text($text);
    if ($text === '') {
        return [];
    }
    $len = strlen($text);
    if ($len < $n) {
        return [$text => true];
    }
    $set = [];
    for ($i = 0; $i <= $len - $n; $i++) {
        $set[substr($text, $i, $n)] = true;
    }
    return $set;
}

/**
 * Returns similarity in [0,1] using 'dice' or 'jaccard'.
 * Returns null when either text is empty (can't compare).
 */
function text_similarity(string $a, string $b): ?float
{
    $setA = char_shingles($a);
    $setB = char_shingles($b);
    if ($setA === [] || $setB === []) {
        return null;
    }

    $intersection = array_intersect_key($setA, $setB);
    $shared = count($intersection);
    $total = count($setA) + count($setB);

    if ($total === 0) {
        return null;
    }

    if (SIM_METHOD === 'jaccard') {
        $union = $total - $shared;
        return $union > 0 ? round($shared / $union, 4) : 1.0;
    }

    return round((2 * $shared) / $total, 4);
}

/**
 * Compares a new submission's confident text against every other submission
 * in the same activity and stores the pairwise scores.
 * Returns the highest similarity found (or null).
 */
function store_comparisons(int $newSubmissionId, int $activityId, string $newText): ?float
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, confident_text FROM submissions WHERE activity_id = ? AND id <> ?'
    );
    $stmt->execute([$activityId, $newSubmissionId]);
    $others = $stmt->fetchAll();

    $insert = $pdo->prepare(
        'INSERT INTO comparisons (activity_id, submission_a, submission_b, similarity)
         VALUES (?, ?, ?, ?)'
    );

    $highest = null;
    foreach ($others as $other) {
        $score = text_similarity($newText, (string)$other['confident_text']);
        if ($score === null) {
            continue;
        }
        $a = min((int)$newSubmissionId, (int)$other['id']);
        $b = max((int)$newSubmissionId, (int)$other['id']);
        $insert->execute([$activityId, $a, $b, $score]);

        if ($highest === null || $score > $highest) {
            $highest = $score;
        }
    }

    return $highest;
}

/** Human labels for a similarity score. */
function similarity_label(float $score): string
{
    if ($score >= 0.70) return 'High similarity';
    if ($score >= 0.30) return 'Moderate similarity';
    return 'Low similarity';
}