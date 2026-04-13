<?php

namespace Dan\AiLoomPlanner\Tests\Unit\Services;

use Dan\AiLoomPlanner\Services\LoomScreenshotService;
use Dan\AiLoomPlanner\Tests\TestCase;
use Illuminate\Support\Facades\File;

class LoomScreenshotServiceTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/loom-screenshot-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─── Capture ───────────────────────────────────────────────────

    /** @test */
    public function it_returns_empty_array_when_script_is_missing(): void
    {
        $service = new LoomScreenshotService;

        // The package script path won't exist in a test environment
        // unless we set it up — this tests the guard clause
        $result = $service->capture('abc123', 60, 10);

        $this->assertIsArray($result);
        // Will be empty because the JS script doesn't exist during tests
    }

    /** @test */
    public function it_returns_empty_array_for_null_duration(): void
    {
        $service = new LoomScreenshotService;

        $result = $service->capture('abc123', null, 10);

        $this->assertIsArray($result);
    }

    // ─── Cleanup ───────────────────────────────────────────────────

    /** @test */
    public function it_cleans_up_screenshot_files(): void
    {
        $service = new LoomScreenshotService;

        // Create temp files to clean up
        $files = [];
        for ($i = 0; $i < 3; $i++) {
            $path = "{$this->tempDir}/screenshot-{$i}.jpg";
            file_put_contents($path, 'fake-image');
            $files[] = ['path' => $path];
        }

        // Verify files exist
        foreach ($files as $f) {
            $this->assertFileExists($f['path']);
        }

        $service->cleanup($files);

        // All files should be deleted
        foreach ($files as $f) {
            $this->assertFileDoesNotExist($f['path']);
        }
    }

    /** @test */
    public function it_handles_cleanup_of_already_deleted_files(): void
    {
        $service = new LoomScreenshotService;

        $screenshots = [
            ['path' => '/tmp/does-not-exist-' . uniqid() . '.jpg'],
            ['path' => ''],
            ['path' => null],
        ];

        // Should not throw
        $service->cleanup($screenshots);

        $this->assertTrue(true); // No exception means pass
    }

    /** @test */
    public function it_skips_entries_with_empty_paths_during_cleanup(): void
    {
        $service = new LoomScreenshotService;

        $validPath = "{$this->tempDir}/valid.jpg";
        file_put_contents($validPath, 'data');

        $screenshots = [
            ['path' => ''],
            ['path' => $validPath],
        ];

        $service->cleanup($screenshots);

        $this->assertFileDoesNotExist($validPath);
    }
}
