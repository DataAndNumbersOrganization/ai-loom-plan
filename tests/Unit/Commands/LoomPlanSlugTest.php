<?php

namespace Dan\AiLoomPlanner\Tests\Unit\Commands;

use Dan\AiLoomPlanner\Commands\LoomPlanCommand;
use Dan\AiLoomPlanner\Tests\TestCase;

class LoomPlanSlugTest extends TestCase
{
    /** @test */
    public function it_returns_frame_when_no_segments(): void
    {
        $this->assertSame('frame', LoomPlanCommand::generateSlugFromTranscript(0, []));
    }

    /** @test */
    public function it_uses_closest_segment_when_timestamps_are_distinct(): void
    {
        $segments = [
            ['text' => 'opening the dashboard for the first time', 'ts' => 0],
            ['text' => 'clicking the save button on the form', 'ts' => 30],
            ['text' => 'reviewing validation errors near the bottom', 'ts' => 90],
        ];

        $this->assertSame(
            'opening-the-dashboard-for-the-first',
            LoomPlanCommand::generateSlugFromTranscript(2, $segments, 120),
        );
        $this->assertSame(
            'clicking-the-save-button-on-the',
            LoomPlanCommand::generateSlugFromTranscript(28, $segments, 120),
        );
        $this->assertSame(
            'reviewing-validation-errors-near-the-bottom',
            LoomPlanCommand::generateSlugFromTranscript(85, $segments, 120),
        );
    }

    /** @test */
    public function it_uses_proportional_window_when_only_one_segment(): void
    {
        // Single big segment with no useful per-screenshot ts coverage.
        $segments = [[
            'text' => 'one two three four five six seven eight nine ten eleven twelve',
            'ts' => 0,
        ]];

        // Duration 120s, screenshot at 0s → window starts at index 0.
        $first = LoomPlanCommand::generateSlugFromTranscript(0, $segments, 120);
        $this->assertSame('one-two-three-four-five-six', $first);

        // Screenshot near the end → window slides toward the tail.
        $last = LoomPlanCommand::generateSlugFromTranscript(120, $segments, 120);
        $this->assertSame('seven-eight-nine-ten-eleven-twelve', $last);

        // Different timestamps must produce different slugs.
        $this->assertNotSame($first, $last);
    }

    /** @test */
    public function it_falls_back_to_proportional_window_when_all_ts_null(): void
    {
        $segments = [
            ['text' => 'alpha beta gamma delta', 'ts' => null],
            ['text' => 'epsilon zeta eta theta', 'ts' => null],
            ['text' => 'iota kappa lambda mu', 'ts' => null],
        ];

        $start = LoomPlanCommand::generateSlugFromTranscript(0, $segments, 60);
        $end = LoomPlanCommand::generateSlugFromTranscript(60, $segments, 60);

        $this->assertNotSame($start, $end);
        $this->assertStringContainsString('alpha', $start);
    }

    /** @test */
    public function it_handles_missing_duration_gracefully(): void
    {
        $segments = [['text' => 'a single chunk of text without timestamps', 'ts' => null]];
        $slug = LoomPlanCommand::generateSlugFromTranscript(15, $segments, null);

        $this->assertNotSame('frame', $slug);
        $this->assertNotSame('unknown', $slug);
    }

    /** @test */
    public function it_returns_frame_when_segments_have_no_text(): void
    {
        $segments = [['text' => '', 'ts' => 5], ['text' => '', 'ts' => 10]];
        $this->assertSame('frame', LoomPlanCommand::generateSlugFromTranscript(7, $segments, 30));
    }
}
