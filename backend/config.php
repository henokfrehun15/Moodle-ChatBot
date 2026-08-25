<?php

// GEMINI API KEY BELOW 
define('GEMINI_API_KEY', 'AQ.Ab8RN6L4qjsWeF-ExAMupb3ThQgMvjwAydhL-Uo25gUR7b41VQ');

// Models 
define('EMBEDDING_MODEL', 'gemini-embedding-001');
define('GENERATION_MODEL', 'gemini-3.5-flash-lite');

// Where the ingested knowledge base is stored
define('VECTOR_STORE_PATH', __DIR__ . '/vectors.json');

// Where source documents live (plain .txt files for now)
define('KNOWLEDGE_BASE_DIR', __DIR__ . '/knowledge_base');

// How many chunks to retrieve per question
define('TOP_K', 4);

// Minimum similarity score (0-1) to consider a chunk "relevant".
// Below this, the bot refuses instead of guessing.
define('MIN_SIMILARITY', 0.55);

// MOODLE WEB SERVICES (for auto-pulling course content)
define('MOODLE_URL', 'http://localhost/moodle'); 
define('MOODLE_TOKEN', 'af3c9ce6f72149713ad8ae4320742351');
// MOODLE CONTEXT SECURITY
define(
    'MOODLE_CONTEXT_SECRET',
    'hheennookkffrreehhuunnttssiiggee'
);