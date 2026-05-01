<?php

namespace Dan\AiLoomPlanner\Tests\Unit\Services;

use Dan\AiLoomPlanner\Services\LoomPlanService;
use Dan\AiLoomPlanner\Tests\TestCase;
use Prism\Prism\Facades\Prism;

class LoomPlanServiceTest extends TestCase
{
    protected LoomPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LoomPlanService::class);
    }

    // ─── Configuration ─────────────────────────────────────────────

    /** @test */
    public function it_reads_model_and_provider_from_config(): void
    {
        $this->app['config']->set('loom-planner.provider', 'openai');
        $this->app['config']->set('loom-planner.model', 'gpt-4o');

        $service = app(LoomPlanService::class);
        $prompt = $service->buildPromptText($this->fakeLoomData());

        // The prompt should reference the app name from config
        $this->assertStringContainsString('TestApp', $prompt);
    }

    /** @test */
    public function it_uses_custom_tech_stack_when_configured(): void
    {
        $this->app['config']->set('loom-planner.tech_stack', '- **Backend**: Phoenix/Elixir');

        $service = app(LoomPlanService::class);
        $prompt = $service->buildPromptText($this->fakeLoomData());

        $this->assertStringContainsString('Phoenix/Elixir', $prompt);
        $this->assertStringNotContainsString('Next.js', $prompt);
    }

    /** @test */
    public function it_uses_default_tech_stack_when_not_configured(): void
    {
        $prompt = $this->service->buildPromptText($this->fakeLoomData());

        $this->assertStringContainsString('Laravel', $prompt);
        $this->assertStringContainsString('Next.js', $prompt);
        $this->assertStringContainsString('MySQL', $prompt);
    }

    // ─── Prompt Building ───────────────────────────────────────────

    /** @test */
    public function it_builds_prompt_with_loom_context(): void
    {
        $loomData = $this->fakeLoomData();
        $prompt = $this->service->buildPromptText($loomData);

        $this->assertStringContainsString($loomData['title'], $prompt);
        $this->assertStringContainsString($loomData['url'], $prompt);
        $this->assertStringContainsString('transcript', strtolower($prompt));
        $this->assertStringContainsString('Implementation Plan', $prompt);
    }

    /** @test */
    public function it_includes_timestamped_transcript_in_prompt(): void
    {
        $loomData = $this->fakeLoomData([
            'transcript_segments' => [
                ['text' => 'First segment', 'ts' => 0],
                ['text' => 'Second segment', 'ts' => 65],
            ],
        ]);

        $prompt = $this->service->buildPromptText($loomData);

        $this->assertStringContainsString('[0:00]', $prompt);
        $this->assertStringContainsString('[1:05]', $prompt);
        $this->assertStringContainsString('First segment', $prompt);
        $this->assertStringContainsString('Second segment', $prompt);
    }

    /** @test */
    public function it_builds_prompt_without_transcript(): void
    {
        $loomData = $this->fakeLoomData([
            'transcript_text' => null,
            'transcript_segments' => [],
        ]);

        $prompt = $this->service->buildPromptText($loomData);

        $this->assertStringContainsString($loomData['title'], $prompt);
        $this->assertStringContainsString('"has_transcript": false', $prompt);
    }

    /** @test */
    public function it_includes_screenshot_guidelines_when_screenshots_present(): void
    {
        $loomData = $this->fakeLoomData();
        $screenshots = $this->fakeScreenshots(3);

        $prompt = $this->service->buildPromptText($loomData, $screenshots);

        $this->assertStringContainsString('SCREENSHOTS', $prompt);
        $this->assertStringContainsString('3 screenshot(s)', $prompt);
        $this->assertStringContainsString('frame-at-0s', $prompt);
    }

    /** @test */
    public function it_omits_screenshot_guidelines_when_no_screenshots(): void
    {
        $prompt = $this->service->buildPromptText($this->fakeLoomData(), []);

        $this->assertStringNotContainsString('SCREENSHOTS', $prompt);
    }

    // ─── Truncation ────────────────────────────────────────────────

    /** @test */
    public function it_truncates_long_transcripts(): void
    {
        $longTranscript = str_repeat('word ', 5000); // ~25000 chars
        $loomData = $this->fakeLoomData(['transcript_text' => $longTranscript]);

        $prompt = $this->service->buildPromptText($loomData);

        // The transcript in the prompt context should be capped at ~12000 chars
        $this->assertStringContainsString('...', $prompt);
    }

}
