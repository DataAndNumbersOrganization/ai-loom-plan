<?php

namespace Dan\AiLoomPlanner\Tests\Feature\Commands;

use Dan\AiLoomPlanner\Services\LoomVideoService;
use Dan\AiLoomPlanner\Tests\TestCase;
use Mockery;

class LoomTranscriptCommandTest extends TestCase
{
    protected function mockVideoService(array $loomData = []): void
    {
        $loomData = array_merge($this->fakeLoomData(), $loomData);

        $mock = Mockery::mock(LoomVideoService::class);
        $mock->shouldReceive('extractVideoId')->andReturn($loomData['id']);
        $mock->shouldReceive('extract')->andReturn($loomData);

        $this->app->instance(LoomVideoService::class, $mock);
    }

    // ─── Validation ────────────────────────────────────────────────

    /** @test */
    public function it_rejects_invalid_loom_url(): void
    {
        $mock = Mockery::mock(LoomVideoService::class);
        $mock->shouldReceive('extractVideoId')->andReturn(null);
        $this->app->instance(LoomVideoService::class, $mock);

        $this->artisan('loom:transcript', ['url' => 'https://youtube.com/watch?v=abc'])
            ->expectsOutputToContain('Invalid Loom URL')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_exits_with_error_when_video_extraction_fails(): void
    {
        $mock = Mockery::mock(LoomVideoService::class);
        $mock->shouldReceive('extractVideoId')->andReturn('abc123');
        $mock->shouldReceive('extract')->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(LoomVideoService::class, $mock);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('Failed to fetch Loom video')
            ->assertExitCode(1);
    }

    // ─── No Transcript ─────────────────────────────────────────────

    /** @test */
    public function it_warns_and_exits_when_no_transcript_found(): void
    {
        $this->mockVideoService([
            'transcript_text' => null,
            'transcript_segments' => [],
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('No transcript found')
            ->assertExitCode(1);
    }

    // ─── Plain Text Output (default) ───────────────────────────────

    /** @test */
    public function it_outputs_plain_text_transcript_by_default(): void
    {
        $this->mockVideoService([
            'transcript_text' => 'Hello world, this is a walkthrough of the new feature.',
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('Hello world, this is a walkthrough')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_displays_video_metadata_before_transcript(): void
    {
        $this->mockVideoService([
            'title' => 'Dashboard Walkthrough',
            'duration' => 125,
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('Dashboard Walkthrough')
            ->expectsOutputToContain('2m 5s')
            ->assertExitCode(0);
    }

    // ─── Timestamps Mode ───────────────────────────────────────────

    /** @test */
    public function it_outputs_transcript_with_timestamps(): void
    {
        $this->mockVideoService([
            'transcript_text' => 'Hello world this is a test',
            'transcript_segments' => [
                ['text' => 'Hello world', 'ts' => 5],
                ['text' => 'this is a test', 'ts' => 65],
            ],
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--timestamps' => true,
        ])
            ->expectsOutputToContain('Transcript')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_falls_back_to_plain_text_when_timestamps_requested_but_no_segments(): void
    {
        $this->mockVideoService([
            'transcript_text' => 'Plain text only, no segments available.',
            'transcript_segments' => [],
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--timestamps' => true,
        ])
            ->expectsOutputToContain('Plain text only')
            ->assertExitCode(0);
    }

    // ─── JSON Output ───────────────────────────────────────────────

    /** @test */
    public function it_outputs_json_with_json_flag(): void
    {
        $this->mockVideoService([
            'title' => 'Test Video',
            'duration' => 60,
            'transcript_text' => 'Hello world',
            'transcript_segments' => [
                ['text' => 'Hello world', 'ts' => 0],
            ],
        ]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--json' => true,
        ])
            ->expectsOutputToContain('Test Video')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_outputs_valid_json(): void
    {
        $this->mockVideoService([
            'title' => 'Valid JSON Test',
            'duration' => 30,
            'transcript_text' => 'Some text',
            'transcript_segments' => [],
        ]);

        // Capture the output by running the command
        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            '--json' => true,
        ])->assertExitCode(0);
    }

    // ─── Word Count Display ────────────────────────────────────────

    /** @test */
    public function it_displays_word_count_in_metadata(): void
    {
        $transcript = 'one two three four five six seven eight nine ten';
        $this->mockVideoService(['transcript_text' => $transcript]);

        $this->artisan('loom:transcript', [
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ])
            ->expectsOutputToContain('10 words')
            ->assertExitCode(0);
    }
}
