# Changelog

All notable changes to `dan/ai-loom-planner` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - 2026-04-29

### Changed

- Default Anthropic model bumped from `claude-sonnet-4-20250514` to
  `claude-sonnet-4-6`. Consumers who set `LOOM_PLAN_AI_MODEL` (or
  `loom-planner.model` in a published config) are unaffected; everyone else
  picks up the newer model on next request.

## [1.0.2] - 2026-04-29

### Fixed

- Screenshots are now persisted under `<output_dir>/screenshots/` (a sibling
  of `contexts/`) as documented in the README, instead of being nested at
  `<output_dir>/contexts/screenshots/`.
- Each captured screenshot now carries a populated `label` derived from the
  transcript, so the AI prompt and the context manifest no longer report
  `"label": "unknown"` for every frame.
- Slug derivation no longer collapses to a single value when the transcript
  is returned as one big segment or when every segment has `ts: null`. In
  that case `generateSlugFromTranscript` falls back to a proportional word
  window from the concatenated transcript, so each screenshot gets a
  distinct, position-appropriate slug.

### Changed

- `LoomPlanCommand::generateSlugFromTranscript()` is now `public static` so
  it can be called directly from tests and external integrations. It also
  takes an optional `$duration` parameter to enable the proportional fallback.

## [1.0.1] - 2026-04-29

### Fixed

- Bundled Playwright helper scripts now use the `.cjs` extension
  (`loom-transcript.cjs`, `loom-screenshot.cjs`) so Node loads them as
  CommonJS even when the consumer project's `package.json` declares
  `"type": "module"`. Previously the `.js` files would fail with
  `ReferenceError: require is not defined in ES module scope` in any
  ESM-typed Laravel project.

## [1.0.0] - 2026-04-29

### Added

- Initial public release.
- `loom:plan` artisan command — generates AI-powered implementation plans from a
  Loom video URL, with optional screenshot capture and configurable prompt
  templates (`feature`, `bug`, `epic`, `documentation`).
- `loom:transcript` artisan command — fetches and prints a Loom transcript
  (plain text, timestamped, or JSON).
- `LoomVideoService` — video metadata + transcript extraction with cascading
  strategies (oEmbed, Apollo / Next.js HTML scraping, transcription API,
  Playwright fallback).
- `LoomScreenshotService` — Playwright-based screenshot capture with
  perceptual-hash deduplication.
- `LoomPlanService` — Prism-backed plan generation with vision support and
  fallback plan generation on AI failure.
- Publishable config (`loom-planner-config`) and prompt templates
  (`loom-planner-templates`).
- Laravel 11.x, 12.x, and 13.x support.
- PHPUnit test suite with Orchestra Testbench.

[1.0.3]: https://github.com/DataAndNumbersOrganization/ai-loom-plan/releases/tag/v1.0.3
[1.0.2]: https://github.com/DataAndNumbersOrganization/ai-loom-plan/releases/tag/v1.0.2
[1.0.1]: https://github.com/DataAndNumbersOrganization/ai-loom-plan/releases/tag/v1.0.1
[1.0.0]: https://github.com/DataAndNumbersOrganization/ai-loom-plan/releases/tag/v1.0.0
