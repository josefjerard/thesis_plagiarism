"""Local AI service for the thesis plagiarism scanner.

Serves two things over a tiny stdlib HTTP server:
  * POST /similarity  -> sentence embeddings (self-trained where available,
                         pretrained sentence-transformers as a fallback) and
                         cosine-based scores + one-to-one sentence matches.
  * POST /ai-detect   -> probability that a text is AI-generated, using the
                         self-trained detector (one model per language).

Run:
    python -m venv .venv
    .venv\\Scripts\\activate          # Windows (source .venv/bin/activate on Linux)
    pip install -r requirements.txt
    python app.py                    # listens on http://127.0.0.1:8000

Endpoints:
    GET  /health           -> {"status": "ok", "embeddings": "...", "detector": {...}}
    POST /similarity        body {"sentences_a": [...], "sentences_b": [...]}
                            -> {"score": 0.83, "pairs": [{"a":0,"b":3,"score":0.91}]}
    POST /ai-detect         body {"text": "...", "language": "en"}
                            -> {"probability": 0.87}
"""

import json
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import numpy as np

# Allow `from ml.features import ...` from the repo root.
_REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _REPO_ROOT not in sys.path:
    sys.path.insert(0, _REPO_ROOT)

PRETRAINED_MODEL = os.environ.get("SEMANTIC_MODEL", "paraphrase-multilingual-MiniLM-L12-v2")
HOST = os.environ.get("AI_HOST", "127.0.0.1")
PORT = int(os.environ.get("AI_PORT", "8000"))
MODELS_DIR = os.environ.get("AI_MODELS_DIR", os.path.join(_REPO_ROOT, "ai_service", "models"))

_embeddings_backend = {}   # language -> embed callable
_detectors = {}            # language -> predict_proba callable
_pretrained = None


def _embedding_path(language: str) -> str:
    return os.path.join(MODELS_DIR, f"fasttext_{language}.model")


def _detector_path(language: str) -> str:
    return os.path.join(MODELS_DIR, f"ai_detector_{language}.joblib")


def _get_self_trained(language: str):
    """Loads a gensim FastText model if it exists, else None."""
    path = _embedding_path(language)
    if not os.path.exists(path):
        return None
    try:
        from gensim.models import FastText  # type: ignore
        return FastText.load(path)
    except Exception as exc:  # noqa: BLE001 - report, then fall back
        print(f"Could not load {path}: {exc}", flush=True)
        return None


def _pretrained_embedder():
    global _pretrained
    if _pretrained is None:
        from sentence_transformers import SentenceTransformer  # type: ignore
        print(f"Loading pretrained model {PRETRAINED_MODEL} ...", flush=True)
        _pretrained = SentenceTransformer(PRETRAINED_MODEL)
    return _pretrained


def get_embedder(language: str):
    """Returns an embed callable for the language, preferring self-trained."""
    cache = _embeddings_backend.get(language)
    if cache is not None:
        return cache

    wv = None
    model = _get_self_trained(language)
    if model is not None:
        wv = model.wv
    elif language == "fil":
        model = _get_self_trained("en")
        if model is not None:
            wv = model.wv
            print("Filipino embeddings missing; reusing English model.", flush=True)

    if wv is not None:
        def embed(sentences, _wv=wv):
            vecs = []
            for s in sentences:
                toks = [t for t in s.lower().split() if t in _wv]
                if not toks:
                    vecs.append(np.zeros(_wv.vector_size, dtype="float32"))
                    continue
                words = np.vstack([_wv[t] for t in toks])
                vecs.append(words.mean(axis=0))
            mat = np.vstack(vecs).astype("float32")
            norms = np.linalg.norm(mat, axis=1, keepdims=True)
            return mat / np.maximum(norms, 1e-8)

        _embeddings_backend[language] = embed
        return embed

    # Fallback: pretrained multilingual model (same for every language).
    st = _pretrained_embedder()

    def embed_pretrained(sentences):
        return st.encode(sentences, normalize_embeddings=True, convert_to_numpy=True)

    _embeddings_backend[language] = embed_pretrained
    return embed_pretrained


