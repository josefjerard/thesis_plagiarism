#!/usr/bin/env python3
"""Turn folders of essay .txt files into the raw CSV used by ml/build_dataset.py.

Folder layout (relative to the project root):

    ml/data/collect/human/p01__s01__01.txt
    ml/data/collect/ai/p01__chatgpt__01.txt

File name format:  <prompt_id>__<author or tool>__<number>.txt
  * human/ -> author is an anonymous student code (s01, s02, ...)
  * ai/    -> author is the AI tool that wrote it (chatgpt, gemini, copilot, ...)

Output: ml/data/raw/essays.csv with columns
    text,label,language,source,prompt_id
(label 0 = human, 1 = AI; language is always "en" for this project).

Usage (from the project root):
    python ml/collect_to_csv.py
    python ml/collect_to_csv.py --collect-dir ml/data/collect --prompts ml/data/prompts.csv
"""
from __future__ import annotations

import argparse
import csv
import re
import statistics
import sys
from collections import defaultdict
from pathlib import Path

MIN_WORDS = 100          # build_dataset.py drops anything shorter
SOFT_MIN_WORDS = 150     # warn below this
NAME_RE = re.compile(r"^(?P<prompt>[A-Za-z0-9]+)__(?P<author>[A-Za-z0-9\-]+)__(?P<n>\d+)\.txt$")


def clean(text: str) -> str:
    text = text.replace("\ufeff", "").replace("\r\n", "\n").replace("\r", "\n")
    return re.sub(r"\s+", " ", text).strip()


def words(text: str) -> int:
    return len(text.split())


SENT_SPLIT = re.compile(r"(?<=[.!?])\s+")


def reused_sentence_share(texts: list[str]) -> float:
    """Share of long sentences (6+ words) that appear in 3 or more different essays.

    Real essays written one by one almost never repeat whole sentences. A high value
    means the essays were assembled from a template or sentence bank, and a detector
    trained on them would learn the template instead of real writing.
    """
    seen: dict[str, int] = defaultdict(int)
    for t in texts:
        for sent in {x.strip().lower() for x in SENT_SPLIT.split(t) if len(x.split()) >= 6}:
            seen[sent] += 1
    if not seen:
        return 0.0
    return sum(1 for c in seen.values() if c >= 3) / len(seen)


def load_prompt_ids(path: Path) -> set[str]:
    with path.open(encoding="utf-8", newline="") as f:
        return {row["prompt_id"].strip() for row in csv.DictReader(f)}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--collect-dir", default="ml/data/collect")
    ap.add_argument("--prompts", default="ml/data/prompts.csv")
    ap.add_argument("--out", default="ml/data/raw/essays.csv")
    args = ap.parse_args()

    collect = Path(args.collect_dir)
    prompts_path = Path(args.prompts)
    if not collect.is_dir():
        print(f"Folder not found: {collect}", file=sys.stderr)
        return 1
    if not prompts_path.is_file():
        print(f"Prompts file not found: {prompts_path}", file=sys.stderr)
        return 1

    valid_prompts = load_prompt_ids(prompts_path)
    rows: list[dict] = []
    errors: list[str] = []
    warnings: list[str] = []
    seen_text: dict[str, str] = {}
    counts = defaultdict(lambda: {0: 0, 1: 0})
    lengths = {0: [], 1: []}
    authors = {0: set(), 1: set()}

    for label, sub in ((0, "human"), (1, "ai")):
        folder = collect / sub
        if not folder.is_dir():
            warnings.append(f"Missing folder: {folder}")
            continue
        for path in sorted(folder.glob("*.txt")):
            m = NAME_RE.match(path.name)
            if not m:
                errors.append(f"{path}: bad file name (expected <prompt_id>__<author>__<nn>.txt)")
                continue
            pid = m["prompt"]
            author = m["author"].lower()
            if pid not in valid_prompts:
                errors.append(f"{path}: unknown prompt_id '{pid}' (not in {prompts_path.name})")
                continue
            try:
                text = clean(path.read_text(encoding="utf-8"))
            except UnicodeDecodeError:
                errors.append(f"{path}: not valid UTF-8 (re-save as UTF-8 text)")
                continue
            n = words(text)
            if n < MIN_WORDS:
                errors.append(f"{path}: only {n} words (minimum {MIN_WORDS})")
                continue
            if n < SOFT_MIN_WORDS:
                warnings.append(f"{path}: short essay ({n} words)")
            if text in seen_text:
                errors.append(f"{path}: duplicate of {seen_text[text]}")
                continue
            seen_text[text] = str(path)
            rows.append({
                "text": text,
                "label": label,
                "language": "en",
                "source": f"{sub}:{author}",
                "prompt_id": pid,
            })
            counts[pid][label] += 1
            lengths[label].append(n)
            authors[label].add(author)

    # ---- report -------------------------------------------------------------
    total_h = sum(c[0] for c in counts.values())
    total_a = sum(c[1] for c in counts.values())
    print(f"Accepted essays: {len(rows)}  (human {total_h}, AI {total_a})")
    print(f"Prompts with data: {len(counts)} of {len(valid_prompts)}")
    print(f"Human authors: {len(authors[0])}   AI tools: {len(authors[1])} {sorted(authors[1])}")

    for pid in sorted(counts):
        h, a = counts[pid][0], counts[pid][1]
        if h == 0 or a == 0:
            warnings.append(f"{pid}: only one class present (human {h}, AI {a}); give each prompt both")

    for label, name in ((0, "human"), (1, "AI")):
        texts = [r["text"] for r in rows if r["label"] == label]
        if len(texts) >= 10:
            share = reused_sentence_share(texts)
            print(f"Repeated-sentence share ({name}): {share:.0%}")
            if share > 0.10:
                warnings.append(
                    f"{name} essays reuse the same sentences across many essays ({share:.0%} of distinct sentences "
                    "appear in 3+ essays). They look templated; the detector would learn the template. "
                    "Write or generate each essay separately."
                )

    if lengths[0] and lengths[1]:
        mh, ma = statistics.median(lengths[0]), statistics.median(lengths[1])
        print(f"Median length: human {mh:.0f} words, AI {ma:.0f} words")
        if max(mh, ma) > 1.3 * min(mh, ma):
            warnings.append(
                "Human and AI essays differ in length by more than 30%. The detector may learn "
                "length instead of authorship; ask for similar lengths."
            )
    if len(authors[1]) < 3:
        warnings.append("Use at least 3 different AI tools so the detector doesn't learn one tool's style.")
    if total_h < 60 or total_a < 60:
        warnings.append("Fewer than ~60 essays in a class; the trainer needs at least 40 per class in its training split.")

    if warnings:
        print("\nWarnings:")
        for w in warnings:
            print("  -", w)
    if errors:
        print("\nProblems (these files were skipped):")
        for e in errors:
            print("  -", e)

    if not rows:
        print("\nNo valid essays found; nothing written.", file=sys.stderr)
        return 1

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["text", "label", "language", "source", "prompt_id"])
        w.writeheader()
        w.writerows(rows)
    print(f"\nWritten: {out}")
    print("Next: python ml/build_dataset.py   then   python ml/train_detector.py")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())