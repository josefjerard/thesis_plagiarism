# Web-Based Essay Plagiarism Scanner Using Hybrid Text and Semantic Similarity Analysis

A web-based system that checks essays for three kinds of copying: **copying from other students' essays**, **copying from reference sources**, and **AI-generated writing**. The user uploads a batch of essay images or `.txt` files, names the activity, and gives each essay a name. Images are transcribed with OCR; text files enter the same pipeline directly. The system scores every same-language pair with four similarity methods, combines the scores into one hybrid score, checks each essay against the reference-source corpus, estimates how likely each essay is to be AI-written, highlights the matching sentences, and generates a report. Built as a Computer Science thesis project.

The AI components (the semantic similarity model and the AI-text detector) will be **trained by us on our own dataset**. They are not integrated from an external AI service.

Pairwise comparisons never cross activities: only the essays uploaded together are compared with each other.

The system supports one user. It does not decide on plagiarism: the final judgment always belongs to the user.

## Status

| Component | Status |
|---|---|
| Image upload and file validation | Implemented |
| Text-file input mode | Implemented for testing the full flow without OCR |
| OCR (Google Cloud Vision) with per-word confidence | Implemented |
| Readability check (rejects unreadable images) | Implemented |
| Confidence-based word filtering | Implemented |
| Character n-gram similarity (Dice / Jaccard) | Implemented |
| Side-by-side comparison and review page | Implemented |
| Language detection (Filipino / English) | Implemented |
| TF-IDF + cosine similarity (smoothed IDF) | Implemented |
| Levenshtein similarity (sentence level) | Implemented |
| Semantic similarity (pretrained sentence embeddings) | Implemented, **to be replaced** by the self-trained embedding model |
| Hybrid score | Implemented |
| Sentence-level highlighting | Implemented |
| Similarity report | Implemented |
| Internet source checking (local reference corpus) | Implemented |
| AI-likelihood client and service (`ai_service/`) | Implemented |
| ML training scripts (`ml/`) | Implemented |
| Training dataset (human and AI essays, English and Filipino) | Planned |
| Self-trained AI-text detector (model) | Planned — pipeline ready, needs training data |
| Self-trained semantic embedding model (per language) | Planned |
| AI-likelihood and web-source sections in the report | Implemented (AI-likelihood populates once the detector is trained) |
| Internet source search mode (candidate pages from a search API) | Planned |

## How it works

### Input modes (text and image)

Use the upload-page selector to choose a mode for each batch. Text mode accepts UTF-8 `.txt` files directly and does not call OCR; image mode keeps the existing photo validation and Google Vision OCR flow. Both modes then use the same downstream comparison, highlighting, AI-likelihood, web-source, and report pipeline.

1. The user enters an activity name, chooses text-file or image mode, selects several essays at once, and gives each one a name. The number of essays is counted automatically. `INPUT_MODE` sets the default; the upload-page selector can override it for a batch.
2. In image mode, the system validates each image and extracts text with OCR, with a confidence score for every word. In text mode, it validates UTF-8 `.txt` files and uses the submitted text directly (at least 20 words by default), without requiring a Vision API key.
3. Images that fail the readability check and text files that are empty or too short are rejected. At least one valid essay is required to continue; two or more are needed for the pairwise check.
4. In image mode, words below the confidence cutoff are removed from the comparison text and the raw OCR text is kept for review. Text mode uses the submitted text as both the raw and comparison text.
5. The text is preprocessed (normalization, tokenization, stopword removal).
6. The system detects each essay's dominant language (Filipino or English). Only essays in the same language are compared with each other.
7. **Pairwise check:** every same-language pair in the activity is scored with four methods, and the scores are combined into a hybrid score. Each pair is classified by similarity level, its matching sentences are highlighted, and the pairs are ranked.
8. **Internet source check:** each essay is split into chunks, the most substantial chunks are compared against a local reference corpus (`reference_sources/`) with n-gram, TF-IDF, and Levenshtein similarity, and the matching sources and overlapping passages are listed. Matching pages found through a search API is the planned next step.
9. **AI-likelihood check:** the trained detector gives each essay a probability of being AI-generated. This is an indicator for review, not a verdict.
10. The user reviews the pairs, web matches, and AI-likelihood scores, and records the final decision.
11. The system generates the report.

If fewer than two valid essays are available, no pairwise comparison is performed and the user is notified. The internet source check and the AI-likelihood check still run on each valid essay, since they do not need a second essay.

## Algorithms

