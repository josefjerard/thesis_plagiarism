#!/usr/bin/env python3
"""Trains the per-language semantic embedding models (FastText, preferred).

Sentence vectors are then built by averaging word vectors at service time
(ai_service/app.py). FastText is chosen over Word2Vec because its subword
vectors handle Filipino word forms and OCR typos better.

Usage:
    python train_embeddings.py [--data-dir ml/data] [--models-dir ai_service/models]
"""

import argparse
import re
from pathlib import Path

import pandas as pd
from gensim.models import FastText, Word2Vec

TOKEN_RE = re.compile(r"[a-z0-9']+")


def tokenize(text: str) -> list[str]:
    return TOKEN_RE.findall((text or "").lower())


def train_language(lang: str, df: pd.DataFrame, models_dir: Path) -> None:
    corpus = [tokenize(t) for t in df["text"]]
    corpus = [t for t in corpus if len(t) >= 30]
    if len(corpus) < 100:
        print(f"language '{lang}': only {len(corpus)} usable texts, skipping.")
        return

    print(f"\n=== language '{lang}': {len(corpus)} texts ===")

    kwargs = dict(vector_size=100, window=5, min_count=2, workers=4,
                  sg=1, epochs=15, seed=42)
    model = FastText(corpus, **kwargs)

    models_dir.mkdir(parents=True, exist_ok=True)
    path = models_dir / f"fasttext_{lang}.model"
    model.save(str(path))
    print(f"Saved {path} (vocab {len(model.wv):,})")

    # Word2Vec variant is kept as a comparison option (see thesis plan).
    w2v = Word2Vec(corpus, **kwargs)
    w2v_path = models_dir / f"word2vec_{lang}.model"
    w2v.save(str(w2v_path))
    print(f"Saved {w2v_path} (vocab {len(w2v.wv):,})")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--data-dir", default="ml/data")
    ap.add_argument("--models-dir", default="ai_service/models")
    args = ap.parse_args()

    data_dir = Path(args.data_dir)
    frames = []
    for split in ("train", "val", "test"):
        path = data_dir / f"{split}.csv"
        if path.exists():
            frames.append(pd.read_csv(path))
    if not frames:
        raise SystemExit("No train/val/test CSVs found — run build_dataset.py first.")

    df = pd.concat(frames, ignore_index=True)
    for lang in ("en", "fil"):
        train_language(lang, df[df["language"] == lang], Path(args.models_dir))


if __name__ == "__main__":
    main()