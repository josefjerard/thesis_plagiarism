<?php
declare(strict_types=1);

/**
 * Internet source checking (runs at scan time).
 *
 * Essays can be checked against a local reference corpus, web search results,
 * or both. Search results are candidate pages; their snippets are compared
 * with selected essay sentences before they are reported.
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
if (!defined('WEB_SEARCH_PROVIDER')) define('WEB_SEARCH_PROVIDER', 'serper');
if (!defined('WEB_SEARCH_API_KEY')) define('WEB_SEARCH_API_KEY', '');
if (!defined('WEB_SEARCH_MAX_QUERIES')) define('WEB_SEARCH_MAX_QUERIES', 8);
if (!defined('WEB_SEARCH_RESULTS_PER_QUERY')) define('WEB_SEARCH_RESULTS_PER_QUERY', 5);
if (!defined('WEB_SEARCH_TIMEOUT')) define('WEB_SEARCH_TIMEOUT', 10);
if (!defined('WEB_SEARCH_PHRASE_WORDS')) define('WEB_SEARCH_PHRASE_WORDS', 18);
if (!defined('WEB_SEARCH_MIN_SENTENCE_WORDS')) define('WEB_SEARCH_MIN_SENTENCE_WORDS', 10);
if (!defined('WEB_SEARCH_CACHE_DIR')) define('WEB_SEARCH_CACHE_DIR', __DIR__ . '/../cache/websearch');

function web_source_check_enabled(): bool
{
    if (WEB_SOURCE_MODE === 'local') {
        return defined('REFERENCE_DIR') && is_dir(REFERENCE_DIR);
    }

    return in_array(WEB_SOURCE_MODE, ['search', 'both'], true)
        && trim((string)WEB_SEARCH_API_KEY) !== '';
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
    if (!in_array(WEB_SOURCE_MODE, ['local', 'both'], true)
        || !is_dir(REFERENCE_DIR)
        || trim($text) === '') {
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

/**
 * Selects high-content sentences while taking turns across essay paragraphs.
 *
 * @return array<int,array{essay_idx:int,sentence:string}>
 */
function web_select_query_sentences(string $text, int $limit): array
{
    if ($limit <= 0) {
        return [];
    }

    $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];
    $candidatesByParagraph = [];
    $essayIndex = 0;

    foreach ($paragraphs as $paragraphIndex => $paragraph) {
        $sentences = split_sentences($paragraph);
        foreach ($sentences as $sentence) {
            $essayIndex++;
            $sentenceIndex = $essayIndex - 1;
            $withoutUrls = preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $sentence) ?? $sentence;
            $words = tokenize($withoutUrls, false);
            if (count($words) < (int)WEB_SEARCH_MIN_SENTENCE_WORDS) {
                continue;
            }

            $digitCount = count(array_filter(
                $words,
                static fn(string $word): bool => preg_match('/^\d+$/u', $word) === 1
            ));
            $urlCount = preg_match_all('~(?:https?://|www\.)\S+~iu', $sentence);
            if ($digitCount >= count($words) / 2 || ($urlCount > 0 && $urlCount >= count($words))) {
                continue;
            }

            $weight = count(array_unique(tokenize($sentence, true)));
            if ($weight === 0) {
                continue;
            }

            $candidatesByParagraph[$paragraphIndex][] = [
                'essay_idx' => $sentenceIndex,
                'sentence' => $sentence,
                'weight' => $weight,
            ];
        }
    }

    foreach ($candidatesByParagraph as &$candidates) {
        usort($candidates, static function (array $a, array $b): int {
            return ($b['weight'] <=> $a['weight']) ?: ($a['essay_idx'] <=> $b['essay_idx']);
        });
    }
    unset($candidates);

    $paragraphOrder = array_keys($candidatesByParagraph);
    usort($paragraphOrder, static function (int $a, int $b) use ($candidatesByParagraph): int {
        return ($candidatesByParagraph[$b][0]['weight'] <=> $candidatesByParagraph[$a][0]['weight'])
            ?: ($a <=> $b);
    });

    $selected = [];
    for ($rank = 0; count($selected) < $limit; $rank++) {
        $foundAtRank = false;
        foreach ($paragraphOrder as $paragraphIndex) {
            if (!isset($candidatesByParagraph[$paragraphIndex][$rank])) {
                continue;
            }
            $candidate = $candidatesByParagraph[$paragraphIndex][$rank];
            $selected[] = [
                'essay_idx' => $candidate['essay_idx'],
                'sentence' => $candidate['sentence'],
            ];
            $foundAtRank = true;
            if (count($selected) >= $limit) {
                break;
            }
        }
        if (!$foundAtRank) {
            break;
        }
    }

    return $selected;
}

