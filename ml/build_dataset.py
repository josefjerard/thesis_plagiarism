#!/usr/bin/env python3
"""Builds the training/validation/test split for the AI-text detector.

Reads every CSV under --raw-dir (columns `text,label,language,source,prompt_id`),
cleans and chunks long essays, then splits by `prompt_id` (70/15/15) so the same
topic never appears in both training and test data.

Usage:
    python build_dataset.py [--raw-dir ml/data/raw] [--data-dir ml/data]
"""

import argparse
import glob
import os
import random
import re
import sys
from pathlib import Path

import pandas as pd

WORDS_RE = re.compile(r"[a-z0-9']+", re.IGNORECASE)


def word_count(text: str) -> int:
    return len(WORDS_RE.findall(text or ""))


def clean_text(text: str) -> str:
    text = re.sub(r"\s+", " ", (text or "").strip())
    return text


def chunk_long(text: str, lo: int = 150, hi: int = 300) -> list[str]:
    """Splits a long essay into 150-300 word chunks on sentence boundaries."""
    words = WORDS_RE.findall(text or "")
    if len(words) <= hi:
        return [text]
    # Split roughly on sentence boundaries: use simple whitespace windows.
    tokens_spaced = (text or "").split()
    if len(tokens_spaced) <= hi:
        return [text]
    chunks, start = [], 0
    while start < len(tokens_spaced):
        end = min(start + hi, len(tokens_spaced))
        if len(tokens_spaced) - end < lo:
            end = len(tokens_spaced)
        chunks.append(" ".join(tokens_spaced[start:end]))
        if end >= len(tokens_spaced):
            break
        start = end - lo  # small overlap keeps sentences from being split oddly
    return chunks


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--raw-dir", default="ml/data/raw", help="folder of raw CSVs")
    ap.add_argument("--data-dir", default="ml/data", help="output folder")
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    raw_dir = Path(args.raw_dir)
    files = sorted(glob.glob(str(raw_dir / "*.csv")))
    if not files:
        print(f"No raw CSVs found in {raw_dir}.", file=sys.stderr)
        print("Each CSV needs columns: text,label,language,source,prompt_id", file=sys.stderr)
        raise SystemExit(1)

    frames = [pd.read_csv(f) for f in files]
    df = pd.concat(frames, ignore_index=True)

    required = {"text", "label", "language", "source", "prompt_id"}
    missing = required - set(df.columns)
    if missing:
        raise SystemExit(f"CSV(s) missing columns: {missing}")

    df["text"] = df["text"].map(clean_text)
    df = df[df["text"].str.len() > 20]
    df = df[df["label"].isin([0, 1])]
    df = df[df["language"].isin(["en", "fil"])]

    # Deduplicate on normalized text.
    df = df.drop_duplicates(subset=["text"])

    # Drop very short texts, then chunk long essays.
    df = df[df["text"].map(word_count) >= 100].copy()
    rows = []
    for _, row in df.iterrows():
        for piece in chunk_long(row["text"]):
            if word_count(piece) >= 100:
                rows.append({
                    "text": piece,
                    "label": int(row["label"]),
                    "language": str(row["language"]),
                    "source": str(row["source"]),
                    "prompt_id": str(row["prompt_id"]),
                })
    df = pd.DataFrame(rows)

    # Split by prompt_id with a fixed seed (student + AI share prompt_ids).
    rng = random.Random(args.seed)
    prompts = sorted(df["prompt_id"].unique())
    rng.shuffle(prompts)
    n = len(prompts)
    n_train = int(round(n * 0.70))
    n_val = int(round(n * 0.15))
    train_prompts = set(prompts[:n_train])
    val_prompts = set(prompts[n_train:n_train + n_val])
    test_prompts = set(prompts[n_train + n_val:])

    by_prompt = {p: df[df["prompt_id"] == p] for p in prompts}
    train = pd.concat([by_prompt[p] for p in prompts if p in train_prompts])
    val = pd.concat([by_prompt[p] for p in prompts if p in val_prompts])
    test = pd.concat([by_prompt[p] for p in prompts if p in test_prompts])

    out = Path(args.data_dir)
    out.mkdir(parents=True, exist_ok=True)
    train.to_csv(out / "train.csv", index=False)
    val.to_csv(out / "val.csv", index=False)
    test.to_csv(out / "test.csv", index=False)

    def counts(frame: pd.DataFrame) -> str:
        return "; ".join(
            f"{lang}/{label}:{int(((frame['label'] == label) & (frame['language'] == lang)).sum())}"
            for lang in ("en", "fil") for label in (0, 1)
        )

    print(f"Clean samples: {len(df)}  ({len(prompts)} prompts)")
    print(f"  train: {len(train)}  {counts(train)}")
    print(f"  val:   {len(val)}  {counts(val)}")
    print(f"  test:  {len(test)}  {counts(test)}")
    print("Written to", out)


if __name__ == "__main__":
    main()