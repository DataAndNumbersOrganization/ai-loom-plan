# AI Loom Planner

Generate ready-to-use AI agent prompts from Loom video walkthroughs — right from the command line.

This Laravel package extracts a Loom transcript and screenshots, builds a structured context file, and prints a copy-pastable prompt for your preferred AI agent (Warp, Cursor, Copilot, etc.) to turn into an implementation plan.

## Features

- **Transcript extraction** — pulls Loom transcripts via oEmbed, page scraping, API fallback, and Playwright (automatic cascade)
- **Screenshot capture** — grabs frames at configurable intervals with perceptual-hash deduplication
- **Prompt building** — assembles a structured context file with transcript, metadata, and screenshot references
- **Prompt templates** — ships with `feature`, `bug`, `epic`, and `documentation` templates; fully customisable via publish
- **Two commands** — `loom:plan` for context + prompt output; `loom:transcript` for quick transcript access

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2+ |
| Laravel | 11.x, 12.x, or 13.x |
| Node.js | 18+ (for Playwright scripts) |
| Playwright | `@playwright/test` installed in your project |

No AI provider or API key is required — the command builds the context and prints a prompt for you to paste into your preferred AI agent (Warp, Cursor, Copilot, etc.).

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
    | Where generated plans and screenshots are saved.
    |
    */
    'output_dir' => env('LOOM_PLAN_OUTPUT_DIR', 'docs-and-plans/loom'),

    /*
    |--------------------------------------------------------------------------
    | Templates Directory
    |--------------------------------------------------------------------------
    |
    | Path to a directory containing prompt Blade templates.
    | Leave as null to use the package's bundled templates.
    | If you publish templates with `vendor:publish --tag=loom-planner-templates`,
    | set this to `resource_path('views/vendor/loom-planner')` to load them.
    |
    */
    'templates_dir' => null,
];
```

### Environment variables

```env
# Optional overrides
LOOM_PLAN_OUTPUT_DIR=docs-and-plans/loom
LOOM_PLAN_TECH_STACK="- **Backend**: Laravel\n- **Frontend**: React"
LOOM_PLAN_APP_NAME=MyApp
```

## Usage

### `loom:plan` — Generate an implementation plan

```bash
# Fetch transcript, capture screenshots, and output a copy-pastable agent prompt
php artisan loom:plan https://www.loom.com/share/abc123def456...

# Capture screenshots every 5 seconds (default: 10)
php artisan loom:plan https://www.loom.com/share/abc123... --screenshots=5

# Disable screenshots entirely
php artisan loom:plan https://www.loom.com/share/abc123... --screenshots=0

# Use the "bug" plan template
php artisan loom:plan https://www.loom.com/share/abc123... --template=bug

# Custom output filename
php artisan loom:plan https://www.loom.com/share/abc123... --output=my-feature-plan
```

#### Options

| Option | Description | Default |
|---|---|---|
| `url` (argument) | Loom video URL | — (required) |
| `--screenshots` | Seconds between screenshot captures (1–60, 0 to disable) | `10` |
| `--template` | Plan template: `feature`, `bug`, `epic`, or `documentation` | `feature` |
| `--output` | Custom output filename | Auto-generated from video title |

#### Workflow

The command fetches the transcript, captures screenshots, writes a context file, and prints a copy-pastable agent prompt:

```
🎬 Fetching Loom video data...
  ✓ Video: Dashboard Redesign Walkthrough
  ✓ Duration: 4m 32s
  ✓ Transcript: 847 words
📸 Capturing screenshots at 10s intervals...
  ✓ Captured 27 frames, 14 unique after dedup

─── Copy below ───

I have a Loom video walkthrough for a new feature I need to implement...

docs-and-plans/loom/contexts/loom-plan-dashboard-redesign-walkthrough-context.md
docs-and-plans/loom/screenshots/loom-plan-dashboard-redesign-walkthrough-0010-reviewing-the-dashboard.jpg

─── End ───
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

### 3. Context + prompt output (`LoomPlanService`)

The service assembles a structured context prompt containing:
- Video metadata (title, duration, URL)
- Full timestamped transcript
- Screenshot labels and timestamps
- Your project's tech stack description

This is saved as a context markdown file and its path is printed alongside a ready-to-paste agent instruction. Paste both into your AI agent of choice to generate the plan.

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

- `$planPath` — the file path where the plan should be saved
- `$screenshotLine` — a sentence about attached screenshots (empty string if none)

Example custom template:

```blade
Read the transcript below and create a migration plan for our Phoenix/Elixir app.{{ $screenshotLine }} Save the plan to `{{ $planPath }}`.
```

## Output Structure

All generated files are saved under the configured `output_dir` (default: `docs-and-plans/loom/`):

```
docs-and-plans/loom/
├── contexts/
│   └── loom-plan-my-feature-context.md      # Transcript + metadata (attach to AI agent)
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
    ├── LoomPlanService.php          # Context/prompt building
    └── LoomScreenshotService.php    # Playwright screenshot capture & dedup
```

The Playwright helper scripts (`resources/scripts/loom-transcript.cjs` and
`loom-screenshot.cjs`) intentionally use the `.cjs` extension so Node loads
them as CommonJS even when the consumer project's `package.json` declares
`"type": "module"`. See [Troubleshooting](#troubleshooting) for context.

## Troubleshooting

### `ReferenceError: require is not defined in ES module scope`

Fixed in **v1.0.1**. If you are pinned to `v1.0.0` and your project's
`package.json` declares `"type": "module"`, the bundled `.js` Playwright scripts
will fail with this error because Node treats every `.js` file as ESM. Bump to
`^1.0.1` (`composer update dan/ai-loom-planner`) — the scripts now ship as
`.cjs` so Node always parses them as CommonJS.

### `Cannot find module '@playwright/test'`

Install Playwright in your consuming project (the package looks up
`@playwright/test` via `NODE_PATH=<your-project>/node_modules`):

```bash
npm install @playwright/test
npx playwright install chromium
```

This is only required if you use `--screenshots` or hit the Playwright
transcript fallback.

### `node not found — cannot run Playwright transcript extraction`

The package looks for `node` via `which node`, then falls back to
`/usr/local/bin/node`, `/opt/homebrew/bin/node`, and `/usr/bin/node`. If your
Node.js binary is somewhere else (e.g. nvm-managed), make sure that path is on
the PHP process's `PATH` (Laravel Herd / valet / Octane workers may have a
different `PATH` from your interactive shell).

## Testing

```bash
composer test
```

The test suite covers:

- **Unit tests** — service-level logic (URL parsing, prompt building, transcript normalisation, config)
- **Feature tests** — full command execution with mocked services

See [`tests/`](tests/) for the complete test suite.

## Versioning

This package follows [Semantic Versioning](https://semver.org/). The public API
surface — artisan command signatures, service class signatures, config keys,
and publish tags — is covered by semver from `v1.0.0` onwards. See
[`CHANGELOG.md`](CHANGELOG.md) for release notes.

## License

MIT — see [`LICENSE`](LICENSE) for the full text.
