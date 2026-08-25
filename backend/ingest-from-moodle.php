<?php
/**
 * INGEST FROM MOODLE
 * ------------------------------------------------------------
 * Pulls Moodle course content into the RAG knowledge base.
 *
 * Handles:
 *   - Page
 *   - Label
 *   - Assignment descriptions
 *   - Forum descriptions
 *   - Lesson descriptions
 *   - Book descriptions
 *   - Resource files
 *   - Folder files
 *   - DOCX
 *   - PDF
 *   - TXT
 *   - VTT
 *   - SRT
 *
 * Each chunk contains metadata describing:
 *   - course
 *   - section
 *   - activity
 *   - activity type
 *   - source
 *   - file
 *
 * Run:
 *
 *   php ingest-from-moodle.php
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/moodle_api.php';
require_once __DIR__ . '/extractors.php';


/* ============================================================
   CONFIGURATION
   ============================================================ */

define(
    'TEMP_DOWNLOAD_DIR',
    __DIR__ . '/tmp_downloads'
);

if (!is_dir(TEMP_DOWNLOAD_DIR)) {
    mkdir(TEMP_DOWNLOAD_DIR, 0777, true);
}


/* ============================================================
   TEXT CHUNKING
   ============================================================ */

function chunk_text($text, $maxWords = 180)
{
    $text = trim($text);

    if ($text === '') {
        return [];
    }

    $words = preg_split('/\s+/', $text);

    $chunks = [];

    for (
        $i = 0;
        $i < count($words);
        $i += $maxWords
    ) {
        $chunk = implode(
            ' ',
            array_slice(
                $words,
                $i,
                $maxWords
            )
        );

        if (trim($chunk) !== '') {
            $chunks[] = trim($chunk);
        }
    }

    return $chunks;
}


/* ============================================================
   DOWNLOAD MOODLE FILE
   ============================================================ */

function download_moodle_file($fileurl, $localPath)
{
    if (empty($fileurl)) {

        echo "    ! Empty Moodle file URL\n";

        return false;
    }

    $separator =
        strpos($fileurl, '?') === false
        ? '?'
        : '&';

    $urlWithToken =
        $fileurl .
        $separator .
        'token=' .
        urlencode(MOODLE_TOKEN);

    echo "    URL: {$fileurl}\n";

    $ch = curl_init($urlWithToken);

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_FOLLOWLOCATION => true,

        CURLOPT_CONNECTTIMEOUT => 10,

        CURLOPT_TIMEOUT => 60,

        CURLOPT_SSL_VERIFYPEER => false,

        CURLOPT_SSL_VERIFYHOST => false,

        CURLOPT_USERAGENT =>
            'Moodle-AI-Ingest/1.0',

        CURLOPT_HTTPHEADER => [
            'Accept: */*'
        ],
    ]);

    $data = curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    $contentType =
        curl_getinfo(
            $ch,
            CURLINFO_CONTENT_TYPE
        );

    $curlError =
        curl_error($ch);

    curl_close($ch);


    if (
        $data === false ||
        $data === ''
    ) {

        echo "    ! Download failed\n";

        if ($curlError !== '') {

            echo
                "    ! cURL error: {$curlError}\n";
        }

        return false;
    }


    echo "    HTTP: {$httpCode}\n";

    echo
        "    Content-Type: {$contentType}\n";


    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        echo
            "    ! Moodle returned HTTP {$httpCode}\n";

        return false;
    }


    /*
     * Moodle may return JSON/HTML when authentication
     * or permissions fail.
     */

    if (
        stripos(
            $contentType,
            'text/html'
        ) !== false ||

        stripos(
            $contentType,
            'application/json'
        ) !== false
    ) {

        echo
            "    ! Moodle returned {$contentType} instead of the file.\n";

        $debugPath =
            TEMP_DOWNLOAD_DIR .
            '/download_error_' .
            uniqid() .
            '.txt';

        file_put_contents(
            $debugPath,
            $data
        );

        echo
            "    ! Debug response saved to:\n";

        echo
            "      {$debugPath}\n";

        echo
            "    ! Response body:\n";

        echo
            "      " .
            substr($data, 0, 1000) .
            "\n";

        return false;
    }


    /*
     * Validate file signatures.
     */

    $extension =
        strtolower(
            pathinfo(
                $localPath,
                PATHINFO_EXTENSION
            )
        );


    /*
     * PDF signature.
     */

    if ($extension === 'pdf') {

        if (
            substr($data, 0, 5)
            !== '%PDF-'
        ) {

            echo
                "    ! Downloaded data is not a valid PDF.\n";

            return false;
        }
    }


    /*
     * DOCX is a ZIP archive.
     */

    if ($extension === 'docx') {

        if (
            substr($data, 0, 2)
            !== 'PK'
        ) {

            echo
                "    ! Downloaded data is not a valid DOCX/ZIP file.\n";

            return false;
        }
    }


    if (
        file_put_contents(
            $localPath,
            $data
        ) === false
    ) {

        echo
            "    ! Could not save downloaded file.\n";

        return false;
    }

    return true;
}


