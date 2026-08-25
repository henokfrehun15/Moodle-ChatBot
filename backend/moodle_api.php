<?php
require_once __DIR__ . '/config.php';

/**
 * Calls a Moodle Web Service function via the REST protocol.
 * $params is an associative array of parameters for that function
 * (Moodle's REST API uses a flattened array[key][index] style for lists,
 * handled here automatically via http_build_query).
 */
function moodle_call($function, $params = []) {
    $url = rtrim(MOODLE_URL, '/') . '/webservice/rest/server.php';

    $query = array_merge([
        'wstoken' => MOODLE_TOKEN,
        'wsfunction' => $function,
        'moodlewsrestformat' => 'json',
    ], $params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // don't auto-follow; we want to SEE the redirect target
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // local dev only
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("Moodle API cURL error calling $url: $error");
    }
    if ($raw === '' || $raw === false) {
        throw new Exception("Moodle API returned an empty response from $url (HTTP $httpCode). Check MOODLE_URL is correct.");
    }

    $headers = substr($raw, 0, $headerSize);
    $response = substr($raw, $headerSize);

    if ($httpCode >= 300 && $httpCode < 400) {
        preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
        $location = trim($m[1] ?? '(no Location header found)');
        throw new Exception(
            "Moodle redirected (HTTP $httpCode) instead of returning API data.\n" .
            "We called: $url\n" .
            "It redirected to: $location\n" .
            "This almost always means MOODLE_URL in config.php doesn't exactly match Moodle's own \$CFG->wwwroot " .
            "(check /moodle/config.php on the server) — e.g. http vs https, or a different port/hostname."
        );
    }

    $decoded = json_decode($response, true);

    if ($decoded === null) {
        // Not valid JSON at all — almost always means MOODLE_URL is wrong,
        // or Moodle returned an HTML error/login page instead of API output.
        $preview = substr(strip_tags($response), 0, 300);
        throw new Exception("Moodle did not return valid JSON (HTTP $httpCode) from $url.\nFirst part of response: $preview");
    }
    if (isset($decoded['exception'])) {
        throw new Exception("Moodle API error: " . $decoded['message'] . " (" . $decoded['errorcode'] . ")");
    }
    return $decoded;
}

/**
 * Strips HTML tags and decodes entities from Moodle's rich-text fields,
 * leaving clean plain text suitable for chunking/embedding.
 */
function moodle_html_to_text($html) {
    $text = strip_tags($html ?? '');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}