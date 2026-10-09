<?php
declare(strict_types=1);

/**
 * Similarity engine orchestrator.
 *
 * Each algorithm lives in its own file under includes/similarity/. This file
 * wires them together: it scores a single pair with all four methods, combines
 * them into the hybrid score, records the sentence matches, and stores every
 * same-language pair in an activity.
 */

// Sensible fallbacks so the engine still runs if config.php predates a setting.
if (!defined('HIGHLIGHT_THRESHOLD')) define('HIGHLIGHT_THRESHOLD', 0.60);
if (!defined('LEVEL_HIGH'))        define('LEVEL_HIGH', 0.70);
if (!defined('LEVEL_MODERATE'))    define('LEVEL_MODERATE', 0.30);
if (!defined('W_NGRAM'))           define('W_NGRAM', 0.35);
if (!defined('W_TFIDF'))           define('W_TFIDF', 0.25);
if (!defined('W_LEV'))             define('W_LEV', 0.20);
if (!defined('W_SEMANTIC'))        define('W_SEMANTIC', 0.20);

require_once __DIR__ . '/similarity/preprocess.php';
require_once __DIR__ . '/similarity/language.php';
require_once __DIR__ . '/similarity/ngram.php';
require_once __DIR__ . '/similarity/tfidf.php';
require_once __DIR__ . '/similarity/levenshtein.php';
require_once __DIR__ . '/similarity/semantic.php';
require_once __DIR__ . '/similarity/hybrid.php';

/**
 * Scores one pair of essays with every method and returns the full breakdown.
 *
 * @param array $tokensA stopword-removed tokens for essay A (for TF-IDF)
 * @param array $tokensB stopword-removed tokens for essay B (for TF-IDF)
 * @param array $idf     smoothed IDF map built from the activity corpus
 * @return array{
 *   ngram: float, tfidf: float, lev: float,
 *   semantic: ?float, hybrid: float, matched: array
 * }
 */
function score_pair(string $textA, string $textB, array $tokensA, array $tokensB, array $idf): array
{
    $ngram = text_similarity($textA, $textB) ?? 0.0;
    $tfidf = cosine_similarity(tfidf_vector($tokensA, $idf), tfidf_vector($tokensB, $idf));

    $sentA = split_sentences($textA);
    $sentB = split_sentences($textB);

    $levResult = levenshtein_similarity($sentA, $sentB);
    $semResult = semantic_similarity($sentA, $sentB);
    $semantic  = $semResult !== null ? $semResult['score'] : null;

    return [
        'ngram'    => round($ngram, 4),
        'tfidf'    => round($tfidf, 4),
        'lev'      => $levResult['score'],
        'semantic' => $semantic !== null ? round($semantic, 4) : null,
        'hybrid'   => hybrid_score($ngram, $tfidf, $levResult['score'], $semantic),
        'matched'  => build_matched_sentences($levResult['pairs'], $semResult['pairs'] ?? []),
    ];
}

/**
 * Merges Levenshtein and semantic sentence matches into one list keyed by the
 * (a,b) sentence index pair, so the review view can highlight either kind.
 */
function build_matched_sentences(array $levPairs, array $semPairs): array
{
    $byKey = [];
    foreach ($levPairs as $pair) {
        $key = $pair['a'] . '-' . $pair['b'];
        $byKey[$key] = ['a' => $pair['a'], 'b' => $pair['b'], 'lev' => $pair['score'], 'sem' => null];
    }
    foreach ($semPairs as $pair) {
        if (!isset($pair['a'], $pair['b'], $pair['score'])) {
            continue;
        }
        $key = $pair['a'] . '-' . $pair['b'];
        if (isset($byKey[$key])) {
            $byKey[$key]['sem'] = $pair['score'];
        } else {
            $byKey[$key] = ['a' => $pair['a'], 'b' => $pair['b'], 'lev' => null, 'sem' => $pair['score']];
        }
    }

    $pairs = array_values($byKey);
    usort($pairs, static function (array $x, array $y): int {
        return max($y['lev'] ?? 0.0, $y['sem'] ?? 0.0)
            <=> max($x['lev'] ?? 0.0, $x['sem'] ?? 0.0);
    });

    return ['pairs' => $pairs];
}

