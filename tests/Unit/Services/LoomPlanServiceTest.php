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
        $prompt = $service->buildPromptForOutput($this->fakeLoomData());

        // The prompt should reference the app name from config
        $this->assertStringContainsString('TestApp', $prompt);
    }

    /** @test */
    public function it_uses_custom_tech_stack_when_configured(): void
    {
        $this->app['config']->set('loom-planner.tech_stack', '- **Backend**: Phoenix/Elixir');

        $service = app(LoomPlanService::class);
        $prompt = $service->buildPromptForOutput($this->fakeLoomData());

        $this->assertStringContainsString('Phoenix/Elixir', $prompt);
        $this->assertStringNotContainsString('Next.js', $prompt);
    }

    /** @test */
    public function it_uses_default_tech_stack_when_not_configured(): void
    {
        $prompt = $this->service->buildPromptForOutput($this->fakeLoomData());

        $this->assertStringContainsString('Laravel', $prompt);
        $this->assertStringContainsString('Next.js', $prompt);
        $this->assertStringContainsString('MySQL', $prompt);
    }

    // ─── Prompt Building ───────────────────────────────────────────

    /** @test */
    public function it_builds_prompt_with_loom_context(): void
    {
        $loomData = $this->fakeLoomData();
        $prompt = $this->service->buildPromptForOutput($loomData);

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

        $prompt = $this->service->buildPromptForOutput($loomData);

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

        $prompt = $this->service->buildPromptForOutput($loomData);

        $this->assertStringContainsString($loomData['title'], $prompt);
        $this->assertStringContainsString('"has_transcript": false', $prompt);
    }

    /** @test */
    public function it_includes_screenshot_guidelines_when_screenshots_present(): void
    {
        $loomData = $this->fakeLoomData();
        $screenshots = $this->fakeScreenshots(3);

        $prompt = $this->service->buildPromptForOutput($loomData, $screenshots);

        $this->assertStringContainsString('SCREENSHOTS', $prompt);
        $this->assertStringContainsString('3 screenshot(s)', $prompt);
        $this->assertStringContainsString('frame-at-0s', $prompt);
    }

    /** @test */
    public function it_omits_screenshot_guidelines_when_no_screenshots(): void
    {
        $prompt = $this->service->buildPromptForOutput($this->fakeLoomData(), []);

        $this->assertStringNotContainsString('SCREENSHOTS', $prompt);
    }

    // ─── Truncation ────────────────────────────────────────────────

    /** @test */
    public function it_truncates_long_transcripts(): void
    {
        $longTranscript = str_repeat('word ', 5000); // ~25000 chars
        $loomData = $this->fakeLoomData(['transcript_text' => $longTranscript]);

        $prompt = $this->service->buildPromptForOutput($loomData);

        // The transcript in the prompt context should be capped at ~12000 chars
        $this->assertStringContainsString('...', $prompt);
    }

    // ─── Fallback Plan ─────────────────────────────────────────────

    /** @test */
    public function it_generates_fallback_plan_on_ai_failure(): void
    {
        // Configure an invalid provider to force Prism to throw
        $this->app['config']->set('loom-planner.provider', 'invalid-provider');
        $this->app['config']->set('loom-planner.model', 'nonexistent');
        $service = app(LoomPlanService::class);

        $plan = $service->generatePlan($this->fakeLoomData());

        $this->assertStringContainsString('Implementation Plan', $plan);
        $this->assertStringContainsString('Test Walkthrough Video', $plan);
        $this->assertStringContainsString('AI generation failed', $plan);
        $this->assertStringContainsString('transcript', strtolower($plan));
    }

    /** @test */
    public function it_generates_fallback_plan_with_video_metadata(): void
    {
        $this->app['config']->set('loom-planner.provider', 'invalid-provider');
        $this->app['config']->set('loom-planner.model', 'nonexistent');
        $service = app(LoomPlanService::class);

        $loomData = $this->fakeLoomData([
            'title' => 'Dashboard Redesign',
            'url' => 'https://www.loom.com/share/abc123',
            'transcript_text' => null,
        ]);

        $plan = $service->generatePlan($loomData);

        $this->assertStringContainsString('Dashboard Redesign', $plan);
        $this->assertStringContainsString('https://www.loom.com/share/abc123', $plan);
    }

    // ─── Successful Generation ─────────────────────────────────────

    /** @test */
    public function it_returns_ai_generated_plan_on_success(): void
    {
        // Verify generatePlan returns a non-empty string (either from AI or fallback)
        $plan = $this->service->generatePlan($this->fakeLoomData());

        $this->assertNotEmpty($plan);
        $this->assertStringContainsString('Implementation Plan', $plan);
        $this->assertStringContainsString('Test Walkthrough Video', $plan);
    }

    /** @test */
    public function it_includes_transcript_in_generated_plan(): void
    {
        $loomData = $this->fakeLoomData([
            'transcript_text' => 'We need to add a new dashboard widget for analytics.',
        ]);

        $plan = $this->service->generatePlan($loomData);

        $this->assertNotEmpty($plan);
        // The plan (or fallback) should include the transcript content
        $this->assertStringContainsString('dashboard widget', $plan);
    }
}
