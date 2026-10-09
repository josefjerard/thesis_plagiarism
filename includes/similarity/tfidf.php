<?php
declare(strict_types=1);

/**
 * TF-IDF + cosine similarity with smoothed IDF (sklearn-style).
 *
 * IDF is estimated from the whole activity corpus (all same-language essays),
 * not just the pair, so word weights reflect how distinctive a term is across
 * the class. Stopword removal is applied here only.
 */

/** term => document frequency across the corpus. */
function build_idf(array $documents): array
{
    $n = count($documents);
    if ($n === 0) {
        return [];
    }

    $df = [];
    foreach ($documents as $tokens) {
        foreach (array_unique($tokens) as $term) {
            $df[$term] = ($df[$term] ?? 0) + 1;
        }
    }

    $idf = [];
    foreach ($df as $term => $freq) {
        // Smoothed IDF: idf = ln((1 + n) / (1 + df)) + 1
        $idf[$term] = log(($n + 1) / ($freq + 1)) + 1.0;
    }
    return $idf;
}

/** Sparse TF-IDF vector for one document (sublinear TF). */
function tfidf_vector(array $tokens, array $idf): array
{
    if ($tokens === []) {
        return [];
    }

    $counts = array_count_values($tokens);
    $vector = [];
    foreach ($counts as $term => $count) {
        if (!isset($idf[$term])) {
            continue;
        }
        $vector[$term] = (1.0 + log($count)) * $idf[$term];
    }
    return $vector;
}

/** Cosine similarity between two sparse vectors. */
function cosine_similarity(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return 0.0;
    }

    $dot = 0.0;
    $normA = 0.0;
    foreach ($a as $term => $value) {
        $normA += $value * $value;
        if (isset($b[$term])) {
            $dot += $value * $b[$term];
        }
    }

    $normB = 0.0;
    foreach ($b as $value) {
        $normB += $value * $value;
    }

    if ($normA <= 0.0 || $normB <= 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($normA) * sqrt($normB));
}
