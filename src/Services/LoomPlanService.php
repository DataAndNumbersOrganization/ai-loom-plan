<?php

namespace Dan\AiLoomPlanner\Services;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

class LoomPlanService
{
    protected string $model;
    protected string $provider;
    protected string $appName;
    protected string $techStack;

    public function __construct()
    {
        $this->model = config('loom-planner.model', 'claude-sonnet-4-20250514');
        $this->provider = config('loom-planner.provider', 'anthropic');
        $this->appName = config('loom-planner.app_name') ?? config('app.name', 'MyApp');
        $this->techStack = config('loom-planner.tech_stack') ?? $this->defaultTechStack();
    }

    /**
     * Generate an implementation plan from Loom video data.
     *
     * @param  array<int, array{path: string, timestamp: int, label: string, formatted_time: string}>  $screenshots
     */
    public function generatePlan(array $loomData, array $screenshots = []): string
    {
        $context = $this->formatContext($loomData, $screenshots);
        $prompt = $this->buildPrompt($context);
        $additionalContent = $this->buildImageContent($screenshots);

        try {
            $maxTokens = (int) config('loom-planner.max_tokens', 8000);

            $response = Prism::text()
                ->using($this->provider, $this->model)
                ->withPrompt($prompt, $additionalContent)
                ->withMaxTokens($maxTokens)
                ->generate();

            return trim($response->text);
        } catch (\Throwable $e) {
            Log::error('Failed to generate Loom implementation plan', ['error' => $e->getMessage()]);

            return $this->generateFallbackPlan($context);
        }
    }

