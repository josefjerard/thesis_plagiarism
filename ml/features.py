"""Shared feature extraction for the AI-text detector.

Both `ml/train_detector.py` and `ai_service/app.py` use these functions so the
serving features are identical to the training features. The feature set follows
the thesis plan: sentence-length average and variation, vocabulary richness,
punctuation and function-word usage, repetition and transition-phrase patterns,
plus word and character n-gram TF-IDF (added by the vectorizers in training).
"""

import re
import statistics

import numpy as np

EN_FUNCTION_WORDS = {
    "the", "and", "of", "to", "in", "on", "at", "by", "for", "from", "with",
    "that", "this", "these", "those", "is", "are", "was", "were", "be", "been",
    "have", "has", "had", "will", "would", "can", "could", "should", "may",
    "might", "must", "not", "but", "or", "as", "which", "when", "where", "who",
    "it", "its", "we", "they", "their", "them", "there", "more", "most", "than",
    "then", "such", "very", "so", "too", "also", "into", "because", "about",
}

FIL_FUNCTION_WORDS = {
    "ang", "ng", "mga", "sa", "na", "ay", "at", "si", "ni", "kay", "para",
    "nang", "din", "rin", "ba", "po", "ito", "iyon", "iyan", "may", "wala",
    "hindi", "oo", "kung", "dahil", "pero", "upang", "mula", "bilang",
    "isang", "sila", "kami", "tayo", "ako", "ikaw", "ka", "niya", "natin",
    "namin", "ninyo", "kanila", "kanilang", "kaya", "naman", "lang",
    "lamang", "pa", "dito", "diyan", "doon", "aking", "iyong", "kanyang",
    "ganito", "ganoon", "maging", "yung", "yun",
}

TRANSITION_PHRASES = [
    "in conclusion", "for example", "in addition", "on the other hand",
    "as a result", "moreover", "however", "therefore", "first of all",
    "lastly", "in other words", "it is important", "all in all",
    "sa kabuuan", "halimbawa", "bukod dito", "sa madaling salita",
    "sa aking palagay", "mula noon", "samakatuwid", "dahil dito",
]

_TOKEN_RE = re.compile(r"[a-z0-9']+")


def tokens(text: str) -> list[str]:
    """Lowercases and keeps latin letters, digits and apostrophes."""
    return _TOKEN_RE.findall((text or "").lower())


def sentences(text: str) -> list[str]:
    """Splits on terminal punctuation and newlines."""
    parts = re.split(r"(?<=[.!?])\s+|\n+", (text or "").strip())
    return [p.strip() for p in parts if len(p.strip()) >= 3]


def stat_features(text: str, is_fil: float = 0.0) -> np.ndarray:
    """The hand-designed numeric features for one text, plus a language flag.

    Returns a row vector of 8 features:
    mean sentence length, sentence-length std, vocabulary richness (TTR),
    repetition ratio, punctuation ratio, function-word ratio,
    transition-phrase hits, and the language flag.
    """
    toks = tokens(text)
    if not toks:
        return np.zeros(8)

    sent_toks = [tokens(s) for s in sentences(text) or [text]]
    sent_lens = [max(1, len(t)) for t in sent_toks]

    uniq = len(set(toks))
    total = len(toks)
    char_total = max(1, len(text))

    mean_len = float(np.mean(sent_lens))
    std_len = float(np.std(sent_lens))
    ttr = uniq / total
    rept = (total - uniq) / total
    punct = sum(ch in '.,;:!?\'"-()[]{}«»“”' for ch in text) / char_total
    func = sum(t in EN_FUNCTION_WORDS or t in FIL_FUNCTION_WORDS for t in toks) / total
    trans = sum(text.lower().count(p) for p in TRANSITION_PHRASES)
    if trans > 0:
        trans = min(2.0, trans)

    return np.array([mean_len, std_len, ttr, rept, punct, func, trans, is_fil])


def reset_engines() -> None:
    """Placeholder for symmetry; nothing to reset in pure functions."""
    return None


STAT_FEATURE_NAMES = [
    "sent_len_mean", "sent_len_std", "ttr", "repetition",
    "punct_ratio", "func_ratio", "transitions", "is_fil",
]