/* ============================================================
   ADD ACTIVITY DESCRIPTION
   ============================================================ */

function extract_activity_description(
    $module
) {

    $pieces = [];

    if (
        isset($module['description']) &&
        trim($module['description']) !== ''
    ) {

        $description =
            moodle_html_to_text(
                $module['description']
            );

        if ($description !== '') {

            $pieces[] =
                $description;
        }
    }


    /*
     * Some Moodle activities expose additional
     * content fields.
     */

    $possibleFields = [
        'intro',
        'content',
        'summary',
    ];

    foreach (
        $possibleFields
        as $field
    ) {

        if (
            !isset($module[$field]) ||
            trim((string)$module[$field]) === ''
        ) {
            continue;
        }

        $text =
            moodle_html_to_text(
                $module[$field]
            );

        if ($text !== '') {

            $pieces[] = $text;
        }
    }


    return trim(
        implode(
            "\n\n",
            array_unique($pieces)
        )
    );
}


/* ============================================================
   FILE EXTENSIONS
   ============================================================ */

$videoExtensions = [
    'mp4',
    'mov',
    'avi',
    'webm',
    'mkv',
    'm4v'
];

$imageExtensions = [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'svg',
    'webp'
];

$textExtensions = [
    'docx',
    'pdf',
    'vtt',
    'srt',
    'txt'
];


/* ============================================================
   GET COURSES
   ============================================================ */

echo
    "Fetching course list from Moodle...\n";

try {

    $courses =
        moodle_call(
            'core_course_get_courses'
        );

} catch (Exception $e) {

    echo
        "\nMoodle API error:\n";

    echo
        $e->getMessage() .
        "\n";

    exit(1);
}


if (
    !is_array($courses) ||
    empty($courses)
) {

    echo
        "No courses were returned.\n";

    exit(1);
}


/* ============================================================
   INGESTION STATE
   ============================================================ */

$notExtracted = [];

$newEntries = [];

$totalChunks = 0;

$totalActivities = 0;

$totalFiles = 0;

$pdfLibAvailable =
    file_exists(
        __DIR__ .
        '/vendor/autoload.php'
    );


if (!$pdfLibAvailable) {

    echo
        "NOTE: PDF library not installed.\n";

    echo
        "Run 'composer install' in the backend folder.\n";
}


/* ============================================================
   PROCESS COURSES
   ============================================================ */

