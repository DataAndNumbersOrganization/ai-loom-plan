<?php

namespace Dan\AiLoomPlanner\Services;

use Dan\AiLoomPlanner\LoomPlannerServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LoomVideoService
{
    protected string $oembedUrl = 'https://www.loom.com/v1/oembed';

    /**
     * Extract all available data from a Loom video URL.
     *
     * @return array{id: string, title: string, duration: ?int, transcript_text: ?string, transcript_segments: array, thumbnail_url: ?string, thumbnail_path: ?string, url: string}
     */
    public function extract(string $url): array
    {
        $videoId = $this->extractVideoId($url);

        if (!$videoId) {
            throw new \InvalidArgumentException("Could not extract video ID from URL: {$url}");
        }

        $normalizedUrl = "https://www.loom.com/share/{$videoId}";

        $oembed = $this->fetchOembed($normalizedUrl);
        $transcript = $this->fetchTranscript($videoId);

        $thumbnailPath = null;
        if (!empty($oembed['thumbnail_url'])) {
            $thumbnailPath = $this->downloadThumbnail($oembed['thumbnail_url'], $videoId);
        }

        return [
            'id' => $videoId,
            'title' => $oembed['title'] ?? 'Untitled Loom Video',
            'duration' => $oembed['duration'] ?? null,
            'transcript_text' => $transcript['text'] ?? null,
            'transcript_segments' => $transcript['segments'] ?? [],
            'thumbnail_url' => $oembed['thumbnail_url'] ?? null,
            'thumbnail_path' => $thumbnailPath,
            'url' => $normalizedUrl,
        ];
    }

    /**
     * Extract the video ID from various Loom URL formats.
     */
    public function extractVideoId(string $url): ?string
    {
        if (preg_match('#loom\.com/(?:share|embed)/([a-f0-9]{32}|[a-f0-9-]{36})#i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function fetchOembed(string $url): array
    {
        try {
            $response = Http::timeout(15)->get($this->oembedUrl, ['url' => $url]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::warning('Loom oEmbed request failed', ['status' => $response->status(), 'url' => $url]);
        } catch (\Throwable $e) {
            Log::warning('Loom oEmbed request error', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @return array{text: ?string, segments: array}
     */
    protected function fetchTranscript(string $videoId): array
    {
        $empty = ['text' => null, 'segments' => []];

        // Strategy 1: Fetch the share page and parse embedded JSON
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get("https://www.loom.com/share/{$videoId}");

            if ($response->successful()) {
                $html = $response->body();

                $transcript = $this->parseApolloTranscript($html);
                if ($transcript['text']) {
                    return $transcript;
                }

                $transcript = $this->parseNextDataTranscript($html);
                if ($transcript['text']) {
                    return $transcript;
                }

                $transcript = $this->parseScriptTagTranscript($html, $videoId);
                if ($transcript['text']) {
                    return $transcript;
                }
            } else {
                Log::warning('Loom page fetch failed', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Loom page scrape error', ['error' => $e->getMessage()]);
        }

        // Strategy 2: Direct transcription API
        $transcript = $this->fetchTranscriptFallback($videoId);
        if ($transcript['text']) {
            return $transcript;
        }

        // Strategy 3: Playwright
        Log::info('Loom: Static scraping failed, trying Playwright...');

        return $this->fetchTranscriptViaPlaywright($videoId);
    }

    protected function parseApolloTranscript(string $html): array
    {
        $empty = ['text' => null, 'segments' => []];

        if (!preg_match('/window\.__APOLLO_STATE__\s*=\s*({.+?});\s*<\/script>/s', $html, $matches)) {
            return $empty;
        }

        try {
            $apolloState = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $empty;
        }

        $segments = [];
        foreach ($apolloState as $key => $value) {
            if (str_contains($key, 'Transcription') || str_contains($key, 'transcription')) {
                if (isset($value['source_text'])) {
                    $segments[] = ['text' => $value['source_text'], 'ts' => $value['ts'] ?? null];
                }
                if (isset($value['text'])) {
                    $segments[] = ['text' => $value['text'], 'ts' => $value['ts'] ?? null];
                }
            }
        }

        if (empty($segments)) {
            return $empty;
        }

        usort($segments, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
        $fullText = implode(' ', array_column($segments, 'text'));

        return ['text' => trim($fullText) ?: null, 'segments' => $segments];
    }

    protected function parseNextDataTranscript(string $html): array
    {
        $empty = ['text' => null, 'segments' => []];

        if (!preg_match('/<script\s+id="__NEXT_DATA__"\s+type="application\/json">\s*({.+?})\s*<\/script>/s', $html, $matches)) {
            return $empty;
        }

        try {
            $nextData = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $empty;
        }

        $transcripts = data_get($nextData, 'props.pageProps.transcript')
            ?? data_get($nextData, 'props.pageProps.video.transcript')
            ?? data_get($nextData, 'props.pageProps.transcription')
            ?? null;

        if (!$transcripts) {
            $transcripts = $this->findTranscriptInArray($nextData);
        }

        if (!$transcripts || !is_array($transcripts)) {
            return $empty;
        }

        return $this->normalizeTranscriptSegments($transcripts);
    }

    protected function parseScriptTagTranscript(string $html, string $videoId): array
    {
        $empty = ['text' => null, 'segments' => []];

        preg_match_all('/<script[^>]*>\s*({.+?})\s*<\/script>/s', $html, $matches);

        foreach ($matches[1] as $jsonString) {
            if (!str_contains($jsonString, $videoId)) {
                continue;
            }

            try {
                $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
                $transcripts = $this->findTranscriptInArray($data);
                if ($transcripts) {
                    return $this->normalizeTranscriptSegments($transcripts);
                }
            } catch (\JsonException $e) {
                continue;
            }
        }

        return $empty;
    }

    protected function fetchTranscriptFallback(string $videoId): array
    {
        $empty = ['text' => null, 'segments' => []];

        $endpoints = [
            "https://www.loom.com/v1/videos/{$videoId}/transcriptions",
            "https://www.loom.com/v1/videos/{$videoId}/transcriptions?lang=en",
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                        'Accept' => 'application/json',
                        'Origin' => 'https://www.loom.com',
                        'Referer' => "https://www.loom.com/share/{$videoId}",
                    ])
                    ->get($endpoint);

                if ($response->successful()) {
                    $data = $response->json();

                    if (isset($data['captions'])) {
                        return $this->normalizeTranscriptSegments($data['captions']);
                    }

                    if (isset($data['transcription'])) {
                        return $this->normalizeTranscriptSegments(
                            is_array($data['transcription']) ? $data['transcription'] : [['text' => $data['transcription']]]
                        );
                    }

                    if (is_array($data) && !empty($data)) {
                        return $this->normalizeTranscriptSegments($data);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Loom transcription API fallback failed', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $empty;
    }

    protected function fetchTranscriptViaPlaywright(string $videoId): array
    {
        $empty = ['text' => null, 'segments' => []];
        $scriptPath = LoomPlannerServiceProvider::packageResourcePath('scripts/loom-transcript.cjs');

        if (!file_exists($scriptPath)) {
            Log::warning('Loom Playwright script not found', ['path' => $scriptPath]);

            return $empty;
        }

        $loomUrl = "https://www.loom.com/share/{$videoId}";

        try {
            $nodePath = $this->findNodeBinary();

            if (!$nodePath) {
                Log::warning('node not found — cannot run Playwright transcript extraction');

                return $empty;
            }

            $nodeModulesPath = base_path('node_modules');

            $command = sprintf('NODE_PATH=%s %s %s %s 2>&1', escapeshellarg($nodeModulesPath), escapeshellarg($nodePath), escapeshellarg($scriptPath), escapeshellarg($loomUrl));

            Log::info('Loom: Running Playwright transcript extraction', ['command' => $command]);

            $output = null;
            $exitCode = null;
            exec($command, $output, $exitCode);

            $jsonOutput = implode("\n", $output ?: []);

            if ($exitCode !== 0) {
                Log::warning('Loom Playwright script failed', ['exit_code' => $exitCode, 'output' => $jsonOutput]);

                return $empty;
            }

            $data = json_decode($jsonOutput, true);

            if (!$data || !is_array($data)) {
                Log::warning('Loom Playwright script returned invalid JSON', ['output' => $jsonOutput]);

                return $empty;
            }

            if (!empty($data['error'])) {
                Log::warning('Loom Playwright script error', ['error' => $data['error']]);

                return $empty;
            }

            $text = $data['text'] ?? null;
            $segments = $data['segments'] ?? [];

            if ($text) {
                Log::info('Loom: Playwright transcript extraction succeeded', [
                    'word_count' => str_word_count($text),
                    'segments' => count($segments),
                ]);

                return ['text' => $text, 'segments' => $segments];
            }

            Log::info('Loom: Playwright ran but no transcript found in rendered page');

            return $empty;
        } catch (\Throwable $e) {
            Log::warning('Loom Playwright extraction error', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    protected function findTranscriptInArray(array $data, int $depth = 0): ?array
    {
        if ($depth > 10) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $keyLower = is_string($key) ? strtolower($key) : '';

            if (in_array($keyLower, ['transcript', 'transcription', 'captions', 'subtitles'])) {
                if (!empty($value)) {
                    return $value;
                }
            }

            if (isset($value[0]) && is_array($value[0])) {
                $first = $value[0];
                if (isset($first['text']) || isset($first['source_text']) || isset($first['value'])) {
                    return $value;
                }
            }

            $found = $this->findTranscriptInArray($value, $depth + 1);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    protected function normalizeTranscriptSegments(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                if (is_string($segment) && trim($segment)) {
                    $normalized[] = ['text' => trim($segment), 'ts' => null];
                }

                continue;
            }

            $text = $segment['text'] ?? $segment['source_text'] ?? $segment['value'] ?? $segment['content'] ?? null;

            if (!$text || !is_string($text)) {
                continue;
            }

            $ts = $segment['ts'] ?? $segment['start'] ?? $segment['startTime'] ?? $segment['start_time'] ?? $segment['timestamp'] ?? null;

            $normalized[] = ['text' => trim($text), 'ts' => $ts];
        }

        if (empty($normalized)) {
            return ['text' => null, 'segments' => []];
        }

        $fullText = implode(' ', array_column($normalized, 'text'));

        return ['text' => trim($fullText) ?: null, 'segments' => $normalized];
    }

    protected function downloadThumbnail(string $thumbnailUrl, string $videoId): ?string
    {
        try {
            $response = Http::timeout(15)->get($thumbnailUrl);

            if (!$response->successful()) {
                return null;
            }

            $extension = 'jpg';
            $contentType = $response->header('Content-Type');
            if (str_contains($contentType, 'png')) {
                $extension = 'png';
            } elseif (str_contains($contentType, 'webp')) {
                $extension = 'webp';
            }

            $path = "temp/loom-thumbnails/{$videoId}.{$extension}";
            Storage::disk('local')->put($path, $response->body());

            return Storage::disk('local')->path($path);
        } catch (\Throwable $e) {
            Log::info('Loom thumbnail download failed', ['error' => $e->getMessage()]);

            return null;
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

    public static function formatDuration(?int $seconds): string
    {
        if (!$seconds) {
            return 'Unknown';
        }

        $minutes = floor($seconds / 60);
        $remaining = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$remaining}s";
        }

        return "{$remaining}s";
    }
}
