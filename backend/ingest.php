<?php
/**
 * INGESTION SCRIPT (course-aware)
 * ------------------------------------------------------------
 * Folder structure now matters:
 *
 *   knowledge_base/
 *     general/                  <- available to every learner regardless
 *       moodle-user-guide.txt      of which courses they're enrolled in
 *     family-planning-101/      <- folder name should match the Moodle
 *       module1.txt                course SHORTNAME exactly
 *       module2.txt
 *     antenatal-care/
 *       ...
 *
 * Run whenever content changes:
 *   php ingest.php
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/gemini.php';

function chunk_text($text, $maxWords = 180) {
    $paragraphs = preg_split('/\n\s*\n/', trim($text));
    $chunks = [];
    $current = '';
    $currentWords = 0;

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $wordCount = str_word_count($para);

        if ($currentWords + $wordCount > $maxWords && $current !== '') {
            $chunks[] = trim($current);
            $current = '';
            $currentWords = 0;
        }
        $current .= $para . "\n\n";
        $currentWords += $wordCount;
    }
    if (trim($current) !== '') {
        $chunks[] = trim($current);
    }
    return $chunks;
}

/**
 * Scans knowledge_base/ for course subfolders (course = folder name)
 * plus a "general" folder for content not tied to a specific course.
 */
function scan_knowledge_base($baseDir) {
    $files = [];
    foreach (scandir($baseDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $baseDir . '/' . $item;
        if (!is_dir($path)) continue;

        $courseTag = ($item === 'general') ? null : $item;
        foreach (glob($path . '/*.txt') as $file) {
            $files[] = ['path' => $file, 'course' => $courseTag];
        }
    }
    return $files;
}

echo "Scanning " . KNOWLEDGE_BASE_DIR . " for course folders...\n";
$files = scan_knowledge_base(KNOWLEDGE_BASE_DIR);

if (empty($files)) {
    echo "No files found. Expected structure: knowledge_base/<course-shortname>/*.txt (or knowledge_base/general/*.txt)\n";
    exit(1);
}

$vectorStore = [];
$totalChunks = 0;

foreach ($files as $fileInfo) {
    $file = $fileInfo['path'];
    $course = $fileInfo['course'];
    $sourceName = basename($file);
    echo "Processing: " . ($course ?? 'general') . "/$sourceName\n";

    $text = file_get_contents($file);
    $chunks = chunk_text($text);

    foreach ($chunks as $i => $chunk) {
        echo "  - Embedding chunk " . ($i + 1) . "/" . count($chunks) . "...\n";
        try {
            $vector = gemini_embed($chunk);
            $vectorStore[] = [
                "id" => ($course ?? 'general') . '/' . $sourceName . '#' . $i,
                "source" => $sourceName,
                "course" => $course, // null = general, available to everyone
                "text" => $chunk,
                "vector" => $vector
            ];
            $totalChunks++;
        } catch (Exception $e) {
            echo "  ! Failed to embed chunk: " . $e->getMessage() . "\n";
        }
        usleep(200000);
    }
}

file_put_contents(VECTOR_STORE_PATH, json_encode($vectorStore));
echo "\nDone. Indexed $totalChunks chunks from " . count($files) . " file(s) into " . VECTOR_STORE_PATH . "\n";
