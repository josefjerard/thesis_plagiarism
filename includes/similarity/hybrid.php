<?php
declare(strict_types=1);

/**
 * Hybrid score: weighted combination of the four methods.
 *
 * Final = w1*NGram + w2*TFIDF + w3*Lev + w4*Semantic
 *
 * Weights live in config.php and should sum to 1. When a method is unavailable
 * (for example the semantic service is offline) its weight is dropped and the
 * remaining weights are renormalized so the score still spans [0,1].
 */
function hybrid_score(?float $ngram, ?float $tfidf, ?float $lev, ?float $semantic): float
{
    $parts = [
        [W_NGRAM, $ngram],
        [W_TFIDF, $tfidf],
        [W_LEV, $lev],
        [W_SEMANTIC, $semantic],
    ];

    $weighted = 0.0;
    $weightSum = 0.0;
    foreach ($parts as [$weight, $score]) {
        if ($score === null) {
            continue;
        }
        $weighted += $weight * $score;
        $weightSum += $weight;
    }

    if ($weightSum <= 0.0) {
        return 0.0;
    }
    return round($weighted / $weightSum, 4);
}

/** Human labels for a similarity score, using the configurable cutoffs. */
function similarity_label(float $score): string
{
    if ($score >= LEVEL_HIGH) {
        return 'High similarity';
    }
    if ($score >= LEVEL_MODERATE) {
        return 'Moderate similarity';
    }
    return 'Low similarity';
}
