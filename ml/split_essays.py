#!/usr/bin/env python3
"""Split one pasted block of essays into separate .txt files for training.

You paste an AI tool's reply (or several human essays) into a text file where each
essay starts with a marker line like   ### p01   (the prompt id from prompts.csv).

Example input file (batch.txt):

    ### p01
    Education plays an important role in ...

    ### p02
    Smartphones have changed how students ...

Run from the project root:

    python ml/split_essays.py batch.txt --label ai --who chatgpt
    python ml/split_essays.py classmate1.txt --label human --who s01

It writes files like  ml/data/collect/ai/p01__chatgpt__01.txt  (numbering continues
if the file already exists), then you run  python ml/collect_to_csv.py  as usual.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

MARKER = re.compile(r"^\s*###\s*(p\d{2,3})\s*$", re.IGNORECASE)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("input", help="text file containing the pasted essays")
    ap.add_argument("--label", choices=["ai", "human"], required=True)
    ap.add_argument("--who", required=True, help="AI tool name (chatgpt) or anonymous student code (s01)")
    ap.add_argument("--out-root", default="ml/data/collect")
    args = ap.parse_args()

    src = Path(args.input)
    if not src.is_file():
        print(f"File not found: {src}", file=sys.stderr)
        return 1

    who = re.sub(r"[^a-z0-9\-]", "", args.who.lower())
    if not who:
        print("--who must contain letters or digits", file=sys.stderr)
        return 1

    text = src.read_text(encoding="utf-8-sig").replace("\r\n", "\n")
    essays: list[tuple[str, list[str]]] = []
    for line in text.split("\n"):
        m = MARKER.match(line)
        if m:
            essays.append((m.group(1).lower(), []))
        elif essays:
            essays[-1][1].append(line)

    if not essays:
        print("No '### p01' style markers found. Put a marker line before each essay.", file=sys.stderr)
        return 1

    out_dir = Path(args.out_root) / args.label
    out_dir.mkdir(parents=True, exist_ok=True)
    written = 0
    for pid, lines in essays:
        body = "\n".join(lines).strip()
        words = len(body.split())
        if words < 100:
            print(f"  skipped {pid}: only {words} words (minimum 100)")
            continue
        n = 1
        while (out_dir / f"{pid}__{who}__{n:02d}.txt").exists():
            n += 1
        path = out_dir / f"{pid}__{who}__{n:02d}.txt"
        path.write_text(body + "\n", encoding="utf-8")
        note = "  (short)" if words < 150 else ""
        print(f"  wrote {path}  [{words} words]{note}")
        written += 1

    print(f"\n{written} essay file(s) written to {out_dir}")
    return 0 if written else 1


if __name__ == "__main__":
    raise SystemExit(main())