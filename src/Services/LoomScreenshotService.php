<?php

namespace Dan\AiLoomPlanner\Services;

use Dan\AiLoomPlanner\LoomPlannerServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LoomScreenshotService
{
    protected string $outputDir;

    public function __construct()
    {
        $this->outputDir = storage_path('app/temp/loom-screenshots');
    }

    /**
     * Capture screenshots from a Loom video at regular intervals.
     *
     * @return array<int, array{path: string, timestamp: int, hash: string|null, formatted_time: string}>
     */
    public function capture(string $videoId, ?int $duration = null, int $interval = 1): array
    {
        $scriptPath = LoomPlannerServiceProvider::packageResourcePath('scripts/loom-screenshot.cjs');

        if (!file_exists($scriptPath)) {
            Log::warning('Loom screenshot script not found', ['path' => $scriptPath]);

            return [];
        }

        if (!File::isDirectory($this->outputDir)) {
            File::makeDirectory($this->outputDir, 0755, true);
        }

        $loomUrl = "https://www.loom.com/share/{$videoId}";

        try {
            $nodePath = $this->findNodeBinary();

            if (!$nodePath) {
                Log::warning('node not found — cannot run Playwright screenshot capture');

                return [];
            }

            $nodeModulesPath = base_path('node_modules');

            $command = sprintf(
                'NODE_PATH=%s %s %s %s --output-dir=%s',
                escapeshellarg($nodeModulesPath),
                escapeshellarg($nodePath),
                escapeshellarg($scriptPath),
                escapeshellarg($loomUrl),
                escapeshellarg($this->outputDir)
            );

            if ($duration) {
                $command .= sprintf(' --duration=%d', $duration);
            }

            $command .= sprintf(' --interval=%d', $interval);
            $command .= ' 2>&1';

            Log::info('Loom: Running Playwright screenshot capture', ['command' => $command]);

            $output = null;
            $exitCode = null;
            exec($command, $output, $exitCode);

            $jsonOutput = implode("\n", $output ?: []);

            if ($exitCode !== 0) {
                Log::warning('Loom screenshot script failed', ['exit_code' => $exitCode, 'output' => $jsonOutput]);

                return [];
            }

            $data = json_decode($jsonOutput, true);

            if (!$data || !is_array($data) || empty($data['screenshots'])) {
                Log::warning('Loom screenshot script returned no screenshots', ['output' => $jsonOutput]);

                return [];
            }

            $screenshots = array_filter($data['screenshots'], function ($screenshot) {
                return !empty($screenshot['path']) && file_exists($screenshot['path']);
            });

            Log::info('Loom: Screenshot capture completed', ['count' => count($screenshots)]);

            return array_values($screenshots);
        } catch (\Throwable $e) {
            Log::warning('Loom screenshot capture error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Clean up screenshot files.
     *
     * @param  array<int, array{path: string}>  $screenshots
     */
    public function cleanup(array $screenshots): void
    {
        foreach ($screenshots as $screenshot) {
            if (!empty($screenshot['path']) && file_exists($screenshot['path'])) {
                @unlink($screenshot['path']);
            }
        }
    }

    protected function findNodeBinary(): ?string
    {
        $nodePath = trim(shell_exec('which node') ?? '');

        if ($nodePath) {
            return $nodePath;
        }

        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node', '/usr/bin/node'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
