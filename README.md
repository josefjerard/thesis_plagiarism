# Plagiarism Detection for Student Essays

A web-based system that detects textual similarity between handwritten student essay submissions using OCR and TF-IDF/cosine similarity, built as a Computer Science thesis project.

Overview

This system allows students to submit photos of handwritten essays for an activity. The system extracts text from the images using OCR, compares submissions for similarity, and flags potential plagiarism cases for admin review — reducing the manual burden of comparing essays by hand.

Features
Single-user system: Used only by one admin — no login page is implemented at this stage
Image upload: Students upload photos of handwritten essays instead of typing them directly
Readability check: Uploaded images are validated before processing; unreadable images are rejected and the student is prompted to reupload a clearer photo
OCR text extraction: Handwritten essay images are transcribed to text using Google Cloud Vision's Document Text Detection
Similarity detection: Extracted essay text is compared using TF-IDF vectorization and cosine similarity, scoped per activity (essays are only compared against other submissions for the same activity, not the entire database)
Side-by-side comparison: Flagged submissions can be viewed side by side for easier manual review
Accuracy threshold: Similarity scores above a configurable threshold are flagged for admin review rather than automatically rejected — the admin makes the final determination
Admin review queue: All flagged submissions are routed to the admin for manual verification
System Flow
Student uploads essay image(s)
System checks if the image is readable
If unreadable → image is rejected, student is asked to reupload
If readable → proceeds to analysis
Extracted text is analyzed for similarity against other submissions in the same activity
Similarity score is compared against the configured accuracy threshold
Flagged results are queued for admin review
Admin reviews and makes a final decision

Note: A login page is not implemented yet, since the system currently has only one user (the admin).

Tech Stack
Frontend: HTML, CSS, JavaScript
Backend: PHP
Database: MySQL
Local environment: XAMPP
OCR: Google Cloud Vision API (Document Text Detection)
Similarity algorithm: TF-IDF + Cosine Similarity
OCR Integration (Google Cloud Vision)

Since submitted essays are handwritten images rather than typed text, the system uses Google Cloud Vision's DOCUMENT_TEXT_DETECTION feature to transcribe each image before running similarity analysis.

Images are base64-encoded and sent to the Vision API via a PHP cURL request
Extracted text is pulled from fullTextAnnotation.text in the API response
Per-word confidence scores are available in the response and can be used to flag low-confidence transcriptions for review
Requires a Google Cloud project with billing enabled and a restricted API key (kept out of version control)
Accuracy Threshold

The similarity threshold used to flag a submission is configurable rather than hardcoded, since the admin retains final review authority. A common reference point from related literature classifies similarity as:

Low similarity: below 30%
Moderate similarity: 30%–70% (flagged for review)
High similarity: above 70% (strongly flagged)

The exact cutoff should be tuned empirically using sample essay pairs during testing.

Limitations
OCR accuracy is not 100%, particularly for handwriting with inconsistent penmanship, faint ink, or unconventional letterforms. Misrecognized characters or words may affect the accuracy of subsequent similarity scoring.
Unreadable image handling: the system performs a basic readability check at upload and will reject images deemed unreadable (e.g., blurry, poorly lit, or incomplete), requiring resubmission. This reduces OCR failures but places some burden on the student to provide a clear photo, and does not guarantee all borderline-quality images will be handled correctly.
The system does not detect paraphrased or semantically reworded plagiarism — only textual similarity is measured.
Status

Thesis title approved. System design and OCR integration approach finalized; implementation in progress.