foreach (
    $courses
    as $course
) {

    /*
     * Moodle's site course is usually ID 1.
     */

    if (
        (int)$course['id'] === 1
    ) {
        continue;
    }


    $courseId =
        (int)$course['id'];

    $shortname =
        $course['shortname'];

    $fullname =
        $course['fullname'];


    echo
        "\n========================================\n";

    echo
        "Course: {$fullname}\n";

    echo
        "Shortname: {$shortname}\n";

    echo
        "Course ID: {$courseId}\n";

    echo
        "========================================\n";


    try {

        $contents =
            moodle_call(
                'core_course_get_contents',
                [
                    'courseid' =>
                        $courseId
                ]
            );

    } catch (Exception $e) {

        echo
            "  ! Failed to get course contents: " .
            $e->getMessage() .
            "\n";

        continue;
    }


    if (
        !is_array($contents)
    ) {
        continue;
    }


    /* ========================================================
       PROCESS SECTIONS
       ======================================================== */

    foreach (
        $contents
        as $sectionIndex =>
        $section
    ) {

        $sectionName =
            trim(
                $section['name']
                ?? ''
            );


        if (
            $sectionName === ''
        ) {

            $sectionName =
                'Section ' .
                ($sectionIndex + 1);
        }


        echo
            "\n  Section: {$sectionName}\n";


        $modules =
            $section['modules']
            ?? [];


        foreach (
            $modules
            as $module
        ) {

            $totalActivities++;


            $modname =
                strtolower(
                    trim(
                        $module['modname']
                        ?? 'unknown'
                    )
                );


            $activityName =
                trim(
                    $module['name']
                    ?? 'Unnamed activity'
                );


            echo
                "\n  Activity: {$activityName}";

            echo
                " [{$modname}]\n";


            $textPieces = [];

            $fileSources = [];


            /* =================================================
               ACTIVITY DESCRIPTION
               ================================================= */

            $activityDescription =
                extract_activity_description(
                    $module
                );


            /*
             * Keep actual Moodle activity text.
             */

            if (
                $activityDescription !== ''
            ) {

                $textPieces[] =
                    $activityDescription;
            }


            /* =================================================
               PAGE / LABEL
               ================================================= */

            if (
                $modname === 'page' ||
                $modname === 'label'
            ) {

                foreach (
                    ($module['contents'] ?? [])
                    as $content
                ) {

                    if (
                        isset(
                            $content['content']
                        )
                    ) {

                        $text =
                            moodle_html_to_text(
                                $content['content']
                            );

                        if (
                            $text !== ''
                        ) {

                            $textPieces[] =
                                $text;
                        }
                    }
                }
            }


            /* =================================================
               RESOURCE / FOLDER FILES
               ================================================= */

            if (
                in_array(
                    $modname,
                    [
                        'resource',
                        'folder'
                    ],
                    true
                )
            ) {

                foreach (
                    ($module['contents'] ?? [])
                    as $content
                ) {

                    if (
                        ($content['type'] ?? '')
                        !== 'file'
                    ) {
                        continue;
                    }


                    if (
                        empty(
                            $content['fileurl']
                        )
                    ) {
                        continue;
                    }


                    $totalFiles++;


                    $filename =
                        $content['filename']
                        ?? 'file';


                    $extension =
                        strtolower(
                            pathinfo(
                                $filename,
                                PATHINFO_EXTENSION
                            )
                        );


                    echo
                        "    - File: {$filename}\n";


                    /*
                     * Images and videos don't currently
                     * provide searchable text.
                     */

                    if (
                        in_array(
                            $extension,
                            $videoExtensions,
                            true
                        )
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (video — no transcript)";

                        continue;
                    }


                    if (
                        in_array(
                            $extension,
                            $imageExtensions,
                            true
                        )
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (image — no text extraction)";

                        continue;
                    }


                    /*
                     * PDF requires the parser library.
                     */

                    if (
                        $extension === 'pdf' &&
                        !$pdfLibAvailable
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (PDF parser unavailable)";

                        continue;
                    }


                    if (
                        !in_array(
                            $extension,
                            $textExtensions,
                            true
                        )
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (unsupported .$extension)";

                        continue;
                    }


                    /*
                     * Temporary local file.
                     */

                    $localPath =
                        TEMP_DOWNLOAD_DIR .
                        '/' .
                        uniqid(
                            'moodle_',
                            true
                        ) .
                        '.' .
                        $extension;


                    echo
                        "      Downloading...\n";


                    if (
                        !download_moodle_file(
                            $content['fileurl'],
                            $localPath
                        )
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (download failed)";

                        continue;
                    }
                    /*
                     * Extract text.
                     */

                    try {

                        $extracted =
                            extract_text_by_extension(
                                $localPath,
                                $extension
                            );

                    } catch (
                        Exception $e
                    ) {

                        $extracted = '';

                        echo
                            "      ! Extraction error: " .
                            $e->getMessage() .
                            "\n";
                    }


                    @unlink(
                        $localPath
                    );


                    if (
                        trim($extracted) === ''
                    ) {

                        $notExtracted[] =
                            "{$shortname} / {$sectionName} / {$activityName} / {$filename} (no text extracted)";

                        continue;
                    }


                    /*
                     * Keep filename metadata inside the
                     * source information.
                     */

                    $fileSources[] =
                        [
                            'filename' =>
                                $filename,
                            'text' =>
                                $extracted
                        ];
                }
            }
            /* =================================================
               ADD FILE TEXT
               ================================================= */

            foreach (
                $fileSources
                as $fileSource
            ) {

                $fileHeader =
                    "File: " .
                    $fileSource['filename'];


                $textPieces[] =
                    $fileHeader .
                    "\n" .
                    $fileSource['text'];
            }


            /* =================================================
               FALLBACK
               ================================================= */

            if (
                empty($textPieces)
            ) {

                /*
                 * Even if an activity has no searchable
                 * content, we don't embed an empty record.
                 */

                echo
                    "    No searchable text exposed by this activity.\n";

                continue;
            }


            $fullText =
                trim(
                    implode(
                        "\n\n",
                        array_filter(
                            $textPieces
                        )
                    )
                );


            /*
             * Ignore tiny fragments.
             */

            if (
                $fullText === '' ||
                str_word_count(
                    $fullText
                ) < 15
            ) {

                echo
                    "    Text too short to index.\n";

                continue;
            }


            /* =================================================
               CHUNK
               ================================================= */

            $chunks =
                chunk_text(
                    $fullText,
                    180
                );


            $sourceName =
                $activityName;


            /*
             * Generate one embedding per chunk.
             */

            foreach (
                $chunks
                as $chunkIndex =>
                $chunk
            ) {

                echo
                    "    - Embedding: {$activityName} " .
                    "(chunk " .
                    ($chunkIndex + 1) .
                    "/" .
                    count($chunks) .
                    ")...\n";


                try {

                    $vector =
                        gemini_embed(
                            $chunk
                        );


                    /*
                     * IMPORTANT:
                     *
                     * Section + activity type are now
                     * stored as metadata.
                     */

                    $newEntries[] = [

                        'id' =>
                            $shortname .
                            '/' .
                            $sectionName .
                            '/' .
                            $activityName .
                            '#' .
                            $chunkIndex,

                        'source' =>
                            $sourceName,

                        'course' =>
                            $shortname,

                        'course_id' =>
                            $courseId,

                        'section' =>
                            $sectionName,

                        'activity' =>
                            $activityName,

                        'activity_type' =>
                            $modname,

                        'chunk_index' =>
                            $chunkIndex,

                        'text' =>
                            $chunk,

                        'vector' =>
                            $vector
                    ];


                    $totalChunks++;


                } catch (
                    Exception $e
                ) {

                    echo
                        "      ! Embedding failed: " .
                        $e->getMessage() .
                        "\n";
                }


                /*
                 * Small delay to avoid hammering
                 * the embedding API.
                 */

                usleep(200000);
            }
        }
    }
}


