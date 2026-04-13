<?php

namespace Dan\AiLoomPlanner\Commands;

use Dan\AiLoomPlanner\Services\LoomVideoService;
use Illuminate\Console\Command;

class LoomTranscriptCommand extends Command
{
    protected $signature = 'loom:transcript
                            {url : Loom video URL (e.g., https://www.loom.com/share/abc123...)}
                            {--timestamps : Include timestamps with each segment}
                            {--json : Output raw JSON instead of plain text}';

    protected $description = 'Fetch and display the transcript from a Loom video';

    public function __construct(
        protected LoomVideoService $loomVideoService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = $this->argument('url');

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

        if (!$loomData['transcript_text']) {
            $this->warn('No transcript found for this video.');

            return 1;
        }

        $wordCount = str_word_count($loomData['transcript_text']);
        $this->info("  ✓ Transcript: {$wordCount} words");
        $this->newLine();

        if ($this->option('json')) {
            $this->line(json_encode([
                'title' => $loomData['title'],
                'duration' => $loomData['duration'],
                'transcript_text' => $loomData['transcript_text'],
                'transcript_segments' => $loomData['transcript_segments'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($this->option('timestamps') && !empty($loomData['transcript_segments'])) {
            foreach ($loomData['transcript_segments'] as $segment) {
                $time = isset($segment['ts'])
                    ? LoomVideoService::formatDuration((int) $segment['ts'])
                    : '??:??';
                $this->line("[{$time}] {$segment['text']}");
            }
        } else {
            $this->line($loomData['transcript_text']);
        }

        return 0;
    }
}
