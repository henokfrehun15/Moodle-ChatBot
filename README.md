# Moodle AI Course Assistant (RAG + Gemini)

A learner-aware AI chatbot for Moodle that answers questions **strictly from your approved
course content** — with source citations, refusal on ungrounded questions, and awareness of
each learner's enrolled courses, progress, and current page.

Platform/navigation questions (e.g. "how do I download my certificate?") get a more flexible
answer, falling back to general knowledge when it's not covered in the docs — clearly labeled
as such. Course-content questions **never** fall back to general knowledge; if it's not in the
approved knowledge base, the bot says so instead of guessing.

---

## How it works

```
Learner types a question
        │
        ▼
Chat widget (floating bubble, embedded in every Moodle page)
        │  sends: question + learner's course/progress/current-page context
        ▼
rag.php  ──────────────────────────────────────────────┐
        │  1. Classifies question: "platform" vs "course"      │
        │  2. Embeds the question (Gemini)                     │
        │  3. Searches the knowledge base, filtered to only    │
        │     the learner's enrolled courses                   │
        │  4. If no good match on a "course" question → refuses│
        │  5. Otherwise generates an answer using ONLY the      │
        │     retrieved chunks, with a source citation          │
        └────────────────────────────────────────────────────┘
        │
        ▼
Answer shown in the widget, with source or a "general guidance" label
```

Content gets into the knowledge base one of two ways:
- **Automatically**, by pulling live from Moodle via its Web Services API (Pages, Labels,
  uploaded PDFs/Word docs, video caption files)
- **Manually**, by dropping approved `.txt` files into a course-named folder

---

## Repository structure

```
.
├── moodle-side/
│   └── local/
│       └── aichat/
│           └── context.php          # Runs INSIDE Moodle — reads the logged-in
│                                     # learner's enrolled courses, completion
│                                     # status, and current page location
│
├── backend/
│   ├── config.php                   # API keys, Moodle URL/token, thresholds
│   ├── gemini.php                   # Calls Google Gemini (embeddings + generation)
│   ├── moodle_api.php               # Calls Moodle's Web Services REST API
│   ├── extractors.php               # Pulls text out of .docx / .pdf / .vtt / .srt
│   ├── composer.json                # PDF-parsing dependency
│   ├── ingest.php                   # Builds knowledge base from manual .txt files
│   ├── ingest-from-moodle.php       # Builds knowledge base by pulling from Moodle
│   ├── rag.php                      # The chat endpoint — retrieval + grounded answer
│   ├── vectors.json                 # Generated — the actual knowledge base 
│   └── knowledge_base/              # Manual content, organized by course
│       ├── general/                 # Available to every learner
│       └── <course-shortname>/      # Only searchable by learners in that course
│
├── moodle-chatbot-widget.html       # Paste into Moodle's Additional HTML setting
└── README.md
```

---

## Setup

### 1. Prerequisites
- A working Moodle install (this was built/tested against a local XAMPP setup)
- PHP 8+ with `curl` and `zip` extensions enabled
- [Composer](https://getcomposer.org/) (for the PDF-reading library)
- A free [Google Gemini API key](https://aistudio.google.com/apikey)

### 2. Enable Moodle Web Services
1. **Site administration → Advanced features** → enable "Enable web services"
2. **Site administration → Plugins → Web services → Manage protocols** → enable "REST protocol"
3. **Site administration → Plugins → Web services → External services** → create a service,
   add functions: `core_course_get_courses`, `core_course_get_contents`, `core_course_get_categories`
4. Create a dedicated service account user with permission to view the courses you want indexed
5. **Manage tokens** → generate a token for that user + service

### 3. Install the files
- Copy `moodle-local-plugin/context.php` into your Moodle install at `local/aichat/context.php`
- Copy everything under `backend/` into a standalone folder on your web server (e.g. `htdocs/backend/`)
- Paste the full contents of `additional-html.html` into **Site administration →
  Appearance → Additional HTML → "Before BODY is closed"**

### 4. Configure
Edit `backend/config.php`:
```php
define('GEMINI_API_KEY', 'your-gemini-key');
define('MOODLE_URL', 'http://localhost/moodle');
define('MOODLE_TOKEN', 'your-moodle-webservice-token');
```

### 5. Install dependencies and build the knowledge base
```bash
cd backend
composer install
php ingest-from-moodle.php   # pulls content live from Moodle, or:
php ingest.php               # builds from manual knowledge_base/ folders instead
```

### 6. Test
- Visit `http://localhost/moodle/local/aichat/context.php` while logged in — should return
  JSON with your enrolled courses
- Open the chat bubble on any Moodle page and ask a question covered in your content

---

## Key design decisions

- **No direct database access.** The chatbot never queries Moodle's database directly — it
  only talks to Moodle through `context.php` (learner session) and the Web Services API
  (course content), keeping a clean boundary.
- **Course-scoped retrieval.** Every piece of ingested content is tagged with the course it
  came from. A learner's questions only ever search content from courses they're enrolled in,
  plus general/platform content available to everyone.
- **Grounded, with one exception.** Course-content questions are answered *only* from retrieved
  approved content, with a hard refusal if nothing relevant is found — no guessing. Platform/
  navigation questions are allowed to fall back to general knowledge, clearly labeled as such.
- **Progress- and location-aware.** The bot knows what each learner has completed and, when
  available, which specific page/activity they're currently viewing — so vague questions like
  "what do I do here?" resolve correctly.

## Known limitations (proof-of-concept stage)

- **Videos without captions** are indexed by title only — the bot doesn't know what's actually
  said in them. Add `.vtt`/`.srt` caption files and re-run ingestion to fix this per video.
- **Old `.doc` format** (not `.docx`) isn't auto-extracted; convert to `.docx`/`.pdf` first.
- **Re-indexing is manual.** Content changes in Moodle aren't picked up automatically — re-run
  `ingest-from-moodle.php` after updating approved content.
- **Local file-based vector store.** `vectors.json` is fine for a proof-of-concept; a larger
  deployment should move to a proper vector database.

## License
