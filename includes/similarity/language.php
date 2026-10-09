<?php
declare(strict_types=1);

require_once __DIR__ . '/preprocess.php';

/**
 * Lightweight dominant-language detection (English vs Filipino).
 *
 * A full language-ID library is overkill here: the two candidates share the
 * Latin script, so we just count unambiguous function words from each language
 * and pick the larger count. Cross-language plagiarism is explicitly out of
 * scope, so essays in different languages are never compared.
 */

// Markers chosen to avoid words that are valid in BOTH languages.
const LANG_EN_MARKERS = [
    'the', 'and', 'of', 'to', 'is', 'are', 'was', 'were', 'this', 'that',
    'these', 'those', 'with', 'for', 'from', 'have', 'has', 'had', 'not',
    'but', 'they', 'them', 'their', 'there', 'which', 'because', 'about',
    'would', 'could', 'should', 'when', 'where', 'what', 'who', 'how',
    'into', 'also', 'more', 'most', 'than', 'then', 'such', 'between',
];

const LANG_FIL_MARKERS = [
    'ang', 'ng', 'mga', 'sa', 'ay', 'si', 'ni', 'kay', 'para', 'nang',
    'maging', 'dahil', 'upang', 'mula', 'bilang', 'isang', 'sila', 'kami',
    'tayo', 'ako', 'ikaw', 'niya', 'natin', 'namin', 'ninyo', 'kanilang',
    'kaya', 'naman', 'lamang', 'dito', 'diyan', 'doon', 'aking', 'iyong',
    'kanyang', 'hindi', 'wala', 'kung', 'pero', 'ito', 'iyon', 'may',
    'rin', 'din', 'po', 'ba', 'bawat', 'ganito', 'ganoon', 'sapagkat',
];

/** Lazily-built marker lookups so the const lists above stay readable. */
function en_marker_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = array_fill_keys(LANG_EN_MARKERS, true);
    }
    return $map;
}

function fil_marker_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = array_fill_keys(LANG_FIL_MARKERS, true);
    }
    return $map;
}

/**
 * Returns 'fil' or 'en' for the dominant language of $text.
 * Ties (and empty text) fall back to English.
 */
function detect_language(string $text): string
{
    $tokens = tokenize($text, false);
    if ($tokens === []) {
        return 'en';
    }

    $enMarkers  = en_marker_map();
    $filMarkers = fil_marker_map();

    $en = 0;
    $fil = 0;
    foreach ($tokens as $token) {
        if (isset($enMarkers[$token])) {
            $en++;
        }
        if (isset($filMarkers[$token])) {
            $fil++;
        }
    }

    return $fil > $en ? 'fil' : 'en';
}

/** Human-readable language name. */
function language_label(string $code): string
{
    return $code === 'fil' ? 'Filipino' : 'English';
}
