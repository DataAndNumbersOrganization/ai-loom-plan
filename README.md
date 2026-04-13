# AI Loom Planner

Generate AI-powered implementation plans from Loom video walkthroughs — right from the command line.

This Laravel package watches a Loom recording, extracts the transcript and screenshots, and feeds everything to an AI model (via [Prism](https://github.com/prism-php/prism)) to produce a detailed, actionable implementation plan in Markdown.

## Features

- **Transcript extraction** — pulls Loom transcripts via oEmbed, page scraping, API fallback, and Playwright (automatic cascade)
- **Screenshot capture** — grabs frames at configurable intervals with perceptual-hash deduplication so static screens don't waste tokens
- **AI plan generation** — sends transcript + screenshots to Claude (or any Prism-supported provider) and returns a structured implementation plan
- **Prompt templates** — ships with `feature`, `bug`, `epic`, and `documentation` templates; fully customisable via publish
- **Two commands** — `loom:plan` for full plan generation; `loom:transcript` for quick transcript access

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2+ |
| Laravel | 11.x or 12.x |
| Node.js | 18+ (for Playwright scripts) |
| Playwright | `@playwright/test` installed in your project |

Playwright is only needed if you use screenshot capture (`--screenshots`) or if static transcript scraping fails (Playwright is the final fallback for transcript extraction). The package works without it — you'll just get fewer features.

## Installation

```bash
composer require dan/ai-loom-planner
```

Publish the config file:

```bash
php artisan vendor:publish --tag=loom-planner-config
```

Optionally publish the prompt templates if you want to customise them:

```bash
php artisan vendor:publish --tag=loom-planner-templates
```

### Playwright setup (optional)

If you want screenshot capture or the Playwright transcript fallback:

```bash
npm install @playwright/test
npx playwright install chromium
```

## Configuration

After publishing, edit `config/loom-planner.php`:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider & Model
    |--------------------------------------------------------------------------
    |
    | The Prism provider/model pair used to generate plans.
    | Any provider supported by Prism works (anthropic, openai, etc.).
    |
    */
    'provider' => env('LOOM_PLAN_AI_PROVIDER', 'anthropic'),
    'model'    => env('LOOM_PLAN_AI_MODEL', 'claude-sonnet-4-20250514'),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    |
    | Maximum token budget for the AI response.
    |
    */
    'max_tokens' => env('LOOM_PLAN_MAX_TOKENS', 8000),

    /*
    |--------------------------------------------------------------------------
    | Tech Stack Description
    |--------------------------------------------------------------------------
    |
    | A multi-line string describing your project's tech stack.
    | This is injected into the AI prompt so the plan references the
    | correct frameworks, languages, and tools.
    |
    | Set to null to use the built-in default (Laravel + Next.js + MySQL).
    |
    */
    'tech_stack' => env('LOOM_PLAN_TECH_STACK', null),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | Used in AI prompts to give the model project context.
    | Defaults to your Laravel app name.
    |
    */
    'app_name' => env('LOOM_PLAN_APP_NAME', null), // falls back to config('app.name')

    /*
    |--------------------------------------------------------------------------
    | Output Directory
    |--------------------------------------------------------------------------
    |
    | Where generated plans, contexts, and screenshots are saved.
    |
    */
    'output_dir' => env('LOOM_PLAN_OUTPUT_DIR', 'docs-and-plans/loom'),

    /*
    |--------------------------------------------------------------------------
    | Templates Directory
    |--------------------------------------------------------------------------
    |
    | Path to the directory containing prompt Blade templates.
    | Set to null to use the package's built-in templates.
    | If you publish templates, this is auto-set to your app's resource path.
    |
    */
    'templates_dir' => null,
];
```

### Environment variables

Add the following to your `.env`:

```env
# Required — your Anthropic API key (or whichever provider you use)
ANTHROPIC_API_KEY=your_key_here

# Optional overrides
LOOM_PLAN_AI_PROVIDER=anthropic
LOOM_PLAN_AI_MODEL=claude-sonnet-4-20250514
LOOM_PLAN_MAX_TOKENS=8000
LOOM_PLAN_OUTPUT_DIR=docs-and-plans/loom
```

## Usage

### `loom:plan` — Generate an implementation plan

```bash
# Basic — outputs a copy-pastable prompt (no AI call)
php artisan loom:plan https://www.loom.com/share/abc123def456...

# Send directly to AI and save the generated plan
php artisan loom:plan https://www.loom.com/share/abc123... --ai

# Capture screenshots every 5 seconds (default: 10)
php artisan loom:plan https://www.loom.com/share/abc123... --screenshots=5

# Disable screenshots entirely
php artisan loom:plan https://www.loom.com/share/abc123... --screenshots=0

# Use the "bug" prompt template
php artisan loom:plan https://www.loom.com/share/abc123... --template=bug

# Custom output filename
php artisan loom:plan https://www.loom.com/share/abc123... --output=my-feature-plan
```

#### Options

| Option | Description | Default |
|---|---|---|
| `url` (argument) | Loom video URL | — (required) |
| `--screenshots` | Seconds between screenshot captures (1–60, 0 to disable) | `10` |
| `--template` | Prompt template: `feature`, `bug`, `epic`, or `documentation` | `feature` |
| `--ai` | Send prompt to AI and save the generated plan | `false` (prompt-only) |
| `--output` | Custom output filename | Auto-generated from video title |

#### Workflow

**Without `--ai` (default)** — the command builds a context file containing the transcript, metadata, and screenshot references, then prints a ready-to-paste prompt for your IDE agent (Cursor, Warp, Copilot, etc.):

```
─── Copy below ───

I have a Loom video walkthrough for a new feature...

docs-and-plans/loom/contexts/loom-plan-my-video-context.md
docs-and-plans/loom/screenshots/loom-plan-my-video-0010-reviewing-the-dashboard.jpg

─── End ───
```

**With `--ai`** — the command calls the AI directly and saves a complete Markdown plan:

```
🎬 Fetching Loom video data...
  ✓ Video: Dashboard Redesign Walkthrough
  ✓ Duration: 4m 32s
  ✓ Transcript: 847 words
📸 Capturing screenshots at 10s intervals...
  ✓ Captured 27 frames, 14 unique after dedup
🤖 Generating implementation plan with AI...

✅ Implementation plan saved to: docs-and-plans/loom/loom-plan-dashboard-redesign-walkthrough.md
```

### `loom:transcript` — Fetch a transcript

```bash
# Plain text transcript
php artisan loom:transcript https://www.loom.com/share/abc123...

# With timestamps
php artisan loom:transcript https://www.loom.com/share/abc123... --timestamps

# Raw JSON output
php artisan loom:transcript https://www.loom.com/share/abc123... --json
```

#### Options

| Option | Description | Default |
|---|---|---|
| `url` (argument) | Loom video URL | — (required) |
| `--timestamps` | Prefix each segment with `[M:SS]` | `false` |
| `--json` | Output raw JSON (title, duration, transcript, segments) | `false` |

## How It Works

### 1. Video data extraction (`LoomVideoService`)

The service tries multiple strategies in order:

1. **oEmbed API** — fetches title, thumbnail, and duration (no auth required)
2. **Page scraping** — loads the share page HTML and searches for transcript data in:
   - Apollo state (`__APOLLO_STATE__`)
   - Next.js data (`__NEXT_DATA__`)
   - Generic `<script>` tags containing video JSON
3. **Transcription API** — tries Loom's direct `/v1/videos/{id}/transcriptions` endpoint
4. **Playwright** (final fallback) — launches a headless browser, loads the page with full JS execution, and extracts the transcript from the rendered DOM or intercepted API calls

### 2. Screenshot capture (`LoomScreenshotService`)

When screenshots are enabled, a Node.js/Playwright script:

- Opens the Loom embed in a headless Chromium browser
- Seeks the video to each target timestamp and takes a screenshot
- Computes a perceptual hash (aHash) for each frame *in the browser*
- Deduplicates consecutive identical/near-identical frames (Hamming distance ≤ 5)
- Returns only unique frames as JPEG files

Screenshots are saved with contextual filenames derived from the nearest transcript segment:

```
loom-plan-BBCM-1234-0012-reviewing-the-dashboard.jpg
loom-plan-BBCM-1234-0047-clicking-the-save-button.jpg
```

### 3. Plan generation (`LoomPlanService`)

The service builds a structured prompt containing:
- Video metadata (title, duration, URL)
- Full timestamped transcript
- Screenshot metadata and the actual images (via Prism's vision/multimodal support)
- Your project's tech stack description

This is sent to the configured AI model, which returns a Markdown plan with sections for requirements, technical approach, database changes, affected files, acceptance criteria, risks, and open questions.

If the AI call fails, a **fallback plan** is generated containing the raw transcript and empty section stubs.

## Templates

The package ships with four prompt templates in `resources/templates/`:

| Template | Use case |
|---|---|
| `feature` | New feature implementation (default) |
| `bug` | Bug diagnosis and fix planning |
| `epic` | Breaking down a large epic into discrete tasks |
| `documentation` | Converting a walkthrough into admin-facing documentation |

### Customising templates

After publishing (`vendor:publish --tag=loom-planner-templates`), templates are copied to `resources/views/vendor/loom-planner/`. Each template receives two variables:

- `$planPath` — the file path where the plan will be saved
- `$screenshotLine` — a sentence about attached screenshots (empty string if none)

Example custom template:

```blade
Read the transcript below and create a migration plan for our Phoenix/Elixir app.{{ $screenshotLine }} Save the plan to `{{ $planPath }}`.
```

## Output Structure

All generated files are saved under the configured `output_dir` (default: `docs-and-plans/loom/`):

```
docs-and-plans/loom/
├── loom-plan-my-feature.md              # Generated plan (--ai mode)
├── contexts/
│   └── loom-plan-my-feature-context.md  # Transcript + metadata (prompt-only mode)
└── screenshots/
    ├── loom-plan-my-feature-0000-intro-and-overview.jpg
    ├── loom-plan-my-feature-0010-reviewing-the-dashboard.jpg
    └── ...
```

## Architecture

```
src/
├── LoomPlannerServiceProvider.php   # Config, commands, views registration
├── Commands/
│   ├── LoomPlanCommand.php          # loom:plan artisan command
│   └── LoomTranscriptCommand.php    # loom:transcript artisan command
└── Services/
    ├── LoomVideoService.php         # URL parsing, oEmbed, transcript extraction
    ├── LoomPlanService.php          # AI prompt building & plan generation
    └── LoomScreenshotService.php    # Playwright screenshot capture & dedup
```

## Testing

```bash
composer test
```

The test suite covers:

- **Unit tests** — service-level logic (URL parsing, prompt building, transcript normalisation, config)
- **Feature tests** — full command execution with mocked services

See [`tests/`](tests/) for the complete test suite.

## License

MIT
