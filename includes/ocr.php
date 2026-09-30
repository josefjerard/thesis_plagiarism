<?php
declare(strict_types=1);

/**
 * Google Cloud Vision OCR wrapper.
 *
 * Runs DOCUMENT_TEXT_DETECTION synchronously, returns the raw transcription
 * plus a "confident text" built from ONLY the words whose per-word confidence
 * is >= CONFIDENCE_CUTOFF (the confidence-gating step).
 */
function vision_ocr(string $imageBytes): array
{
    $request = [
        'requests' => [[
            'image'    => ['content' => base64_encode($imageBytes)],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
        ]],
    ];

    $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_API_KEY;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($request),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('OCR request failed: ' . $curlErr);
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 && isset($data['error'])) {
        throw new RuntimeException('Vision API ' . $httpCode . ': ' . ($data['error']['message'] ?? 'unknown error'));
    }

    $annotation = $data['responses'][0]['fullTextAnnotation'] ?? null;
    if ($annotation === null || empty($annotation['text'])) {
        return ['raw_text' => '', 'confident_text' => '', 'word_count' => 0, 'low_conf_words' => 0, 'avg_confidence' => 0.0, 'has_annotation' => false];
    }

    $rawText = $annotation['text'];

    $confidentLines = [];
    $totalWords = 0;
    $confidenceSum = 0.0;
    $lowConfWords = 0;

    foreach ($annotation['pages'] ?? [] as $page) {
        foreach ($page['blocks'] ?? [] as $block) {
            foreach ($block['paragraphs'] ?? [] as $paragraph) {
                $keptWords = [];
                foreach ($paragraph['words'] ?? [] as $word) {
                    $text = '';
                    foreach ($word['symbols'] ?? [] as $symbol) {
                        $text .= $symbol['text'] ?? '';
                    }
                    $conf = (float)($word['confidence'] ?? 0.0);

                    $totalWords++;
                    $confidenceSum += $conf;
                    if ($conf < CONFIDENCE_CUTOFF) {
                        $lowConfWords++;
                        continue;
                    }
                    if ($text !== '') {
                        $keptWords[] = $text;
                    }
                }
                if ($keptWords !== []) {
                    $confidentLines[] = implode(' ', $keptWords);
                }
            }
        }
    }

    return [
        'raw_text'        => $rawText,
        'confident_text'  => implode("\n", $confidentLines),
        'word_count'      => $totalWords,
        'low_conf_words'  => $lowConfWords,
        'avg_confidence'  => $totalWords > 0 ? round($confidenceSum / $totalWords, 4) : 0.0,
        'has_annotation'  => true,
    ];
}

/**
 * Readability gate (step 2 of the system flow).
 * Rejects images that yielded very few words or consistently low confidence.
 */
function image_is_readable(array $ocr): bool
{
    if (empty($ocr['has_annotation'])) {
        return false;
    }
    if ($ocr['word_count'] < READABILITY_MIN_WORDS) {
        return false;
    }
    if ($ocr['avg_confidence'] < READABILITY_MIN_AVG_CONF) {
        return false;
    }
    return true;
}