/* ============================================================
   UPDATE VECTOR STORE
   ============================================================ */

$existing = [];


if (
    file_exists(
        VECTOR_STORE_PATH
    )
) {

    $existing =
        json_decode(
            file_get_contents(
                VECTOR_STORE_PATH
            ),
            true
        );


    if (
        !is_array($existing)
    ) {

        $existing = [];
    }
}


/*
 * Remove old records that have the same IDs.
 */

$newIds =
    array_column(
        $newEntries,
        'id'
    );


$kept =
    array_filter(
        $existing,
        function ($entry) use ($newIds) {

            return !in_array(
                $entry['id'] ?? '',
                $newIds,
                true
            );
        }
    );


$finalStore =
    array_merge(
        array_values($kept),
        $newEntries
    );


file_put_contents(
    VECTOR_STORE_PATH,
    json_encode(
        $finalStore,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    )
);


/* ============================================================
   SUMMARY
   ============================================================ */

echo
    "\n========================================\n";

echo
    "INGESTION COMPLETE\n";

echo
    "========================================\n";

echo
    "Courses processed: " .
    count($courses) .
    "\n";

echo
    "Activities inspected: " .
    $totalActivities .
    "\n";

echo
    "Files inspected: " .
    $totalFiles .
    "\n";

echo
    "New chunks indexed: " .
    $totalChunks .
    "\n";

echo
    "Total chunks in knowledge base: " .
    count($finalStore) .
    "\n";


if (
    !empty($notExtracted)
) {

    echo
        "\n----------------------------------------\n";

    echo
        count(
            array_unique(
                $notExtracted
            )
        ) .
        " file(s) were not indexed:\n";


    foreach (
        array_unique(
            $notExtracted
        )
        as $file
    ) {

        echo
            "  - {$file}\n";
    }


    echo
        "\nVideos can be indexed by adding matching .vtt/.srt captions.\n";
}


echo
    "\nDone.\n";