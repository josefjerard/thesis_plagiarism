#!/usr/bin/env python3
"""Evaluates the AI detector on the held-out test set and, when a labeled
similarity-pair file is present, compares the self-trained embedding model
against the first-version pretrained model.

Detector reports precision / recall / F1 / confusion matrix per language, split
by input condition (typed, OCR of printed text, OCR of handwriting) when the
test CSV has a `condition` column.

Usage:
    python evaluate.py [--data-dir ml/data] [--models-dir ai_service/models]
                       [--reports-dir ml/reports]
"""

import argparse
import json
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.metrics import (classification_report, confusion_matrix,
                             precision_recall_fscore_support)

# Make `from ml.features import ...` work regardless of how this script is
# invoked (python ml/evaluate.py, python -m ml.evaluate, ...).
import os
import sys
_REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _REPO_ROOT not in sys.path:
    sys.path.insert(0, _REPO_ROOT)

from ml.features import stat_features


def load_artifact_by_lang(model_dir: Path):
    def _load(lang: str):
        path = model_dir / f"ai_detector_{lang}.joblib"
        if path.exists():
            return joblib.load(path)
        if lang == "fil":
            alt = model_dir / "ai_detector_en.joblib"
            return joblib.load(alt) if alt.exists() else None
        return None

    return _load


def eval_detector(models_dir: Path, reports_dir: Path, test: pd.DataFrame) -> None:
    loader = load_artifact_by_lang(models_dir)
    reports_dir.mkdir(parents=True, exist_ok=True)

    for lang in ("en", "fil"):
        artifact = loader(lang)
        if artifact is None:
            print(f"No detector model found for '{lang}'; skipping.")
            continue

        sub = test[test["language"] == lang]
        if sub.empty:
            print(f"No test rows for '{lang}'; skipping.")
            continue

        vw, vc = artifact["word_tfidf"], artifact["char_tfidf"]
        scaler = artifact["scaler"]
        clf = artifact["clf"]

        def predict(df):
            stats = np.vstack([
                stat_features(t, 1.0 if lg == "fil" else 0.0)
                for t, lg in zip(df["text"], df["language"])
            ])
            stats = scaler.transform(stats)
            vec = np.hstack([
                vw.transform(df["text"].tolist()).toarray(),
                vc.transform(df["text"].tolist()).toarray(),
                stats,
            ])
            return clf.predict(vec)

        y_true = sub["label"].to_numpy()
        y_pred = predict(sub)

        out = {"language": lang, "size": len(sub)}
        out["confusion_matrix"] = confusion_matrix(y_true, y_pred).tolist()

        groups = {"all": sub}
        if "condition" in sub.columns:
            for cond in sub["condition"].unique():
                groups[f"condition={cond}"] = sub[sub["condition"] == cond]

        for name, g in groups.items():
            if len(g) == 0:
                continue
            prec, rec, f1, _ = precision_recall_fscore_support(
                g["label"].to_numpy(), predict(g), average=None, labels=[0, 1])
            cm = confusion_matrix(g["label"].to_numpy(), predict(g))
            fp_rate = float(cm[0][1] / cm[0].sum()) if cm[0].sum() else 0.0
            out[name] = {
                "n": int(len(g)),
                "precision_human": round(float(prec[0]), 4),
                "recall_human": round(float(rec[0]), 4),
                "f1_human": round(float(f1[0]), 4),
                "precision_ai": round(float(prec[1]), 4),
                "recall_ai": round(float(rec[1]), 4),
                "f1_ai": round(float(f1[1]), 4),
                "false_positive_rate": round(fp_rate, 4),
            }
            print(f"\n[{lang} / {name}] n={len(g)}")
            print(classification_report(g["label"].to_numpy(), predict(g), digits=3))

        (reports_dir / f"evaluation_{lang}.json").write_text(json.dumps(out, indent=2))
        print(f"\nSaved report -> {reports_dir / f'evaluation_{lang}.json'}")


def eval_embeddings(models_dir: Path, data_dir: Path) -> None:
    """Compares self-trained vs pretrained embeddings on ml/data/pairs.csv.

    Expected columns: text_a, text_b, label (1 = copied/reworded, 0 = unrelated).
    """
    pairs_path = Path(data_dir) / "pairs.csv"
    if not pairs_path.exists():
        print("\nNo ml/data/pairs.csv; skipping embedding comparison.")
        return

    pairs = pd.read_csv(pairs_path)
    print(f"\nEmbedding comparison on {len(pairs)} labeled pairs")

    try:
        from scipy.stats import pearsonr, spearmanr
        from sentence_transformers import SentenceTransformer

        lang = "en"  # pairs.csv is expected to be English for this comparison
        if "language" in pairs.columns:
            lang = pairs["language"].mode().iloc[0]
        pretrained = SentenceTransformer("paraphrase-multilingual-MiniLM-L12-v2")

        def fetch_embedder(lang_code):
            path = models_dir / f"fasttext_{lang_code}.model"
            if not path.exists():
                raise FileNotFoundError(path)
            from gensim.models import FastText
            wv = FastText.load(str(path)).wv
            def embed(texts):
                mats = []
                for s in texts:
                    toks = [t for t in s.lower().split() if t in wv]
                    if not toks:
                        mats.append(np.zeros(wv.vector_size))
                        continue
                    mats.append(np.mean(np.vstack([wv[t] for t in toks]), axis=0))
                m = np.vstack(mats)
                return m / np.maximum(np.linalg.norm(m, axis=1, keepdims=True), 1e-8)
            return embed
    except ImportError as exc:
        print(f"Embedding comparison skipped (missing dependency): {exc}")
        return

    emb_self = fetch_embedder(lang)
    emb_pre = lambda texts: pretrained.encode(texts, normalize_embeddings=True)

    def cosine_sims(embed):
        a = embed(pairs["text_a"].tolist())
        b = embed(pairs["text_b"].tolist())
        return (a * b).sum(axis=1)

    self_scores = cosine_sims(emb_self)
    pre_scores = cosine_sims(emb_pre)
    labels = pairs["label"].to_numpy()

    # Correlation with the label, and mean score by class.
    result = {
        "language": lang,
        "pearson_self": float(pearsonr(self_scores, labels)[0]),
        "pearson_pretrained": float(pearsonr(pre_scores, labels)[0]),
        "spearman_self": float(spearmanr(self_scores, labels)[0]),
        "spearman_pretrained": float(spearmanr(pre_scores, labels)[0]),
        "mean_self_copied": float(self_scores[labels == 1].mean()),
        "mean_self_unrelated": float(self_scores[labels == 0].mean()),
        "mean_pretrained_copied": float(pre_scores[labels == 1].mean()),
        "mean_pretrained_unrelated": float(pre_scores[labels == 0].mean()),
    }
    print(json.dumps(result, indent=2))
    (Path(data_dir) / "embedding_comparison.json").write_text(json.dumps(result, indent=2))


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--data-dir", default="ml/data")
    ap.add_argument("--models-dir", default="ai_service/models")
    ap.add_argument("--reports-dir", default="ml/reports")
    args = ap.parse_args()

    models_dir = Path(args.models_dir)
    data_dir = Path(args.data_dir)

    test_path = data_dir / "test.csv"
    eval_detector(models_dir, Path(args.reports_dir),
                  pd.read_csv(test_path) if test_path.exists() else pd.DataFrame())
    eval_embeddings(models_dir, data_dir)


if __name__ == "__main__":
    main()