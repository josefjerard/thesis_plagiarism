<?php
declare(strict_types=1);

/**
 * Internet source checking (runs at scan time).
 *
 * Current mode: 'local'. Each essay is split into chunks, the most substantial
 * chunks are compared against a local reference corpus (plain-text files in
 * REFERENCE_DIR) with n-gram, TF-IDF and Levenshtein similarity, and the top
 * matches are reported. This is fully self-contained.
 *
 * The 'search' mode (finding candidate pages through a search API) is planned
 * but not implemented yet.
 */

require_once __DIR__ . '/similarity/preprocess.php';
require_once __DIR__ . '/similarity/ngram.php';
require_once __DIR__ . '/similarity/tfidf.php';
require_once __DIR__ . '/similarity/levenshtein.php';

// Fallbacks so the engine still runs if config.php predates a setting.
if (!defined('WEB_SOURCE_MODE'))      define('WEB_SOURCE_MODE', 'local');
if (!defined('WEB_MAX_PAGES'))        define('WEB_MAX_PAGES', 5);
if (!defined('WEB_CHUNK_SENTENCES'))  define('WEB_CHUNK_SENTENCES', 4);
if (!defined('REFERENCE_DIR'))        define('REFERENCE_DIR', __DIR__ . '/../reference_sources');
if (!defined('WEB_MATCH_MIN_SIM'))    define('WEB_MATCH_MIN_SIM', 0.25);
if (!defined('WEB_MAX_DOC_CHARS'))    define('WEB_MAX_DOC_CHARS', 20000);

function web_source_check_enabled(): bool
{
    return WEB_SOURCE_MODE === 'local'
        && defined('REFERENCE_DIR')
        && is_dir(REFERENCE_DIR);
}

/**
 * @return array<string,string> filename => plain text for every reference
 *                               document under REFERENCE_DIR.
 */
function reference_docs(): array
{
    $docs = [];
    if (!is_dir(REFERENCE_DIR)) {
        return $docs;
    }

    $handle = opendir(REFERENCE_DIR);
    if ($handle === false) {
        return $docs;
    }

    while (($entry = readdir($handle)) !== false) {
        if (!is_file(REFERENCE_DIR . DIRECTORY_SEPARATOR . $entry)) {
            continue;
        }
        if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'txt') {
            continue;
        }
        $content = file_get_contents(REFERENCE_DIR . DIRECTORY_SEPARATOR . $entry);
        if ($content === false || trim($content) === '') {
            continue;
        }
        $docs[$entry] = $content;
    }
    closedir($handle);

    return $docs;
}

/**
 * Splits an essay into sentence groups of about WEB_CHUNK_SENTENCES sentences.
 *
 * @return array<int,array{start:int,text:string}>
 */
function web_chunk_essay(string $text): array
{
    $sentences = split_sentences($text);
    $size      = max(1, (int)WEB_CHUNK_SENTENCES);

    $chunks = [];
    $count  = count($sentences);
    for ($start = 0; $start < $count; $start += $size) {
        $slice = array_slice($sentences, $start, $size);
        $chunks[] = ['start' => $start, 'text' => implode(' ', $slice)];
    }
    return $chunks;
}

/**
 * Picks the most substantial chunks: those with the most distinct content
 * (non-stopword) tokens, since copied passages usually carry real content.
 *
 * @return array<int,array{start:int,text:string}>
 */
