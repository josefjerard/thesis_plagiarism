"""Local semantic similarity service for the thesis plagiarism scanner.

Embeds sentences with a multilingual sentence-transformers model and exposes
their cosine similarity over a tiny stdlib HTTP server, so the PHP app can call
it without any extra web framework.

Run:
    python -m venv .venv
    .venv\\Scripts\\activate          # Windows (source .venv/bin/activate on Linux)
    pip install -r requirements.txt
    python app.py                    # listens on http://127.0.0.1:8000

Endpoints:
    GET  /health       -> {"status": "ok", "model": "..."}
    POST /similarity    body {"sentences_a": [...], "sentences_b": [...]}
                        -> {"score": 0.83, "pairs": [{"a":0,"b":3,"score":0.91}]}
"""

import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

import numpy as np
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.environ.get("SEMANTIC_MODEL", "paraphrase-multilingual-MiniLM-L12-v2")
HOST = os.environ.get("SEMANTIC_HOST", "127.0.0.1")
PORT = int(os.environ.get("SEMANTIC_PORT", "8000"))

_model = None


def get_model() -> SentenceTransformer:
    global _model
    if _model is None:
        print(f"Loading model {MODEL_NAME} ...", flush=True)
        _model = SentenceTransformer(MODEL_NAME)
    return _model


def embed(sentences):
    return get_model().encode(
        sentences, normalize_embeddings=True, convert_to_numpy=True
    )


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


class Handler(BaseHTTPRequestHandler):
    def _send(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802 (stdlib naming)
        if self.path == "/health":
            self._send(200, {"status": "ok", "model": MODEL_NAME})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self) -> None:  # noqa: N802 (stdlib naming)
        if self.path != "/similarity":
            self._send(404, {"error": "not found"})
            return

        length = int(self.headers.get("Content-Length", 0))
        try:
            data = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError as exc:
            self._send(400, {"error": str(exc)})
            return

        sentences_a = data.get("sentences_a") or []
        sentences_b = data.get("sentences_b") or []
        if not sentences_a or not sentences_b:
            self._send(200, {"score": 0.0, "pairs": []})
            return

        emb_a = embed(sentences_a)
        emb_b = embed(sentences_b)
        sim = emb_a @ emb_b.T

        self._send(200, {
            "score": round(best_match_average(sim), 4),
            "pairs": greedy_matches(sim),
        })

    def log_message(self, fmt, *args) -> None:
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


def main() -> None:
    server = HTTPServer((HOST, PORT), Handler)
    print(f"Semantic similarity service on http://{HOST}:{PORT} (model: {MODEL_NAME})")
    server.serve_forever()


if __name__ == "__main__":
    main()
