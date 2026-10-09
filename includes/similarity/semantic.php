<?php
declare(strict_types=1);

/**
 * Semantic similarity via a local Python service.
 *
 * The service embeds each sentence with a multilingual model
 * (paraphrase-multilingual-MiniLM-L12-v2) and returns a cosine-based score plus
 * one-to-one sentence matches. When the service is not running the method
 * returns null and the hybrid score renormalizes over the remaining methods.
 */

function semantic_service_enabled(): bool
{
    return defined('SEMANTIC_SERVICE_URL')
        && SEMANTIC_SERVICE_URL !== ''
        && function_exists('curl_init');
}

/**
 * @return array{score: float, pairs: array}|null
 */
function semantic_similarity(array $sentencesA, array $sentencesB): ?array
{
    if (!semantic_service_enabled() || $sentencesA === [] || $sentencesB === []) {
        return null;
    }

    $payload = json_encode([
        'sentences_a' => array_values($sentencesA),
        'sentences_b' => array_values($sentencesB),
    ]);
    if ($payload === false) {
        return null;
    }

    $ch = curl_init(rtrim(SEMANTIC_SERVICE_URL, '/') . '/similarity');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => defined('SEMANTIC_TIMEOUT') ? SEMANTIC_TIMEOUT : 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || !isset($data['score'])) {
        return null;
    }

    return [
        'score' => (float)$data['score'],
        'pairs' => isset($data['pairs']) && is_array($data['pairs']) ? $data['pairs'] : [],
    ];
}
