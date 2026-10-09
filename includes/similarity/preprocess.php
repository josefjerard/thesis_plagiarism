<?php
declare(strict_types=1);

/**
 * Shared preprocessing for all similarity methods.
 *
 * Two flavours of normalization are used on purpose:
 *   - normalize_text(): ASCII-safe, stopwords KEPT. Used by n-gram and
 *     Levenshtein so that copied sentences still line up character by character.
 *   - tokenize(): Unicode-aware, stopwords optionally removed. Used by TF-IDF
 *     (and available to language detection), where function words add noise.
 */

// Function words that carry little topic information.
const EN_STOPWORDS = [
    'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by',
    'for', 'from', 'with', 'about', 'into', 'through', 'during', 'before',
    'after', 'above', 'below', 'up', 'down', 'out', 'off', 'over', 'under',
    'again', 'further', 'then', 'once', 'here', 'there', 'when', 'where',
    'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most',
    'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so',
    'than', 'too', 'very', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'am', 'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing',
    'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might',
    'must', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it',
    'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your', 'his',
    'its', 'our', 'their', 'mine', 'yours', 'hers', 'ours', 'theirs', 'as',
];

// Common Filipino function words / particles.
const FIL_STOPWORDS = [
    'ang', 'ng', 'mga', 'sa', 'na', 'ay', 'at', 'si', 'ni', 'kay', 'para',
    'nang', 'din', 'rin', 'ba', 'po', 'ito', 'iyon', 'iyan', 'may', 'wala',
    'hindi', 'oo', 'kung', 'dahil', 'pero', 'upang', 'mula', 'bilang',
    'isang', 'sila', 'kami', 'tayo', 'ako', 'ikaw', 'ka', 'niya', 'natin',
    'namin', 'ninyo', 'kanila', 'kanilang', 'kaya', 'naman', 'lang',
    'lamang', 'pa', 'dito', 'diyan', 'doon', 'aking', 'iyong', 'kanyang',
    'nilang', 'nito', 'ninyong', 'sabihin', 'ganito', 'ganoon', 'kung',
    'nag', 'mag', 'pag', 'pumunta', 'ang', 'maging', 'yung', 'yun', 'nga',
];

/** ASCII-safe normalization for n-gram + Levenshtein (stopwords kept). */
function normalize_text(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim($text);
}

/** Lazily-built lookup map of every stopword. */
function stopword_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = array_fill_keys(array_merge(EN_STOPWORDS, FIL_STOPWORDS), true);
    }
    return $map;
}

function is_stopword(string $word): bool
{
    return isset(stopword_map()[$word]);
}

/**
 * Unicode-aware tokenizer. Preserves letters with diacritics; drops punctuation.
 * Stopword removal is intended for TF-IDF only.
 */
function tokenize(string $text, bool $removeStopwords = true): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
    $raw  = preg_split('/\s+/u', trim($text)) ?: [];

    $out = [];
    foreach ($raw as $word) {
        if ($word === '') {
            continue;
        }
        if ($removeStopwords && is_stopword($word)) {
            continue;
        }
        $out[] = $word;
    }
    return $out;
}

/** Splits text into sentences on terminal punctuation and line breaks. */
function split_sentences(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $parts = preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [];

    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '' && mb_strlen($part, 'UTF-8') >= 3) {
            $out[] = $part;
        }
    }
    return $out;
}