def embeddings_backend_name(language: str) -> str:
    return "self-trained" if os.path.exists(_embedding_path(language)) \
        else ("pretrained (fallback)" if _pretrained is not None else "pretrained")


def best_match_average(sim: np.ndarray) -> float:
    """Symmetric mean of each sentence's best cosine match on the other side."""
    best_a = float(sim.max(axis=1).mean())
    best_b = float(sim.max(axis=0).mean())
    return (best_a + best_b) / 2.0


def greedy_matches(sim: np.ndarray):
    """One-to-one matches, strongest first, for sentence highlighting."""
    used_a, used_b, pairs = set(), set(), []
    flat = sorted(
        ((float(sim[i, j]), i, j)
         for i in range(sim.shape[0])
         for j in range(sim.shape[1])),
        reverse=True,
    )
    for score, i, j in flat:
        if i in used_a or j in used_b:
            continue
        used_a.add(i)
        used_b.add(j)
        pairs.append({"a": i, "b": j, "score": round(score, 4)})
    return pairs


def get_detector(language: str):
    """Loads and caches the per-language detector; returns None if absent."""
    cached = _detectors.get(language)
    if cached is not None:
        return cached

    import joblib  # type: ignore

    path = _detector_path(language)
    if not os.path.exists(path):
        path = _detector_path("en")  # shared fallback model
    if not os.path.exists(path):
        print(f"No detector model trained for '{language}' ({path}).", flush=True)
        return None

    artifact = joblib.load(path)
    vw = artifact["word_tfidf"]
    vc = artifact["char_tfidf"]
    scaler = artifact["scaler"]
    clf = artifact["clf"]

    def predict_proba(text):
        from ml.features import stat_features, sentences  # local import at runtime
        is_fil = 1.0 if language == "fil" else 0.0
        stats = stat_features(text, is_fil).reshape(1, -1)
        stats = scaler.transform(stats)
        txt = [text]
        vec = np.hstack([vw.transform(txt).toarray(),
                         vc.transform(txt).toarray(),
                         stats])
        proba = clf.predict_proba(vec)
        return proba

    _detectors[language] = predict_proba
    return predict_proba


class Handler(BaseHTTPRequestHandler):
    def _send(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _body(self) -> dict:
        length = int(self.headers.get("Content-Length", 0))
        try:
            return json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return {}

    def do_GET(self) -> None:  # noqa: N802 (stdlib naming)
        if self.path == "/health":
            self._send(200, {
                "status": "ok",
                "embeddings": {
                    "en": embeddings_backend_name("en"),
                    "fil": embeddings_backend_name("fil"),
                },
                "detector": {
                    "en": os.path.exists(_detector_path("en")),
                    "fil": os.path.exists(_detector_path("fil")),
                },
            })
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802 (stdlib naming)
        if self.path == "/similarity":
            self._similarity(self._body())
        elif self.path == "/ai-detect":
            self._ai_detect(self._body())
        else:
            self._send(404, {"error": "not found"})

    def _similarity(self, data: dict) -> None:
        sentences_a = data.get("sentences_a") or []
        sentences_b = data.get("sentences_b") or []
        language = str(data.get("language", "en"))
        if not sentences_a or not sentences_b:
            self._send(200, {"score": 0.0, "pairs": []})
            return

        embed = get_embedder(language)
        sim = embed(sentences_a) @ embed(sentences_b).T
        self._send(200, {
            "score": round(best_match_average(sim), 4),
            "pairs": greedy_matches(sim),
        })

    def _ai_detect(self, data: dict) -> None:
        text = str(data.get("text", ""))
        language = str(data.get("language", "en"))
        if not text.strip():
            self._send(200, {"probability": 0.0})
            return

        predict = get_detector(language)
        if predict is None:
            self._send(503, {"error": "no detector model trained for this language"})
            return

        proba = predict(text)
        self._send(200, {"probability": round(float(proba[0, 1]), 4)})

    def log_message(self, fmt, *args) -> None:  # noqa: A003
        sys.stderr.write("%s - %s\n" % (self.client_address[0], fmt % args))


def main() -> None:
    os.makedirs(MODELS_DIR, exist_ok=True)
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"AI service on http://{HOST}:{PORT} (models: {MODELS_DIR})", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()