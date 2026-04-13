<?php

namespace Dan\AiLoomPlanner\Tests;

use Dan\AiLoomPlanner\LoomPlannerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LoomPlannerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('loom-planner.provider', 'anthropic');
        $app['config']->set('loom-planner.model', 'claude-sonnet-4-20250514');
        $app['config']->set('loom-planner.max_tokens', 8000);
        $app['config']->set('loom-planner.app_name', 'TestApp');
        $app['config']->set('loom-planner.output_dir', 'docs-and-plans/loom');
    }

    /**
     * Build a fake Loom data array matching LoomVideoService::extract() output.
     */
    protected function fakeLoomData(array $overrides = []): array
    {
        return array_merge([
            'id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'title' => 'Test Walkthrough Video',
            'duration' => 272,
            'transcript_text' => 'This is a test transcript with enough words to be useful for testing the plan generation service.',
            'transcript_segments' => [
                ['text' => 'This is a test transcript', 'ts' => 0],
                ['text' => 'with enough words to be useful', 'ts' => 10],
                ['text' => 'for testing the plan generation service.', 'ts' => 20],
            ],
            'thumbnail_url' => 'https://cdn.loom.com/sessions/thumbnails/a1b2c3d4e5f6-thumb.jpg',
            'thumbnail_path' => null,
            'url' => 'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ], $overrides);
    }

    /**
     * Build a fake screenshots array.
     */
    protected function fakeScreenshots(int $count = 3): array
    {
        $screenshots = [];
        for ($i = 0; $i < $count; $i++) {
            $ts = $i * 10;
            $screenshots[] = [
                'path' => "/tmp/loom-test/screenshot-{$ts}.jpg",
                'timestamp' => $ts,
                'hash' => md5("frame-{$ts}"),
                'formatted_time' => sprintf('%dm%02ds', floor($ts / 60), $ts % 60),
                'label' => "frame-at-{$ts}s",
            ];
        }
        return $screenshots;
    }
}
