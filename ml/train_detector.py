#!/usr/bin/env python3
"""Trains the AI-text detector, one model per language.

Features: word and character n-gram TF-IDF plus the hand-designed statistical
features from ml/features.py (matching what ai_service/app.py serves online).
Logistic Regression, linear SVM and Random Forest are compared on the training
set; the best is refitted on train+val and evaluated on the held-out test set.

Usage:
    python train_detector.py [--data-dir ml/data] [--models-dir ai_service/models]
                             [--reports-dir ml/reports]
"""

import argparse
import json
import os
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from scipy.sparse import csr_matrix, hstack
from sklearn.ensemble import RandomForestClassifier
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (classification_report, confusion_matrix,
                             precision_recall_fscore_support)
from sklearn.model_selection import cross_val_score
from sklearn.preprocessing import StandardScaler
from sklearn.svm import SVC

# Make `from ml.features import ...` work regardless of how this script is
# invoked (python ml/train_detector.py, python -m ml.train_detector, ...).
import sys
_REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _REPO_ROOT not in sys.path:
    sys.path.insert(0, _REPO_ROOT)

from ml.features import stat_features

MIN_PER_CLASS = 40


def stats_matrix(df: pd.DataFrame) -> np.ndarray:
    return np.vstack([
        stat_features(t, 1.0 if lang == "fil" else 0.0)
        for t, lang in zip(df["text"], df["language"])
    ])


def build_features(df: pd.DataFrame, word_tfidf, char_tfidf, scaler,
                   fit: bool) -> "tuple":
    stats = stats_matrix(df)
    if fit:
        stats = scaler.fit_transform(stats)
    else:
        stats = scaler.transform(stats)

    texts = df["text"].tolist()
    if fit:
        w = word_tfidf.fit_transform(texts)
        c = char_tfidf.fit_transform(texts)
    else:
        w = word_tfidf.transform(texts)
        c = char_tfidf.transform(texts)

    return hstack([w, c, csr_matrix(stats)]).tocsr()


def train_language(lang: str, train: pd.DataFrame, val: pd.DataFrame,
                   test: pd.DataFrame, models_dir: Path, reports_dir: Path) -> None:
    print(f"\n=== language '{lang}' ===")

    per_class = {label: int((train['label'] == label).sum()) for label in (0, 1)}
    if min(per_class.values()) < MIN_PER_CLASS:
        print(f"Too little data per class {per_class} (need >= {MIN_PER_CLASS}); skipping.")
        return

    word_tfidf = TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True, max_features=30000)
    char_tfidf = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), max_features=30000)
    scaler = StandardScaler()

    print("Building feature matrix (fit on train) ...")
    X_train = build_features(train, word_tfidf, char_tfidf, scaler, fit=True)
    y_train = train["label"].to_numpy()

    models = {
        "logistic": LogisticRegression(max_iter=2000, class_weight="balanced", random_state=42),
        "svm": SVC(kernel="linear", probability=True, class_weight="balanced", random_state=42),
        "random_forest": RandomForestClassifier(n_estimators=200, n_jobs=-1, class_weight="balanced", random_state=42),
    }

    scores = {}
    for name, clf in models.items():
        cv = cross_val_score(clf, X_train, y_train, cv=5, scoring="f1_macro")
        scores[name] = float(cv.mean())
        print(f"  {name:14s} cross-val F1 (macro): {cv.mean():.3f} +- {cv.std():.3f}")

    best_name = max(scores, key=scores.get)
    best_clf = models[best_name]
    print(f"Best: {best_name}")

    # Refit on train + val for the final model.
    combined = pd.concat([train, val], ignore_index=True)
    print("Refitting on train+val ...")
    X_combined = build_features(combined, word_tfidf, char_tfidf, scaler, fit=False)
    y_combined = combined["label"].to_numpy()
    best_clf.fit(X_combined, y_combined)

    X_test = build_features(test, word_tfidf, char_tfidf, scaler, fit=False)
    y_test = test["label"].to_numpy()
    y_pred = best_clf.predict(X_test)

    print(classification_report(y_test, y_pred, digits=3))
    cm = confusion_matrix(y_test, y_pred)
    prec, rec, f1, _ = precision_recall_fscore_support(y_test, y_pred, average=None, labels=[0, 1])
    fp_rate = float(cm[0, 1] / (cm[0, 0] + cm[0, 1])) if cm[0].sum() else 0.0

    models_dir.mkdir(parents=True, exist_ok=True)
    reports_dir.mkdir(parents=True, exist_ok=True)
    artifact = {"word_tfidf": word_tfidf, "char_tfidf": char_tfidf, "scaler": scaler, "clf": best_clf}
    joblib.dump(artifact, models_dir / f"ai_detector_{lang}.joblib", compress=3)

    report = {
        "language": lang,
        "best_model": best_name,
        "cross_val": scores,
        "test": {
            "size": len(test),
            "precision_human": float(prec[0]),
            "recall_human": float(rec[0]),
            "f1_human": float(f1[0]),
            "precision_ai": float(prec[1]),
            "recall_ai": float(rec[1]),
            "f1_ai": float(f1[1]),
            "false_positive_rate": round(fp_rate, 4),
            "confusion_matrix": cm.tolist(),
        },
    }
    (reports_dir / f"detector_{lang}.json").write_text(json.dumps(report, indent=2))
    print(f"Saved {models_dir / f'ai_detector_{lang}.joblib'} and report.")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--data-dir", default="ml/data")
    ap.add_argument("--models-dir", default="ai_service/models")
    ap.add_argument("--reports-dir", default="ml/reports")
    args = ap.parse_args()

    data_dir = Path(args.data_dir)
    for split in ("train", "val", "test"):
        if not (data_dir / f"{split}.csv").exists():
            raise SystemExit(f"Missing {data_dir / split}.csv — run build_dataset.py first.")

    train = pd.read_csv(data_dir / "train.csv")
    val = pd.read_csv(data_dir / "val.csv")
    test = pd.read_csv(data_dir / "test.csv")

    for lang in ("en", "fil"):
        sub = lambda df, lg: df[df["language"] == lg].reset_index(drop=True)
        train_lang = sub(train, lang)
        val_lang = sub(val, lang)
        test_lang = sub(test, lang)
        train_language(lang, train_lang, val_lang, test_lang,
                       Path(args.models_dir), Path(args.reports_dir))


if __name__ == "__main__":
    main()