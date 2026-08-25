<?php
require_once __DIR__ . '/config.php';

/**
 * Calls Gemini's embedContent endpoint and returns a float vector.
 */
function gemini_embed($text) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . EMBEDDING_MODEL . ":embedContent";
    $body = [
        "content" => ["parts" => [["text" => $text]]]
    ];
    $result = gemini_post($url, $body);
    if (!isset($result['embedding']['values'])) {
        throw new Exception("Embedding failed: " . json_encode($result));
    }
    return $result['embedding']['values'];
}

/**
 * Calls Gemini's generateContent endpoint with a full prompt string.
 * Returns the plain text response.
 */
function gemini_generate($prompt, $maxOutputTokens = 350, $temperature = 0.2) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . GENERATION_MODEL . ":generateContent";
    $body = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ],
        "generationConfig" => [
            "temperature" => $temperature,
            "maxOutputTokens" => $maxOutputTokens
        ]
    ];
    $result = gemini_post($url, $body);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Generation failed: " . json_encode($result));
    }
    return $result['candidates'][0]['content']['parts'][0]['text'];
}

/**
 * Low-level POST helper with the API key header.
 */
function gemini_post($url, $bodyArray) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "x-goog-api-key: " . GEMINI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyArray));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: $error");
    }
    $decoded = json_decode($response, true);
    if ($httpCode !== 200) {
        throw new Exception("Gemini API error ($httpCode): " . $response);
    }
    return $decoded;
}

/**
 * Cosine similarity between two equal-length vectors.
 */
function cosine_similarity($a, $b) {
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    $len = count($a);
    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    if ($normA == 0 || $normB == 0) return 0;
    return $dot / (sqrt($normA) * sqrt($normB));
}