<?php

namespace Dan\AiLoomPlanner\Tests\Feature\Commands;

use Dan\AiLoomPlanner\Services\LoomPlanService;
use Dan\AiLoomPlanner\Services\LoomScreenshotService;
use Dan\AiLoomPlanner\Services\LoomVideoService;
use Dan\AiLoomPlanner\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

class LoomPlanCommandTest extends TestCase
{
    protected string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/loom-plan-test-' . uniqid();
        mkdir($this->outputDir, 0755, true);
        $this->app['config']->set('loom-planner.output_dir', $this->outputDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);
        parent::tearDown();
    }

    protected function mockVideoService(array $loomData = []): void
    {
        $loomData = array_merge($this->fakeLoomData(), $loomData);

        $mock = Mockery::mock(LoomVideoService::class);
        $mock->shouldReceive('extractVideoId')->andReturn($loomData['id']);
        $mock->shouldReceive('extract')->andReturn($loomData);

        $this->app->instance(LoomVideoService::class, $mock);
    }

    protected function mockScreenshotService(array $screenshots = []): void
    {
        $mock = Mockery::mock(LoomScreenshotService::class);
        $mock->shouldReceive('capture')->andReturn($screenshots);

        $this->app->instance(LoomScreenshotService::class, $mock);
    }

    protected function mockPlanService(string $plan = '# Implementation Plan: Test'): void
    {
        $mock = Mockery::mock(LoomPlanService::class);
        $mock->shouldReceive('generatePlan')->andReturn($plan);
        $this->app->instance(LoomPlanService::class, $mock);
    }

    // ─── Validation ────────────────────────────────────────────────

    /** @test */
    public function it_rejects_invalid_loom_url(): void
    {
        $invalidVideoService = Mockery::mock(LoomVideoService::class);
        $invalidVideoService->shouldReceive('extractVideoId')->andReturn(null);
        $this->app->instance(LoomVideoService::class, $invalidVideoService);

        $this->artisan('loom:plan', ['url' => 'https://youtube.com/watch?v=abc'])
            ->expectsOutputToContain('Invalid Loom URL')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_rejects_empty_url(): void
    {
        $invalidVideoService = Mockery::mock(LoomVideoService::class);
        $invalidVideoService->shouldReceive('extractVideoId')->andReturn(null);
        $this->app->instance(LoomVideoService::class, $invalidVideoService);

        $this->artisan('loom:plan', ['url' => 'not-a-url'])
            ->expectsOutputToContain('Invalid Loom URL')
            ->assertExitCode(1);
    }

    // ─── Plan Generation ───────────────────────────────────────────

    /** @test */
    public function it_generates_and_saves_plan(): void
    {
        $this->mockVideoService();
        $this->mockScreenshotService();

        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')
            ->once()
            ->andReturn('# Implementation Plan: Test Feature');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '0',
        ])
            ->expectsOutputToContain('Generating implementation plan with AI')
            ->expectsOutputToContain('Implementation plan saved')
            ->assertExitCode(0);

        $planFiles = glob("{$this->outputDir}/loom-plan-*.md");
        $this->assertNotEmpty($planFiles, 'A plan file should be saved');
        $this->assertStringContainsString('# Implementation Plan', file_get_contents($planFiles[0]));
    }

    // ─── Screenshots ───────────────────────────────────────────────

    /** @test */
    public function it_captures_screenshots_at_specified_interval(): void
    {
        $this->mockVideoService();
        $this->mockPlanService();

        $screenshotMock = Mockery::mock(LoomScreenshotService::class);
        $screenshotMock->shouldReceive('capture')
            ->withArgs(function ($videoId, $duration, $interval) {
                return $interval === 5;
            })
            ->once()
            ->andReturn([]);
        $this->app->instance(LoomScreenshotService::class, $screenshotMock);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '5',
        ])->assertExitCode(0);
    }

    /** @test */
    public function it_skips_screenshots_when_interval_is_zero(): void
    {
        $this->mockVideoService();
        $this->mockPlanService();

        $screenshotMock = Mockery::mock(LoomScreenshotService::class);
        $screenshotMock->shouldNotReceive('capture');
        $this->app->instance(LoomScreenshotService::class, $screenshotMock);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '0',
        ])->assertExitCode(0);
    }

    /** @test */
    public function it_clamps_screenshot_interval_to_valid_range(): void
    {
        $this->mockVideoService();
        $this->mockPlanService();

        $screenshotMock = Mockery::mock(LoomScreenshotService::class);
        $screenshotMock->shouldReceive('capture')
            ->withArgs(function ($videoId, $duration, $interval) {
                // Values above 60 should be clamped to 60
                return $interval === 60;
            })
            ->once()
            ->andReturn([]);
        $this->app->instance(LoomScreenshotService::class, $screenshotMock);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '999',
        ])->assertExitCode(0);
    }

    // ─── Custom Output ─────────────────────────────────────────────

    /** @test */
    public function it_uses_custom_output_filename(): void
    {
        $this->mockVideoService();
        $this->mockScreenshotService();

        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')->andReturn('# Plan');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--output' => 'my-custom-plan',
            '--screenshots' => '0',
        ])->assertExitCode(0);

        $this->assertFileExists("{$this->outputDir}/my-custom-plan.md");
    }

    /** @test */
    public function it_appends_md_extension_if_missing(): void
    {
        $this->mockVideoService();
        $this->mockScreenshotService();

        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')->andReturn('# Plan');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--output' => 'already-has.md',
            '--screenshots' => '0',
        ])->assertExitCode(0);

        // Should not double the extension
        $this->assertFileExists("{$this->outputDir}/already-has.md");
        $this->assertFileDoesNotExist("{$this->outputDir}/already-has.md.md");
    }

    /** @test */
    public function it_auto_generates_filename_from_video_title(): void
    {
        $this->mockVideoService(['title' => 'Dashboard Redesign Walkthrough']);
        $this->mockScreenshotService();

        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')->andReturn('# Plan');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '0',
        ])->assertExitCode(0);

        $this->assertFileExists("{$this->outputDir}/loom-plan-dashboard-redesign-walkthrough.md");
    }

    // ─── Template Selection ────────────────────────────────────────

    /** @test */
    public function it_passes_template_to_plan_service(): void
    {
        $this->mockVideoService();
        $this->mockScreenshotService();

        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')
            ->withArgs(function ($loomData, $screenshots, $template) {
                return $template === 'bug';
            })
            ->once()
            ->andReturn('# Bug Fix Plan');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--template' => 'bug',
            '--screenshots' => '0',
        ])->assertExitCode(0);
    }

    // ─── No Transcript Warning ─────────────────────────────────────

    /** @test */
    public function it_warns_when_no_transcript_is_found(): void
    {
        $this->mockVideoService([
            'transcript_text' => null,
            'transcript_segments' => [],
        ]);
        $this->mockScreenshotService();
        $this->mockPlanService();

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '0',
        ])
            ->expectsOutputToContain('No transcript found')
            ->assertExitCode(0);
    }

    // ─── Video Extraction Failure ──────────────────────────────────

    /** @test */
    public function it_exits_with_error_when_video_extraction_fails(): void
    {
        $mock = Mockery::mock(LoomVideoService::class);
        $mock->shouldReceive('extractVideoId')->andReturn('abc123');
        $mock->shouldReceive('extract')->andThrow(new \RuntimeException('Network timeout'));
        $this->app->instance(LoomVideoService::class, $mock);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('Failed to fetch Loom video')
            ->assertExitCode(1);
    }

    // ─── Screenshot persistence ─────────────────────────────────────

    /**
     * Build N temporary JPEG files at evenly-spaced timestamps and return them
     * in the shape LoomScreenshotService::capture() produces.
     */
    protected function fakeCapturedScreenshots(int $count, int $intervalSecs = 10): array
    {
        $tmpDir = sys_get_temp_dir() . '/loom-plan-screenshots-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $screenshots = [];
        for ($i = 0; $i < $count; $i++) {
            $ts = $i * $intervalSecs;
            $path = "{$tmpDir}/raw-{$ts}.jpg";
            file_put_contents($path, 'fake-jpeg-bytes');
            $screenshots[] = [
                'path' => $path,
                'timestamp' => $ts,
                'hash' => null,
                'formatted_time' => sprintf('%dm%02ds', floor($ts / 60), $ts % 60),
            ];
        }

        return $screenshots;
    }

    /** @test */
    public function it_persists_screenshots_to_screenshots_dir(): void
    {
        $this->mockVideoService([
            'duration' => 120,
            'transcript_segments' => [
                ['text' => 'one two three four five six seven eight nine ten eleven twelve', 'ts' => 0],
            ],
        ]);
        $this->mockScreenshotService($this->fakeCapturedScreenshots(3));
        $this->mockPlanService();

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '10',
        ])->assertExitCode(0);

        $this->assertDirectoryExists("{$this->outputDir}/screenshots");
        $this->assertNotEmpty(glob("{$this->outputDir}/screenshots/*.jpg"));
    }

    /** @test */
    public function it_assigns_meaningful_labels_to_screenshots(): void
    {
        $this->mockVideoService([
            'duration' => 120,
            'transcript_segments' => [
                ['text' => 'opening the dashboard now', 'ts' => 0],
                ['text' => 'clicking the save button', 'ts' => 60],
                ['text' => 'final review of validation errors', 'ts' => 110],
            ],
        ]);
        $this->mockScreenshotService($this->fakeCapturedScreenshots(3));

        $capturedScreenshots = null;
        $planService = Mockery::mock(LoomPlanService::class);
        $planService->shouldReceive('generatePlan')
            ->withArgs(function ($loomData, $screenshots, $template) use (&$capturedScreenshots) {
                $capturedScreenshots = $screenshots;
                return true;
            })
            ->andReturn('# Plan');
        $this->app->instance(LoomPlanService::class, $planService);

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '10',
        ])->assertExitCode(0);

        $this->assertNotNull($capturedScreenshots);

        // The placeholder "unknown" must NOT appear in the screenshot labels.
        foreach ($capturedScreenshots as $screenshot) {
            $this->assertNotEquals('unknown', $screenshot['label'] ?? 'unknown');
        }
        // At least one of the segment-derived slugs should appear.
        $labels = implode(' ', array_column($capturedScreenshots, 'label'));
        $this->assertMatchesRegularExpression(
            '/(opening-the-dashboard|clicking-the-save|final-review-of-validation)/',
            $labels,
        );
    }

    /** @test */
    public function it_produces_distinct_slugs_when_transcript_has_a_single_segment(): void
    {
        $this->mockVideoService([
            'duration' => 120,
            'transcript_segments' => [[
                'text' => 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen',
                'ts' => 0,
            ]],
        ]);
        $this->mockScreenshotService($this->fakeCapturedScreenshots(3, 60));
        $this->mockPlanService();

        $this->artisan('loom:plan', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--screenshots' => '60',
        ])->assertExitCode(0);

        $files = glob("{$this->outputDir}/screenshots/*.jpg");
        $this->assertCount(3, $files);

        // Strip the timestamp prefix and assert the slug portions differ.
        $slugs = array_map(
            fn ($p) => preg_replace('/^.*-\d{4}-(.*)\.jpg$/', '$1', basename($p)),
            $files,
        );
        $this->assertSame(
            count($slugs),
            count(array_unique($slugs)),
            'Each screenshot should get a distinct slug from the proportional fallback',
        );
    }
}
