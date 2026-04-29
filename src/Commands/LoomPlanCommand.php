<?php

namespace Dan\AiLoomPlanner\Commands;

use Dan\AiLoomPlanner\LoomPlannerServiceProvider;
use Dan\AiLoomPlanner\Services\LoomPlanService;
use Dan\AiLoomPlanner\Services\LoomScreenshotService;
use Dan\AiLoomPlanner\Services\LoomVideoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LoomPlanCommand extends Command
{
    protected $signature = 'loom:plan
                            {url : Loom video URL (e.g., https://www.loom.com/share/abc123...)}
                            {--screenshots=10 : Seconds between screenshot captures (1-60, 0 to disable)}
                            {--template=feature : Prompt template — feature, bug, epic, or documentation}
                            {--ai : Send prompt to AI and save the generated plan (default: output prompt only)}
                            {--output= : Custom output filename (defaults to video title based name)}';

    protected $description = 'Generate an AI-powered implementation plan from a Loom video walkthrough';

    public function __construct(
        protected LoomVideoService $loomVideoService,
        protected LoomPlanService $loomPlanService,
        protected LoomScreenshotService $loomScreenshotService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = $this->argument('url');
        $screenshotInterval = max(0, min(60, (int) ($this->option('screenshots') ?? 10)));
        $sendToAi = $this->option('ai');
        $customOutput = $this->option('output');

        if (!$this->loomVideoService->extractVideoId($url)) {
            $this->error('Invalid Loom URL. Expected format: https://www.loom.com/share/{video-id}');

            return 1;
        }

        $this->info('🎬 Fetching Loom video data...');

        try {
            $loomData = $this->loomVideoService->extract($url);
        } catch (\Throwable $e) {
            $this->error("Failed to fetch Loom video: {$e->getMessage()}");

            return 1;
        }

        $this->info("  ✓ Video: {$loomData['title']}");
        $this->info('  ✓ Duration: ' . LoomVideoService::formatDuration($loomData['duration']));

        if ($loomData['transcript_text']) {
            $wordCount = str_word_count($loomData['transcript_text']);
            $this->info("  ✓ Transcript: {$wordCount} words");
        } else {
            $this->warn('  ⚠ No transcript found — plan will be generated from metadata only');
        }

        if ($loomData['thumbnail_path']) {
            $this->info('  ✓ Thumbnail downloaded');
        }

        // Capture screenshots
        $screenshots = [];
        if ($screenshotInterval > 0) {
            $this->info("📸 Capturing screenshots at {$screenshotInterval}s intervals...");
            $rawScreenshots = $this->loomScreenshotService->capture(
                $loomData['id'],
                $loomData['duration'],
                $screenshotInterval
            );

            $estimatedFrames = $loomData['duration'] ? (int) ceil($loomData['duration'] / $screenshotInterval) : 0;
            $uniqueCount = count($rawScreenshots);

            if ($uniqueCount > 0) {
                $this->info("  ✓ Captured {$estimatedFrames} frames, {$uniqueCount} unique after dedup");
                $screenshots = $rawScreenshots;
            } else {
                $this->warn('  ⚠ No screenshots captured — continuing without them');
            }
        }

        // Ensure output directory exists
        $configDir = config('loom-planner.output_dir', 'docs-and-plans/loom');
        $outputDir = str_starts_with($configDir, '/') ? $configDir : base_path($configDir);
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $filename = $this->determineFilename($customOutput, $loomData['title']);

        // Ensure contexts directory exists
        $contextsDir = "{$outputDir}/contexts";
        if (!File::isDirectory($contextsDir)) {
            File::makeDirectory($contextsDir, 0755, true);
        }

        // Persist screenshots as a sibling of contexts/ (per README spec).
        if (!empty($screenshots)) {
            $screenshots = $this->persistScreenshots(
                $screenshots,
                $outputDir,
                $filename,
                $loomData['transcript_segments'] ?? [],
                $loomData['duration'] ?? null,
            );
        }

        // Generate plan or output prompt
        if ($sendToAi) {
            if (!empty($screenshots)) {
                ini_set('memory_limit', '512M');
            }

            $this->info('🤖 Generating implementation plan with AI...');
            $plan = $this->loomPlanService->generatePlan($loomData, $screenshots);

            $outputPath = "{$outputDir}/{$filename}";
            File::put($outputPath, $plan);

            $this->newLine();
            $this->info("✅ Implementation plan saved to: {$outputPath}");
            $this->newLine();

            $this->displaySummary($loomData, $outputPath, $screenshots);
        } else {
            $contextMarkdown = $this->loomPlanService->buildPromptForOutput($loomData, $screenshots);
            $contextFilename = preg_replace('/\.md$/', '-context.md', $filename);
            $contextPath = "{$contextsDir}/{$contextFilename}";
            File::put($contextPath, $contextMarkdown);

            $template = $this->option('template');
            $planPath = "{$outputDir}/{$filename}";
            $this->outputAgentPrompt($template, $contextPath, $planPath, $screenshots);
        }

        return 0;
    }

    protected function determineFilename(?string $custom, string $title): string
    {
        if ($custom) {
            return str_ends_with($custom, '.md') ? $custom : "{$custom}.md";
        }

        $slug = Str::slug($title);

        return "loom-plan-{$slug}.md";
    }

    /**
     * @return array<int, array{path: string, timestamp: int, hash: string|null, formatted_time: string, label: string}>
     */
    protected function persistScreenshots(
        array $screenshots,
        string $outputDir,
        string $planFilename,
        array $transcriptSegments = [],
        ?int $duration = null,
    ): array {
        $screenshotDir = "{$outputDir}/screenshots";
        if (!File::isDirectory($screenshotDir)) {
            File::makeDirectory($screenshotDir, 0755, true);
        }

        $baseName = preg_replace('/\.md$/', '', $planFilename);
        $persisted = [];

        foreach ($screenshots as $screenshot) {
            if (empty($screenshot['path']) || !file_exists($screenshot['path'])) {
                continue;
            }

            $timestamp = $screenshot['timestamp'] ?? 0;
            $secondsPadded = str_pad($timestamp, 4, '0', STR_PAD_LEFT);

            $slug = static::generateSlugFromTranscript($timestamp, $transcriptSegments, $duration);

            $ext = pathinfo($screenshot['path'], PATHINFO_EXTENSION) ?: 'jpg';
            $destFilename = "{$baseName}-{$secondsPadded}-{$slug}.{$ext}";
            $destPath = "{$screenshotDir}/{$destFilename}";

            File::copy($screenshot['path'], $destPath);

            @unlink($screenshot['path']);
            $screenshot['path'] = $destPath;
            $screenshot['label'] = $slug;
            $persisted[] = $screenshot;
        }

        return $persisted;
    }

    /**
     * Derive a short slug describing what the presenter was saying near the
     * given timestamp. Falls back to a proportional word window from the
     * concatenated transcript when timestamp metadata is sparse (e.g. the
     * transcript was returned as a single segment, or all segments share
     * `ts: null`).
     */
    public static function generateSlugFromTranscript(
        int $timestamp,
        array $transcriptSegments,
        ?int $duration = null,
    ): string {
        if (empty($transcriptSegments)) {
            return 'frame';
        }

        $tsValues = array_filter(
            array_map(fn ($s) => $s['ts'] ?? null, $transcriptSegments),
            fn ($v) => $v !== null,
        );
        $hasUsefulTs = count(array_unique($tsValues)) > 1;

        if ($hasUsefulTs) {
            $closestSegment = null;
            $minDistance = PHP_INT_MAX;

            foreach ($transcriptSegments as $segment) {
                $segmentTs = $segment['ts'] ?? 0;
                $distance = abs($segmentTs - $timestamp);

                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $closestSegment = $segment;
                }
            }

            if ($closestSegment && !empty($closestSegment['text'])) {
                return static::slugFromWords($closestSegment['text']);
            }
        }

        // Fallback: proportional word window from the concatenated transcript.
        $allText = trim(implode(' ', array_filter(array_column($transcriptSegments, 'text'))));
        if ($allText === '') {
            return 'frame';
        }

        $words = str_word_count($allText, 1);
        if (empty($words)) {
            return 'frame';
        }

        $totalWords = count($words);
        $windowSize = min(6, $totalWords);
        $maxStart = max(0, $totalWords - $windowSize);
        $ratio = ($duration && $duration > 0)
            ? max(0.0, min($timestamp / $duration, 1.0))
            : 0.0;
        $startIndex = (int) floor($ratio * $maxStart);
        $window = array_slice($words, $startIndex, $windowSize);

        return Str::slug(implode(' ', $window)) ?: 'frame';
    }

    protected static function slugFromWords(string $text): string
    {
        $words = str_word_count($text, 1);
        $shortText = implode(' ', array_slice($words, 0, 6));

        return Str::slug($shortText) ?: 'frame';
    }

    protected function outputAgentPrompt(string $template, string $contextPath, string $planPath, array $screenshots): void
    {
        $screenshotLine = !empty($screenshots)
            ? ' Screenshots from key moments in the video are attached below for visual context.'
            : '';

        $prompt = $this->renderTemplate($template, [
            'planPath' => $planPath,
            'screenshotLine' => $screenshotLine,
        ]);

        $this->newLine();
        $this->line('─── Copy below ───');
        $this->newLine();
        $this->line(trim($prompt));
        $this->newLine();
        $this->line($contextPath);
        foreach ($screenshots as $s) {
            $this->line($s['path']);
        }
        $this->newLine();
        $this->line('─── End ───');
    }

    protected function renderTemplate(string $template, array $data): string
    {
        // Check config override first, then package templates
        $templatesDir = config('loom-planner.templates_dir');

        if ($templatesDir && file_exists("{$templatesDir}/{$template}.blade.php")) {
            $templatePath = "{$templatesDir}/{$template}.blade.php";
        } else {
            $templatePath = LoomPlannerServiceProvider::packageResourcePath("templates/{$template}.blade.php");
        }

        if (!file_exists($templatePath)) {
            $this->warn("Template '{$template}' not found at {$templatePath}, falling back to 'feature'");
            $templatePath = LoomPlannerServiceProvider::packageResourcePath('templates/feature.blade.php');
        }

        if (!file_exists($templatePath)) {
            return "Read the transcript and context file below, then build a detailed implementation plan.{$data['screenshotLine']} Save the implementation plan to `{$data['planPath']}`.";
        }

        return Blade::render(file_get_contents($templatePath), $data);
    }

    protected function displaySummary(array $loomData, string $outputPath, array $screenshots = []): void
    {
        $this->line('📊 <fg=cyan>Summary</>');
        $this->line('─────────────────────────────────────');
        $this->line("  Video: <fg=yellow>{$loomData['title']}</>");
        $this->line('  Duration: ' . LoomVideoService::formatDuration($loomData['duration']));
        $this->line("  URL: {$loomData['url']}");

        if ($loomData['transcript_text']) {
            $wordCount = str_word_count($loomData['transcript_text']);
            $this->line("  Transcript: <fg=green>{$wordCount} words</>");
        } else {
            $this->line('  Transcript: <fg=red>Not available</>');
        }

        if (!empty($screenshots)) {
            $this->line('  Screenshots: <fg=green>' . count($screenshots) . ' captured</>');
        }

        $this->line('─────────────────────────────────────');
        $this->line("  File: <fg=green>{$outputPath}</>");
        $this->newLine();
    }
}