| # | Method | What it measures |
|---|---|---|
| 1 | Character n-gram similarity (5-character shingles, Dice coefficient) | Copied or closely matching wording; tolerant of OCR typos |
| 2 | TF-IDF + cosine similarity (smoothed IDF) | Overlap in important vocabulary |
| 3 | Levenshtein similarity, applied per sentence | How close matched sentences are, character by character |
| 4 | Semantic similarity (cosine). Current: pretrained sentence embeddings. Plan: self-trained word embeddings | Similar meaning with different wording |
| 5 | Hybrid score | The four scores combined |
| 6 | Internet source matching (local reference corpus) | Overlap between an essay and a reference source, using methods 1 to 3 |
| 7 | AI-text detector (self-trained classifier) | Probability that an essay was written by an AI tool |

Formulas:

- Dice: `2·|A ∩ B| / (|A| + |B|)`
- TF-IDF: `tfidf(t, d) = tf(t, d) × idf(t)` (smoothed), compared with `cos(A, B) = (A · B) / (‖A‖ ‖B‖)`
- Levenshtein similarity: `1 − edits / max(|a|, |b|)`
- Hybrid: `Final = w1·NGram + w2·TFIDF + w3·Lev + w4·Semantic`, where the weights sum to 1

The AI-likelihood score is reported **separately** and is not part of the hybrid score, since it measures a different thing (how the text was produced, not how similar it is to another text).

The weights and similarity-level cutoffs are not fixed yet. They will be tuned on a labeled test set (copied, lightly edited, reworded, and unrelated essay pairs).

Not every method uses the same preprocessing. Stopword removal is meant for TF-IDF. N-gram and Levenshtein work on normalized text with stopwords kept, so copied sentences still line up.

## Custom AI models

Both models are trained with Python on our own dataset. A normal laptop is enough; no GPU is needed.

### AI-text detector

- **Task:** binary classification, human-written (0) vs AI-generated (1). Output is a probability.
- **Features:** sentence-length average and variation, vocabulary richness, punctuation and function-word usage, repetition and transition-phrase patterns, plus word and character n-gram TF-IDF.
- **Models compared:** Logistic Regression, SVM, and Random Forest. The best model on the validation set is kept and saved with `joblib`.
- **Evaluation:** precision, recall, F1, confusion matrix, and false-positive rate, reported per language and per input condition (typed text, OCR of printed text, OCR of handwriting).

### Semantic embedding model

- **Task:** produce sentence vectors so that sentences with similar meaning score high under cosine similarity.
- **Method:** Word2Vec or FastText trained on an English and Filipino text corpus, one model per language, with sentence vectors made by averaging word vectors. FastText is preferred because it handles Filipino word forms and OCR typos better.
- **Replaces:** the pretrained `paraphrase-multilingual-MiniLM-L12-v2` model used in the first version.

### Dataset

A single CSV with the columns `text, label, language, source, prompt_id`.

- **Human essays (label 0):** past essays from classmates (with written consent, anonymized), public essay datasets, and Filipino news and opinion text. A set of handwritten essays is photographed and run through the OCR so the model sees realistic OCR noise.
- **AI essays (label 1):** generated by us with several AI tools from a list of prompts, in both languages, with varied style instructions (for example short, formal, or "written like a college student").
- **Same prompts for both classes:** classmates answer the same prompts given to the AI tools, so the model learns authorship and not topic.
- **Cleaning:** names and personal details removed, duplicates removed, texts under about 100 words removed, and source information logged for each batch.
- **Splitting:** about 70% train, 15% validation, 15% test, split **by `prompt_id`** so the same topic never appears in both training and test data.
- **Target size:** at least 300 to 500 samples per class per language to start. Long essays may be cut into 150 to 300 word chunks.

## Internet source checking

This module runs at scan time and is written by us. The current mode compares each essay against a **local reference corpus** of plain-text sources, so no external search service is needed.

1. Split the essay into chunks of a few sentences and select the most substantial ones.
2. Compare each chunk against every reference document in `reference_sources/` (one `.txt` file per source).
3. Score each pair with n-gram, TF-IDF, and Levenshtein similarity, and combine them.
4. Report the matched source, the similarity score, and the highlighted overlapping passages.

It can only find what is in the local corpus, so collection is deliberate: add the pages, articles, or documents you want to check against as `.txt` files. A future search mode that finds candidate pages online can extend this without changing the rest of the pipeline.

## Languages

Essays are written in Filipino or English. A single essay may contain a few words of the other language. Each essay is labeled by its dominant language, and only same-language essays are compared. Cross-language (translated) plagiarism is out of scope.

The embedding models and the AI-text detector are trained separately for each language, or with the language as a feature, depending on how much Filipino data is available.

## Tech stack

- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL
- **Local environment:** XAMPP
- **Input modes (text and image):** image mode uses Google Cloud Vision; text mode sends `.txt` contents directly into the same analysis pipeline for testing.
- **OCR:** Google Cloud Vision API (Document Text Detection)
- **AI service:** Python with the standard library `http.server`, `scikit-learn`, `gensim`, `joblib`, and `numpy` (`ai_service/`). It serves sentence embeddings and AI-text detection. Self-trained models are used where available, with the pretrained `paraphrase-multilingual-MiniLM-L12-v2` model as a fallback for embeddings until they are trained.
- **Model training:** Python with `scikit-learn`, `pandas`, `gensim`, and `joblib` (`ml/`).
- **Legacy:** `semantic_service/` is the first-version pretrained-only service; `ai_service/` supersedes it.

## Project structure

Input modes (text and image) are implemented by the upload page and handler; both share the existing analysis pipeline.

```
thesis_plagiarism/
├── index.php            Dashboard (default page; activities newest first + filters)
├── upload_essays.php    upload page (activity name + text or image input mode)
├── upload.php           handles uploads, OCR or direct text input, comparison, web and AI checks
├── result.php           activity results (all pairs, web matches, AI-likelihood)
├── about.php            how the scanner scores
├── compare.php          side-by-side comparison
├── report.php           generated similarity report
├── submission.php       submission details (text, AI score, web matches)
├── install.php          one-time setup (delete after use)
├── database.sql         database schema
├── migrate_add_source_type.sql  adds the input-mode discriminator to an existing database
├── .gitignore           excludes config.php and generated data
├── config.example.php   configuration template
├── includes/
│   ├── db.php
│   ├── foot.php         shared page footer
│   ├── head.php         shared page header
│   ├── helpers.php
│   ├── ocr.php
│   ├── similarity.php   engine orchestrator (scoring + storage)
│   ├── websource.php    internet source checking (local reference corpus)
│   └── similarity/
│       ├── preprocess.php    normalization, tokenization, sentences
│       ├── language.php      Filipino / English detection
│       ├── ngram.php         character n-gram (Dice / Jaccard)
│       ├── tfidf.php         TF-IDF + cosine (smoothed IDF)
│       ├── levenshtein.php   sentence-level Levenshtein
│       ├── semantic.php      client for the Python service
│       ├── aidetect.php      client for the AI-text detector
│       └── hybrid.php        weighted hybrid score
├── ai_service/                                   (models are trained, not committed)
│   ├── app.py           local HTTP service: embeddings + AI-text detection
│   ├── requirements.txt
│   └── models/          trained models (ai_detector_<lang>.joblib, fasttext_<lang>.model)
├── ml/                                           (model training, run separately)
│   ├── features.py      shared detector features (training + serving)
│   ├── data/            dataset CSV files (train/val/test, not committed)
│   ├── build_dataset.py cleaning and splitting by prompt_id
│   ├── train_detector.py
│   ├── train_embeddings.py
│   ├── evaluate.py      metrics and comparison tables
│   ├── reports/         evaluation output (not committed)
│   └── requirements.txt
├── semantic_service/    legacy first-version pretrained service
├── reference_sources/   plain-text sources for web checking (not committed)
├── sample_essays/       text-mode flow test essays
├── assets/
└── uploads/             uploaded images (not committed)
```

## Setup

1. Install XAMPP and start Apache and MySQL.
2. Place the project folder in `htdocs`.
3. Create an empty database named `thesis_plagiarism` in phpMyAdmin.
4. Copy `config.example.php` to `config.php` and fill in your values. A Google Cloud Vision API key is required only for image mode; `INPUT_MODE` defaults to `text` and can be overridden on the upload page.
5. Open `http://localhost/thesis_plagiarism/install.php` once to create the tables, then delete `install.php`.
6. Open `http://localhost/thesis_plagiarism/`, enter an activity name, select text files or essay images in one batch, name each one, and upload them together. The new activity then appears at the top of the Dashboard.
7. Optional: put plain-text sources (`.txt`) in `reference_sources/` to check essays against the local internet-source corpus.

**For the custom AI models:**

1. Create a virtual environment in `ml/` and install the requirements (`scikit-learn`, `pandas`, `numpy`, `gensim`, `joblib`, `scipy`).
2. Put the raw dataset CSV(s) in `ml/data/raw/` (columns `text,label,language,source,prompt_id`).
3. Run `build_dataset.py` to clean and split the data, then `train_detector.py` and `train_embeddings.py`. The trained models are saved to `ai_service/models/`.
4. Run `evaluate.py` to produce the metrics and comparison tables in `ml/reports/`.
5. In `ai_service/`, install `requirements.txt` and run `app.py` (defaults to `http://127.0.0.1:8000`). It replaces `semantic_service/`; if both are configured on the same URL, only one needs to run.

If the Python service is not running, the semantic similarity and AI-likelihood checks are skipped, and the hybrid score renormalizes over the other three methods. The AI-likelihood section is left blank until a detector model has been trained.

