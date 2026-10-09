Web-Based Essay Plagiarism Scanner Using Hybrid Text and Semantic Similarity Analysis

A web-based system that compares suspected handwritten essays and reports how similar they are. The user uploads a whole batch of essay photos at once; the system transcribes them with OCR, scores every same-language pair with four similarity methods, combines the scores into one hybrid score, highlights the matching sentences, and generates a report for the reviewer. Built as a Computer Science thesis project.

Comparisons never cross batches: only the essays uploaded together are compared with each other.

The system supports the reviewer. It does not decide on plagiarism: the final judgment always belongs to the user.

Status
Component	Status
Image upload and file validation	Implemented
OCR (Google Cloud Vision) with per-word confidence	Implemented
Readability check (rejects unreadable images)	Implemented
Confidence-based word filtering	Implemented
Character n-gram similarity (Dice / Jaccard)	Implemented
Side-by-side comparison and review page	Implemented
Language detection (Filipino / English)	Implemented
TF-IDF + cosine similarity (smoothed IDF)	Implemented
Levenshtein similarity (sentence level)	Implemented
Semantic similarity (sentence embeddings)	Implemented
Hybrid score	Implemented
Sentence-level highlighting	Implemented
Similarity report	Implemented
How it works
The user selects several suspected essay images at once and gives each one a name.
The system validates each file and extracts text with OCR, with a confidence score for every word.
Images that fail the readability check are rejected; at least two readable essays are required to continue.
Words below the confidence cutoff are removed from the comparison text.
The text is preprocessed (normalization, tokenization, stopword removal).
The system detects each essay's dominant language (Filipino or English). Only essays in the same language are compared.
Every same-language pair in the batch is scored with four methods, and the scores are combined into a hybrid score.
Each pair is classified by similarity level, its matching sentences are highlighted, and the pairs are ranked.
The user reviews the pairs side by side and records the final decision.
The system generates the similarity report.

If fewer than two readable essays are available, no comparison is performed and the user is notified.

Algorithms
#	Method	What it measures
1	Character n-gram similarity (5-character shingles, Dice coefficient)	Copied or closely matching wording; tolerant of OCR typos
2	TF-IDF + cosine similarity (smoothed IDF)	Overlap in important vocabulary
3	Levenshtein similarity, applied per sentence	How close matched sentences are, character by character
4	Semantic similarity (multilingual sentence embeddings, cosine)	Similar meaning with different wording
5	Hybrid score	The four scores combined

Formulas:

Dice: 2·|A ∩ B| / (|A| + |B|)
TF-IDF: tfidf(t, d) = tf(t, d) × idf(t) (smoothed), compared with cos(A, B) = (A · B) / (‖A‖ ‖B‖)
Levenshtein similarity: 1 − edits / max(|a|, |b|)
Hybrid: Final = w1·NGram + w2·TFIDF + w3·Lev + w4·Semantic, where the weights sum to 1

The weights and similarity-level cutoffs are not fixed yet. They will be tuned on a labeled test set (copied, lightly edited, reworded, and unrelated essay pairs).

Not every method uses the same preprocessing. Stopword removal is meant for TF-IDF. N-gram and Levenshtein work on normalized text with stopwords kept, so copied sentences still line up.

Languages

Essays are written in Filipino or English. A single essay may contain a few words of the other language. Each essay is labeled by its dominant language, and only same-language essays are compared. Cross-language (translated) plagiarism is out of scope.

The semantic method uses a multilingual embedding model (paraphrase-multilingual-MiniLM-L12-v2), served by a small local Python service that the PHP application calls over HTTP.

Tech stack
Frontend: HTML, CSS, JavaScript
Backend: PHP
Database: MySQL
Local environment: XAMPP
OCR: Google Cloud Vision API (Document Text Detection)
Semantic similarity: Python service with sentence-transformers
Project structure
thesis_plagiarism/
├── index.php            review history (default page, sessions + filters)
├── upload_essays.php    upload page (select a batch of images)
├── upload.php           handles the batch, runs OCR and comparison
├── result.php           batch results (all pairs)
├── about.php            how the scanner scores
├── compare.php          side-by-side comparison
├── report.php           generated similarity report
├── submission.php       submission details
├── install.php          one-time setup (delete after use)
├── database.sql         database schema
├── config.example.php   configuration template
├── includes/
│   ├── db.php
│   ├── helpers.php
│   ├── ocr.php
│   ├── similarity.php   engine orchestrator (scoring + storage)
│   └── similarity/
│       ├── preprocess.php    normalization, tokenization, sentences
│       ├── language.php      Filipino / English detection
│       ├── ngram.php         character n-gram (Dice / Jaccard)
│       ├── tfidf.php         TF-IDF + cosine (smoothed IDF)
│       ├── levenshtein.php   sentence-level Levenshtein
│       ├── semantic.php      client for the Python service
│       └── hybrid.php        weighted hybrid score
├── semantic_service/
│   └── app.py           local sentence-embeddings HTTP service
├── assets/
└── uploads/             uploaded images (not committed)
Setup
Install XAMPP and start Apache and MySQL.
Place the project folder in htdocs.
Create an empty database named thesis_plagiarism in phpMyAdmin.
Copy config.example.php to config.php and fill in your values, including the Google Cloud Vision API key.
Open http://localhost/thesis_plagiarism/install.php once to create the tables, then delete install.php.
Open http://localhost/thesis_plagiarism/, select all the essay images in the batch, name each one, and upload them together.

Optional, for the semantic method: run the Python service. In semantic_service/ create a virtual environment, pip install -r requirements.txt, then run app.py (defaults to http://127.0.0.1:8000). If it is not running, semantic similarity is skipped and the hybrid score renormalizes over the other three methods.

If you already had the database from an earlier version, re-run install.php once; it adds the new language and per-method score columns without touching existing rows.

config.php holds your API key and is excluded from version control. Never commit it or share it.

Configuration

Current settings in config.php:

Setting	Purpose
GOOGLE_VISION_API_KEY	API key for OCR
SHINGLE_SIZE	Character n-gram size (default 5)
SIM_METHOD	dice or jaccard
FLAG_THRESHOLD	Similarity at or above which a pair is flagged
CONFIDENCE_CUTOFF	Words below this OCR confidence are excluded
READABILITY_MIN_WORDS / READABILITY_MIN_AVG_CONF	Readability gate
MAX_UPLOAD_BYTES	Upload size limit
W_NGRAM / W_TFIDF / W_LEV / W_SEMANTIC	Hybrid weights (sum to 1)
LEVEL_HIGH / LEVEL_MODERATE	Similarity-level cutoffs for the human label
HIGHLIGHT_THRESHOLD	Sentence-pair score at or above which sentences are highlighted
SEMANTIC_SERVICE_URL / SEMANTIC_TIMEOUT	Local Python semantic service address and timeout

Limitations
OCR is not perfect, especially on inconsistent, faint, or unconventional handwriting. Misread words can lower similarity scores.
Filipino handwriting and Filipino semantic scoring are likely less accurate than English, since the OCR and embedding models are stronger in English.
Very heavily mixed Filipino-English essays may be labeled with the wrong dominant language.
TF-IDF is weak when only a few essays are compared, since word weights depend on the other documents.
Similarity scores prioritize pairs for review. A high score is evidence, not proof of plagiarism.
Evaluation plan
Build a small test set of essay pairs: copied, lightly edited, reworded, and unrelated, in both Filipino and English.
Run each pair on typed text, OCR of printed text, and OCR of handwriting.
Report how each method and the hybrid score perform in each condition, and use the results to set the weights and similarity levels.