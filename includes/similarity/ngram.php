<?php
declare(strict_types=1);

require_once __DIR__ . '/preprocess.php';

/**
 * Character n-gram similarity (OCR-error-robust).
 *
 * Both essays are reduced to a SET of character shingles (e.g. 5-charagrams).
 * A single OCR typo only destroys one or two shingles, so the score degrades
 * gracefully instead of collapsing to zero. Stopwords are intentionally kept.
 */

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

    $shared = count(array_intersect_key($setA, $setB));
    $total  = count($setA) + count($setB);
    if ($total === 0) {
        return null;
    }

    if (SIM_METHOD === 'jaccard') {
        $union = $total - $shared;
        return $union > 0 ? round($shared / $union, 4) : 1.0;
    }

    return round((2 * $shared) / $total, 4);
}