    /**
     * @return Image[]
     */
    protected function buildImageContent(array $screenshots): array
    {
        $images = [];

        foreach ($screenshots as $screenshot) {
            if (!empty($screenshot['path']) && file_exists($screenshot['path'])) {
                try {
                    $images[] = Image::fromLocalPath($screenshot['path']);
                } catch (\Throwable $e) {
                    Log::warning('Could not load screenshot for AI vision', [
                        'path' => $screenshot['path'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $images;
    }

    protected function defaultTechStack(): string
    {
        return implode("\n", [
            '- **Backend**: Laravel (PHP) with Filament admin panel',
            '- **Frontend**: Next.js (React/TypeScript) merchant-facing app',
            '- **Database**: MySQL',
            '- **Queue**: Laravel Horizon / Redis',
            '- **APIs**: REST + internal service layer',
        ]);
    }

    /**
     * Build the full prompt text for external use (prompt-only mode).
     *
     * @param  array<int, array{path: string, timestamp: int, label: string, formatted_time: string}>  $screenshots
     */
    public function buildPromptForOutput(array $loomData, array $screenshots = []): string
    {
        $context = $this->formatContext($loomData, $screenshots);

        return $this->buildPrompt($context);
    }

    /**
     * @param  array<int, array{path: string, timestamp: int, label: string, formatted_time: string}>  $screenshots
     */
    protected function formatContext(array $loomData, array $screenshots = []): array
    {
        $context = [
            'loom' => [
                'id' => $loomData['id'] ?? 'N/A',
                'title' => $loomData['title'] ?? 'Untitled',
                'duration' => $loomData['duration'] ?? null,
                'duration_formatted' => LoomVideoService::formatDuration($loomData['duration'] ?? null),
                'url' => $loomData['url'] ?? '',
                'has_transcript' => !empty($loomData['transcript_text']),
            ],
        ];

        if (!empty($loomData['transcript_text'])) {
            $context['loom']['transcript'] = $this->truncateText($loomData['transcript_text'], 12000);
        }

        if (!empty($loomData['transcript_segments'])) {
            $context['loom']['segments_count'] = count($loomData['transcript_segments']);
            $context['loom']['timestamped_transcript'] = $this->formatTimestampedTranscript(
                $loomData['transcript_segments']
            );
        }

        if (!empty($screenshots)) {
            $context['loom']['screenshots'] = array_map(function ($s) {
                return [
                    'label' => $s['label'] ?? 'unknown',
                    'timestamp' => $s['formatted_time'] ?? ($s['timestamp'] . 's'),
                ];
            }, $screenshots);
            $context['loom']['screenshot_count'] = count($screenshots);
        }

        return $context;
    }

    protected function buildPrompt(array $context): string
    {
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $appName = $this->appName;
        $techStack = $this->techStack;

        return <<<PROMPT
You are a senior software engineer and technical lead analyzing a Loom video walkthrough to create a comprehensive implementation plan.

## Context
{$contextJson}

The video is from the **{$appName}** team. The tech stack is:
{$techStack}

Generate a detailed implementation plan in Markdown format with the following structure:

# Implementation Plan: [Brief Title Based on Video Content]

## 📋 Summary
Brief overview of what was discussed/demonstrated in the video (2-3 sentences max).

## 🎯 Requirements
What features, changes, or fixes were described? List each requirement clearly.

## 🏗️ Technical Approach
Break down the implementation by layer/area. For each area, list:
- Specific files, classes, or modules that need to be created or modified
- Database/schema changes needed
- API endpoints to add or modify
- Background jobs or async work if applicable
- UI/frontend components affected

## 🗄️ Database Changes
- New tables or columns needed
- Migration details
- Index considerations

## 📁 Affected Files
List the specific files that will likely need changes, grouped by area (models, services, controllers, migrations, frontend, tests, etc.).

## ✅ Acceptance Criteria
Clear, testable criteria derived from what was shown in the video.

## ⚠️ Risks & Considerations
- Performance implications
- Breaking changes
- Migration concerns
- Dependencies on other features

## ❓ Open Questions
Things that weren't clear from the video or need further clarification.

---

**Guidelines**:
1. Be SPECIFIC — reference actual file paths and class names where possible
2. If the transcript mentions specific features, buttons, or UI elements, capture them precisely
3. Think about the full stack — what needs to change from database to UI
4. Consider edge cases and error handling
5. If this is a bug fix, describe both the root cause and the fix
6. Include any ticket/issue references mentioned in the video
7. Format Loom references as: [Loom Video](actual-loom-url)
8. Prioritize items from most critical to least critical
9. Keep it actionable — another developer should be able to pick this up and start building

Focus on QUALITY and ACCURACY — extract as much detail as possible from the transcript.

{$this->buildScreenshotGuidelines($context)}
PROMPT;
    }

    protected function buildScreenshotGuidelines(array $context): string
    {
        if (empty($context['loom']['screenshots'])) {
            return '';
        }

        $count = $context['loom']['screenshot_count'];
        $labels = collect($context['loom']['screenshots'])
            ->map(fn ($s) => "- {$s['label']} ({$s['timestamp']})")
            ->implode("\n");

        return <<<GUIDELINES

**SCREENSHOTS**: {$count} screenshot(s) from the video are attached as images. These show what the presenter was demonstrating at key moments:
{$labels}

Use these screenshots to:
- Identify specific UI elements, buttons, menus, or screens being discussed
- Note the layout, design patterns, or visual structure
- Capture any on-screen text, labels, error messages, or data shown
- Cross-reference what's said in the transcript with what's visible on screen
GUIDELINES;
    }

    protected function formatTimestampedTranscript(array $segments): string
    {
        $lines = [];

        foreach ($segments as $segment) {
            $ts = $segment['ts'] ?? null;
            $text = $segment['text'] ?? '';

            if (!$text) {
                continue;
            }

            if ($ts !== null) {
                $minutes = floor($ts / 60);
                $seconds = $ts % 60;
                $timestamp = sprintf('[%d:%02d]', $minutes, $seconds);
                $lines[] = "{$timestamp} {$text}";
            } else {
                $lines[] = $text;
            }
        }

        $formatted = implode("\n", $lines);

        return $this->truncateText($formatted, 10000);
    }

    protected function generateFallbackPlan(array $context): string
    {
        $title = $context['loom']['title'] ?? 'Unknown';
        $url = $context['loom']['url'] ?? '#';
        $duration = $context['loom']['duration_formatted'] ?? 'Unknown';

        $plan = "# Implementation Plan: {$title}\n\n";
        $plan .= "## 📋 Summary\n";
        $plan .= "Implementation plan based on [Loom Video]({$url}) (Duration: {$duration})";
        $plan .= "\n\n";

        if (!empty($context['loom']['transcript'])) {
            $plan .= "## 📝 Video Transcript\n";
            $plan .= $context['loom']['transcript'] . "\n\n";
        }

        $plan .= "## 🎯 Requirements\n";
        $plan .= "*To be defined after reviewing the video*\n\n";
        $plan .= "## 🏗️ Technical Approach\n";
        $plan .= "*To be defined based on requirements*\n\n";
        $plan .= "## 📁 Affected Files\n";
        $plan .= "*To be determined*\n\n";
        $plan .= "## ✅ Acceptance Criteria\n";
        $plan .= "*To be defined*\n\n";
        $plan .= "---\n\n";
        $plan .= "*Note: AI generation failed. This plan includes the raw transcript and requires manual completion.*\n";

        return $plan;
    }

    protected function truncateText(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }
}