/**
 * Scores EVERY same-language pair in a batch (activity) and stores the results.
 *
 * A batch is the set of essays uploaded together, so comparisons never cross
 * batches. The TF-IDF corpus is built from all same-language essays in the
 * batch at once, which keeps the scores consistent across pairs.
 *
 * @return array<int,float> highest hybrid score per submission id
 */
function store_batch_comparisons(int $activityId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, confident_text, language FROM submissions WHERE activity_id = ? ORDER BY id'
    );
    $stmt->execute([$activityId]);
    $rows = $stmt->fetchAll();

    // Tokenize each essay once, and build one IDF map per language in the batch.
    $tokensById  = [];
    $corpusByLang = [];
    foreach ($rows as $row) {
        $id     = (int)$row['id'];
        $lang   = (string)$row['language'];
        $tokens = tokenize((string)$row['confident_text'], true);
        $tokensById[$id] = $tokens;
        $corpusByLang[$lang][] = $tokens;
    }

    $idfByLang = [];
    foreach ($corpusByLang as $lang => $documents) {
        $idfByLang[$lang] = build_idf($documents);
    }

    $insert = $pdo->prepare(
        'INSERT INTO comparisons
            (activity_id, submission_a, submission_b, similarity,
             ngram_score, tfidf_score, lev_score, semantic_score, hybrid_score, matched_sentences)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            similarity = VALUES(similarity),
            ngram_score = VALUES(ngram_score),
            tfidf_score = VALUES(tfidf_score),
            lev_score = VALUES(lev_score),
            semantic_score = VALUES(semantic_score),
            hybrid_score = VALUES(hybrid_score),
            matched_sentences = VALUES(matched_sentences)'
    );

    $highestBySubmission = [];
    $count = count($rows);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $rowA = $rows[$i];
            $rowB = $rows[$j];
            if ($rowA['language'] !== $rowB['language']) {
                continue;
            }

            $idA = (int)$rowA['id'];
            $idB = (int)$rowB['id'];
            $lang = (string)$rowA['language'];

            $result = score_pair(
                (string)$rowA['confident_text'],
                (string)$rowB['confident_text'],
                $tokensById[$idA],
                $tokensById[$idB],
                $idfByLang[$lang]
            );

            $insert->execute([
                $activityId,
                min($idA, $idB),
                max($idA, $idB),
                $result['hybrid'],
                $result['ngram'],
                $result['tfidf'],
                $result['lev'],
                $result['semantic'],
                $result['hybrid'],
                json_encode($result['matched']),
            ]);

            foreach ([$idA, $idB] as $id) {
                if (!isset($highestBySubmission[$id]) || $result['hybrid'] > $highestBySubmission[$id]) {
                    $highestBySubmission[$id] = $result['hybrid'];
                }
            }
        }
    }

    return $highestBySubmission;
}

/**
 * Decodes a stored matched_sentences JSON blob and returns, per side, the set
 * of sentence indices whose best match is at or above $threshold.
 *
 * @return array{0: array<int,bool>, 1: array<int,bool>}
 */
function highlighted_sentence_map(?string $matchedJson, ?float $threshold = null): array
{
    $threshold = $threshold ?? HIGHLIGHT_THRESHOLD;
    $data = json_decode((string)$matchedJson, true);
    $pairs = is_array($data['pairs'] ?? null) ? $data['pairs'] : [];

    $sideA = [];
    $sideB = [];
    foreach ($pairs as $pair) {
        $best = max((float)($pair['lev'] ?? 0.0), (float)($pair['sem'] ?? 0.0));
        if ($best >= $threshold) {
            $sideA[(int)$pair['a']] = true;
            $sideB[(int)$pair['b']] = true;
        }
    }
    return [$sideA, $sideB];
}