If you already had the database from an earlier version, re-run `install.php` once; it adds missing columns without touching existing rows. Alternatively, run `migrate_add_source_type.sql` once against the existing database.

`config.php` holds your API key and is excluded from version control. Never commit it or share it.

## Configuration

Current settings in `config.php`:

| Setting | Purpose |
|---|---|
| `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` | MySQL connection settings |
| `GOOGLE_VISION_API_KEY` | API key for OCR |
| `INPUT_MODE` | Default upload mode: `text` or `image` (upload page can override it per batch) |
| `SHINGLE_SIZE` | Character n-gram size (default 5) |
| `SIM_METHOD` | `dice` or `jaccard` |
| `FLAG_THRESHOLD` | An essay is flagged when its highest hybrid score against any other essay is at or above this value |
| `CONFIDENCE_CUTOFF` | Words below this OCR confidence are excluded |
| `READABILITY_MIN_WORDS` / `READABILITY_MIN_AVG_CONF` | Readability gate |
| `TEXT_MIN_WORDS` | Minimum words accepted in text mode (default 20) |
| `UPLOAD_DIR` | Folder where uploaded images and text files are stored |
| `MAX_UPLOAD_BYTES` | Upload size limit |
| `W_NGRAM` / `W_TFIDF` / `W_LEV` / `W_SEMANTIC` | Hybrid weights (sum to 1) |
| `LEVEL_HIGH` / `LEVEL_MODERATE` | Similarity-level cutoffs for the human label |
| `HIGHLIGHT_THRESHOLD` | Sentence-pair score at or above which sentences are highlighted |
| `SEMANTIC_SERVICE_URL` / `SEMANTIC_TIMEOUT` | Local Python service address and timeout for embeddings |
| `AI_SERVICE_URL` / `AI_TIMEOUT` | Local Python service address and timeout for AI-text detection (defaults to the semantic URL) |
| `AI_FLAG_THRESHOLD` | AI-likelihood at or above which an essay is highlighted for review |
| `WEB_SOURCE_MODE` | How candidate sources are found (`local` reference corpus; `search` planned) |
| `WEB_MAX_PAGES` | Maximum source matches kept per essay |
| `WEB_CHUNK_SENTENCES` | Number of sentences per checked chunk |
| `REFERENCE_DIR` | Folder holding the plain-text reference sources |

## Limitations

- OCR is not perfect, especially on inconsistent, faint, or unconventional handwriting. Misread words can lower similarity scores and distort the text the AI detector sees.
- Filipino handwriting, Filipino semantic scoring, and Filipino AI detection are likely less accurate than English, since the OCR is stronger in English and our Filipino training data is smaller.
- The self-trained models depend on the size and variety of our dataset. They will not match large commercial detectors or large pretrained embedding models.
- No detector can prove that a text was written by AI. The AI-likelihood score can produce false positives and false negatives, so it is only evidence for the user to weigh.
- A detector trained on a few AI tools may miss text from other tools or from text that was edited by a student after generation.
- Internet source checking currently only finds what is in `reference_sources/`; it does not search the live web (search mode is planned).
- The AI-likelihood and Filipino semantic scores stay blank or fall back to pretrained until the self-trained models are trained on our dataset.
- Very heavily mixed Filipino-English essays may be labeled with the wrong dominant language.
- TF-IDF is weak when only a few essays are compared, since word weights depend on the other documents.
- Similarity scores prioritize pairs for review. A high score is evidence, not proof of plagiarism.

## Evaluation plan

**Similarity (pairwise)**

1. Build a small test set of essay pairs: copied, lightly edited, reworded, and unrelated, in both Filipino and English.
2. Run each pair on typed text, OCR of printed text, and OCR of handwriting.
3. Report how each method and the hybrid score perform in each condition, and use the results to set the weights and similarity levels.
4. Compare the self-trained embedding model against the first-version pretrained model, and report the difference.

**AI-text detector**

1. Evaluate on the held-out test set, which uses prompts the model never saw in training.
2. Compare Logistic Regression, SVM, and Random Forest with precision, recall, F1, and a confusion matrix.
3. Report results per language and per input condition (typed, OCR of printed, OCR of handwriting), with particular attention to the false-positive rate on human essays.

**Internet source checking**

1. Take essays with passages copied from known sources, plus essays with no source.
2. Report how often the correct source is found and how often unrelated sources are matched.

## Pending decisions

- Whether the professor's "build our own AI" requirement also covers the Google Cloud Vision OCR, or applies only to the similarity and detection models.
- Whether a search API is acceptable for finding candidate web pages, or whether the locally collected reference corpus (now implemented) is enough.
- Whether the thesis title should be revised to mention AI-generated text and internet source detection.