/** Builds the exact-phrase query and reads/writes its response cache. */
function web_search_query(string $phrase): array
{
    $cacheFile = rtrim((string)WEB_SEARCH_CACHE_DIR, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . sha1($phrase) . '.json';

    if (is_file($cacheFile)) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    try {
        if (WEB_SEARCH_PROVIDER !== 'serper' || trim((string)WEB_SEARCH_API_KEY) === '') {
            throw new RuntimeException('Search provider is unavailable or not configured.');
        }

        $words = preg_split('/\s+/u', trim($phrase)) ?: [];
        $queryPhrase = implode(' ', array_slice($words, 0, max(1, (int)WEB_SEARCH_PHRASE_WORDS)));
        $body = json_encode([
            'q' => '"' . $queryPhrase . '"',
            'num' => max(1, (int)WEB_SEARCH_RESULTS_PER_QUERY),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Could not encode the search request.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is unavailable.');
        }

        $curl = curl_init('https://google.serper.dev/search');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the search request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . trim((string)WEB_SEARCH_API_KEY),
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)WEB_SEARCH_TIMEOUT),
            CURLOPT_TIMEOUT => max(1, (int)WEB_SEARCH_TIMEOUT),
        ]);

        $response = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if (!is_string($response) || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                'Search request failed' . ($curlError !== '' ? ': ' . $curlError : " (HTTP $statusCode).")
            );
        }

        $payload = json_decode($response, true);
        if (!is_array($payload) || !is_array($payload['organic'] ?? null)) {
            throw new RuntimeException('The search provider returned an invalid response.');
        }

        $results = [];
        foreach ($payload['organic'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['link'] ?? ''));
            if (mb_strlen($url, 'UTF-8') > 500
                || !preg_match('~^https?://~i', $url)
                || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $results[] = [
                'url' => $url,
                'title' => trim((string)($item['title'] ?? '')),
                'snippet' => trim((string)($item['snippet'] ?? '')),
            ];
        }

        if (!is_dir((string)WEB_SEARCH_CACHE_DIR)
            && !mkdir((string)WEB_SEARCH_CACHE_DIR, 0777, true)
            && !is_dir((string)WEB_SEARCH_CACHE_DIR)) {
            error_log('Web search response could not be cached: cache directory creation failed.');
            return $results;
        }
        $encodedResults = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedResults === false
            || file_put_contents($cacheFile, $encodedResults, LOCK_EX) === false) {
            error_log('Web search response could not be cached.');
        }

        return $results;
    } catch (Throwable $error) {
        $GLOBALS['web_search_error_occurred'] = true;
        error_log('Web source search failed: ' . $error->getMessage());
        return [];
    }
}

/** Scores a sentence/snippet pair by n-gram similarity and phrase containment. */
function web_score_search_result(string $sentence, string $snippet): array
{
    $normalizedSentence = normalize_text($sentence);
    $normalizedSnippet = normalize_text($snippet);
    $ngram = text_similarity($normalizedSentence, $normalizedSnippet) ?? 0.0;

    $phraseWords = $normalizedSentence === '' ? [] : explode(' ', $normalizedSentence);
    $snippetWords = $normalizedSnippet === '' ? [] : explode(' ', $normalizedSnippet);
    $snippetFourgrams = [];
    for ($i = 0, $count = count($snippetWords) - 3; $i < $count; $i++) {
        $snippetFourgrams[implode(' ', array_slice($snippetWords, $i, 4))] = true;
    }

    $sequenceCount = max(0, count($phraseWords) - 3);
    $containedCount = 0;
    for ($i = 0; $i < $sequenceCount; $i++) {
        if (isset($snippetFourgrams[implode(' ', array_slice($phraseWords, $i, 4))])) {
            $containedCount++;
        }
    }
    $containment = $sequenceCount > 0 ? $containedCount / $sequenceCount : 0.0;

    return [
        'ngram' => $ngram,
        'containment' => $containment,
        'score' => max($ngram, $containment),
    ];
}

