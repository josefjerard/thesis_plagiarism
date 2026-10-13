<?php
declare(strict_types=1);

/**
 * AI-text likelihood via the local Python service (ai_service/).
 *
 * The service loads a self-trained detector (per language) and returns the
 * probability that a text was written by an AI tool. When the service is not
 * running, or no detector model has been trained yet, the check returns null
 * and the rest of the pipeline is unaffected.
 */

// Fallbacks so the engine still runs if config.php predates a setting.
if (!defined('AI_SERVICE_URL')) define('AI_SERVICE_URL', defined('SEMANTIC_SERVICE_URL') ? SEMANTIC_SERVICE_URL : 'http://127.0.0.1:8000');
if (!defined('AI_TIMEOUT'))     define('AI_TIMEOUT', 30);

function ai_service_enabled(): bool
{
    return defined('AI_SERVICE_URL') && AI_SERVICE_URL !== '' && function_exists('curl_init');
}

/**
 * @return float|null probability in [0,1] that $text is AI-generated,
 *                    or null when the check is unavailable.
 */
function ai_likelihood(string $text, string $language): ?float
{
    if (!ai_service_enabled() || trim($text) === '') {
        return null;
    }

    $payload = json_encode(['text' => $text, 'language' => $language]);
    if ($payload === false) {
        return null;
    }

    $ch = curl_init(rtrim(AI_SERVICE_URL, '/') . '/ai-detect');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => (int)AI_TIMEOUT,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || !isset($data['probability']) || !is_numeric($data['probability'])) {
        return null;
    }

    return round(max(0.0, min(1.0, (float)$data['probability'])), 4);
}