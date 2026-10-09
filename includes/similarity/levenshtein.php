<?php
declare(strict_types=1);

require_once __DIR__ . '/preprocess.php';

/**
 * Sentence-level Levenshtein similarity.
 *
 * For each sentence we find its closest counterpart in the other essay and
 * average those best matches (both directions). A greedy one-to-one matching
 * is also produced so the reviewer can see which sentences line up.
 * Stopwords are kept so copied sentences still align.
 */

/** Edit distance over ASCII bytes (input is already normalize_text'd). */
function levenshtein_distance(string $a, string $b): int
{
    $lenA = strlen($a);
    $lenB = strlen($b);
    if ($lenA === 0) {
        return $lenB;
    }
    if ($lenB === 0) {
        return $lenA;
    }

    $prev = range(0, $lenB);
    for ($i = 1; $i <= $lenA; $i++) {
        $curr    = [$i];
        $charA   = $a[$i - 1];
        for ($j = 1; $j <= $lenB; $j++) {
            $cost      = ($charA === $b[$j - 1]) ? 0 : 1;
            $curr[$j]  = min(
                $curr[$j - 1] + 1,
                $prev[$j] + 1,
                $prev[$j - 1] + $cost
            );
        }
        $prev = $curr;
    }
    return $prev[$lenB];
}

/** Normalized Levenshtein similarity: 1 - edits / max(len_a, len_b). */
function levenshtein_ratio(string $a, string $b): float
{
    $a = normalize_text($a);
    $b = normalize_text($b);
    $lenA = strlen($a);
    $lenB = strlen($b);

    if ($lenA === 0 && $lenB === 0) {
        return 1.0;
    }
    if ($lenA === 0 || $lenB === 0) {
        return 0.0;
    }

    $distance = levenshtein_distance($a, $b);
    return 1.0 - ($distance / max($lenA, $lenB));
}

/**
 * Compares two sentence lists.
 * Returns ['score' => float in [0,1], 'pairs' => [['a','b','score'], ...]].
 */
function levenshtein_similarity(array $sentA, array $sentB): array
{
    $countA = count($sentA);
    $countB = count($sentB);
    if ($countA === 0 || $countB === 0) {
        return ['score' => 0.0, 'pairs' => []];
    }

    $sim = [];
    for ($i = 0; $i < $countA; $i++) {
        $row = [];
        for ($j = 0; $j < $countB; $j++) {
            $row[$j] = levenshtein_ratio($sentA[$i], $sentB[$j]);
        }
        $sim[$i] = $row;
    }

    $sumA = 0.0;
    for ($i = 0; $i < $countA; $i++) {
        $sumA += max($sim[$i]);
    }

    $sumB = 0.0;
    for ($j = 0; $j < $countB; $j++) {
        $colMax = 0.0;
        for ($i = 0; $i < $countA; $i++) {
            if ($sim[$i][$j] > $colMax) {
                $colMax = $sim[$i][$j];
            }
        }
        $sumB += $colMax;
    }

    $score = (($sumA / $countA) + ($sumB / $countB)) / 2.0;

    // Greedy one-to-one matching, strongest pairs first.
    $triples = [];
    for ($i = 0; $i < $countA; $i++) {
        for ($j = 0; $j < $countB; $j++) {
            $triples[] = [$sim[$i][$j], $i, $j];
        }
    }
    usort($triples, static fn(array $x, array $y): int => $y[0] <=> $x[0]);

    $usedA = [];
    $usedB = [];
    $pairs = [];
    foreach ($triples as [$pairScore, $i, $j]) {
        if (isset($usedA[$i]) || isset($usedB[$j])) {
            continue;
        }
        $usedA[$i] = true;
        $usedB[$j] = true;
        $pairs[] = ['a' => $i, 'b' => $j, 'score' => round($pairScore, 4)];
    }

    return ['score' => round($score, 4), 'pairs' => $pairs];
}