/** Searches selected sentences and aggregates the strongest snippet per URL. */
function web_search_matches(string $text): array
{
    $sentences = web_select_query_sentences($text, (int)WEB_SEARCH_MAX_QUERIES);
    $bestByUrl = [];
    foreach ($sentences as $querySentence) {
        $results = web_search_query($querySentence['sentence']);
        foreach ($results as $result) {
            $snippet = trim((string)($result['snippet'] ?? ''));
            if ($snippet === '') {
                continue;
            }

            $scores = web_score_search_result($querySentence['sentence'], $snippet);
            if ($scores['containment'] < 0.5 && $scores['ngram'] < (float)WEB_MATCH_MIN_SIM) {
                continue;
            }

            $url = (string)$result['url'];
            $essayIndex = (int)$querySentence['essay_idx'];
            if (!isset($bestByUrl[$url])) {
                $bestByUrl[$url] = [
                    'source' => $url,
                    'title' => (string)($result['title'] ?? ''),
                    'similarity' => 0.0,
                    'ngram' => 0.0,
                    'tfidf' => 0.0,
                    'lev' => 0.0,
                    'passages' => [],
                    'matched_indices' => [],
                ];
            }

            $match = &$bestByUrl[$url];
            $match['similarity'] = max($match['similarity'], $scores['score']);
            $match['ngram'] = max($match['ngram'], $scores['ngram']);
            if ($match['title'] === '' && (string)($result['title'] ?? '') !== '') {
                $match['title'] = (string)$result['title'];
            }
            $passage = [
                'essay_idx' => $essayIndex,
                'src' => $snippet,
                'score' => round($scores['score'], 4),
            ];
            if (!isset($match['matched_indices'][$essayIndex])) {
                $match['matched_indices'][$essayIndex] = count($match['passages']);
                $match['passages'][] = $passage;
            } elseif ($passage['score'] > $match['passages'][$match['matched_indices'][$essayIndex]]['score']) {
                $match['passages'][$match['matched_indices'][$essayIndex]] = $passage;
            }
            unset($match);
        }
    }

    $ranked = array_values($bestByUrl);
    foreach ($ranked as &$match) {
        unset($match['matched_indices']);
        $match['similarity'] = round($match['similarity'], 4);
        $match['ngram'] = round($match['ngram'], 4);
    }
    unset($match);
    usort($ranked, static function (array $a, array $b): int {
        return ($b['similarity'] <=> $a['similarity'])
            ?: (count($b['passages']) <=> count($a['passages']));
    });

    return array_slice($ranked, 0, max(0, (int)WEB_MAX_PAGES));
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
        $passageData = ['passages' => $match['passages']];
        if (isset($match['title']) && (string)$match['title'] !== '') {
            $passageData['title'] = (string)$match['title'];
        }
        $stmt->execute([
            $submissionId,
            (string)$match['source'],
            (float)$match['similarity'],
            (float)$match['ngram'],
            (float)$match['tfidf'],
            (float)$match['lev'],
            json_encode($passageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

/** Orchestrator used by the upload pipeline: compute + store. */
function run_web_source_check(int $submissionId, string $text): void
{
    $status = 'ok';
    $matches = [];
    $mode = (string)WEB_SOURCE_MODE;
    $needsSearch = in_array($mode, ['search', 'both'], true);
    $keyConfigured = trim((string)WEB_SEARCH_API_KEY) !== '';

    try {
        if (in_array($mode, ['local', 'both'], true)) {
            $matches = array_merge($matches, web_match_essay($text));
        }

        if ($needsSearch) {
            if (!$keyConfigured) {
                $status = 'unavailable';
            } else {
                $GLOBALS['web_search_error_occurred'] = false;
                try {
                    $matches = array_merge($matches, web_search_matches($text));
                } catch (Throwable $error) {
                    $GLOBALS['web_search_error_occurred'] = true;
                    error_log('Web source search flow failed: ' . $error->getMessage());
                }
                if (!empty($GLOBALS['web_search_error_occurred'])) {
                    $status = 'error';
                }
            }
        } elseif ($mode !== 'local') {
            $status = 'error';
        }

        $bestBySource = [];
        foreach ($matches as $match) {
            $source = (string)$match['source'];
            if (!isset($bestBySource[$source])
                || (float)$match['similarity'] > (float)$bestBySource[$source]['similarity']) {
                $bestBySource[$source] = $match;
            }
        }
        $matches = array_values($bestBySource);
        usort($matches, static fn(array $a, array $b): int => (float)$b['similarity'] <=> (float)$a['similarity']);
        $matches = array_slice($matches, 0, max(0, (int)WEB_MAX_PAGES));
        store_web_sources($submissionId, $matches);
    } catch (Throwable $error) {
        $status = 'error';
        error_log('Web source check failed: ' . $error->getMessage());
    }

    try {
        $stmt = db()->prepare('UPDATE submissions SET web_check_status = ? WHERE id = ?');
        $stmt->execute([$status, $submissionId]);
    } catch (Throwable $error) {
        error_log('Web source status could not be saved: ' . $error->getMessage());
    }
}