function web_select_chunks(array $chunks, int $limit): array
{
    $scored = [];
    foreach ($chunks as $chunk) {
        $tokens = array_unique(tokenize($chunk['text'], true));
        $scored[] = ['chunk' => $chunk, 'weight' => count($tokens)];
    }

    usort($scored, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
    $scored = array_slice($scored, 0, max(1, $limit));

    $out = [];
    foreach ($scored as $item) {
        if ($item['weight'] >= 6) {
            $out[] = $item['chunk'];
        }
    }
    return $out !== [] ? $out : [current($scored)['chunk']];
}

/**
 * Scores one essay chunk against one reference document.
 *
 * @return array{source:string, similarity:float, ngram:float, tfidf:float,
 *               lev:float, passages:array}|null
 */
function web_score_chunk(string $source, string $chunkText, int $chunkStart, string $docText, array $idf): ?array
{
    $normChunk = normalize_text($chunkText);
    $normDoc   = normalize_text($docText);
    if ($normChunk === '' || $normDoc === '') {
        return null;
    }

    $ngram = text_similarity($normChunk, $normDoc) ?? 0.0;

    $tokensChunk = tokenize($chunkText, true);
    $tokensDoc   = tokenize($docText, true);
    $tfidf = ($tokensChunk !== [] && $tokensDoc !== [])
        ? cosine_similarity(tfidf_vector($tokensChunk, $idf), tfidf_vector($tokensDoc, $idf))
        : 0.0;

    $sentChunk = split_sentences($chunkText);
    $sentDoc   = split_sentences($docText);
    $levResult = levenshtein_similarity($sentChunk, $sentDoc);
    $lev  = $levResult['score'];

    // Overlapping passages: essay sentence (global index) -> source sentence.
    $passages = [];
    foreach ($levResult['pairs'] as $pair) {
        if ($pair['score'] >= 0.45 && isset($sentDoc[(int)$pair['b']])) {
            $passages[] = [
                'essay_idx' => $chunkStart + (int)$pair['a'],
                'src'       => $sentDoc[(int)$pair['b']],
                'score'     => round($pair['score'], 4),
            ];
        }
    }

    $similarity = ($ngram + $tfidf + $lev) / 3.0;
    if ($similarity < WEB_MATCH_MIN_SIM) {
        return null;
    }

    return [
        'source'     => $source,
        'similarity' => round($similarity, 4),
        'ngram'      => round($ngram, 4),
        'tfidf'      => round($tfidf, 4),
        'lev'        => round($lev, 4),
        'passages'   => $passages,
    ];
}

/**
 * Runs the whole check: chunk the essay, compare against every reference doc,
 * and return the best unique matches, best first (capped at WEB_MAX_PAGES).
 *
 * @return array  list of match maps as produced by web_score_chunk()
 */
function web_match_essay(string $text): array
{
    if (!web_source_check_enabled() || trim($text) === '') {
        return [];
    }

    $docs = reference_docs();
    if ($docs === []) {
        return [];
    }

    // Truncate very long reference documents for speed.
    foreach ($docs as $name => $content) {
        if (mb_strlen($content, 'UTF-8') > WEB_MAX_DOC_CHARS) {
            $docs[$name] = mb_substr($content, 0, WEB_MAX_DOC_CHARS, 'UTF-8');
        }
    }

    // IDF over the reference corpus + the essay itself.
    $corpus = [];
    foreach ($docs as $content) {
        $corpus[] = tokenize($content, true);
    }
    $corpus[] = tokenize($text, true);
    $idf = build_idf($corpus);

    $chunks  = web_select_chunks(web_chunk_essay($text), (int)WEB_MAX_PAGES);
    $matches = [];
    foreach ($chunks as $chunk) {
        $chunkText = (string)$chunk['text'];
        foreach ($docs as $name => $content) {
            $hit = web_score_chunk($name, $chunkText, (int)$chunk['start'], $content, $idf);
            if ($hit !== null) {
                $matches[] = $hit;
            }
        }
    }

    // Best per source, then overall best WEB_MAX_PAGES matches.
    $bestBySource = [];
    foreach ($matches as $hit) {
        if (!isset($bestBySource[$hit['source']]) || $hit['similarity'] > $bestBySource[$hit['source']]['similarity']) {
            $bestBySource[$hit['source']] = $hit;
        }
    }

    $ranked = array_values($bestBySource);
    usort($ranked, static fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
    return array_slice($ranked, 0, max(1, (int)WEB_MAX_PAGES));
}

/** Persists web matches for one submission. */
function store_web_sources(int $submissionId, array $matches): void
{
    if ($matches === []) {
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO web_sources (submission_id, source_name, similarity, ngram_score, tfidf_score, lev_score, matched_passages)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($matches as $match) {
        $stmt->execute([
            $submissionId,
            (string)$match['source'],
            (float)$match['similarity'],
            (float)$match['ngram'],
            (float)$match['tfidf'],
            (float)$match['lev'],
            json_encode(['passages' => $match['passages']]),
        ]);
    }
}

/** Orchestrator used by the upload pipeline: compute + store. */
function run_web_source_check(int $submissionId, string $text): void
{
    if (!web_source_check_enabled()) {
        return;
    }
    store_web_sources($submissionId, web_match_essay($text));
}