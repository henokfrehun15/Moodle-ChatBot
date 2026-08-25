<?php
/**
 * File text extractors used during Moodle ingestion.
 * Includes diagnostic error reporting and Moodle HTML error page detection.
 */

/**
 * Resolves composer's autoloader location across common project structures.
 */
function get_composer_autoloader() {
    $paths = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Checks if a downloaded file is actually an HTML error page (e.g., Moodle login/token redirect).
 */
function is_html_response($path) {
    if (!file_exists($path) || filesize($path) === 0) {
        return false;
    }
    $handle = fopen($path, 'r');
    $header = fread($handle, 512);
    fclose($handle);

    return (stripos($header, '<!DOCTYPE html>') !== false || stripos($header, '<html') !== false);
}

/**
 * Extract text from a .docx file using PHP's built-in ZipArchive.
 */
function extract_docx_text($path) {
    if (!class_exists('ZipArchive')) {
        echo "  [Error] PHP 'zip' extension is disabled in php.ini.\n";
        return '';
    }

    if (is_html_response($path)) {
        echo "  [Error] File contains HTML content instead of binary DOCX (Moodle auth failure).\n";
        return '';
    }

    $zip = new ZipArchive();
    $res = $zip->open($path);
    if ($res !== true) {
        echo "  [Error] ZipArchive failed to open file (ErrorCode: {$res}).\n";
        return '';
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        echo "  [Error] Missing 'word/document.xml' in archive structure.\n";
        return '';
    }

    $xml = str_replace('</w:p>', "\n\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/**
 * Extract text from a PDF using smalot/pdfparser.
 */
function extract_pdf_text($path) {
    $autoload = get_composer_autoloader();
    if (!$autoload) {
        echo "  [Error] Composer autoloader not found. Run 'composer require smalot/pdfparser'.\n";
        return '';
    }

    require_once $autoload;

    if (is_html_response($path)) {
        echo "  [Error] File contains HTML content instead of binary PDF (Moodle auth failure).\n";
        return '';
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);
        $text = $pdf->getText();
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    } catch (\Throwable $e) {
        echo "  [Error] PDF Parser Exception: " . $e->getMessage() . "\n";
        return '';
    }
}

/**
 * Extract spoken text from .vtt or .srt caption files.
 */
function extract_caption_text($path) {
    if (!file_exists($path)) return '';

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!$lines) return '';

    $textLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^\d+$/', $line)) continue;
        if (strpos($line, '-->') !== false) continue;
        if (stripos($line, 'WEBVTT') === 0) continue;
        $textLines[] = $line;
    }

    $deduped = [];
    foreach ($textLines as $line) {
        if (end($deduped) !== $line) $deduped[] = $line;
    }

    return trim(implode(' ', $deduped));
}

/**
 * Main dispatcher based on file extension.
 */
function extract_text_by_extension($path, $extension) {
    if (!file_exists($path) || filesize($path) === 0) {
        echo "  [Error] File missing or empty at path: {$path}\n";
        return '';
    }

    $extension = strtolower($extension);
    switch ($extension) {
        case 'docx':
            return extract_docx_text($path);
        case 'pdf':
            return extract_pdf_text($path);
        case 'vtt':
        case 'srt':
            return extract_caption_text($path);
        case 'txt':
            return trim((string)file_get_contents($path));
        default:
            return '';
    }
}