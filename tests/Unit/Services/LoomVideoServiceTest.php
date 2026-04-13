<?php

namespace Dan\AiLoomPlanner\Tests\Unit\Services;

use Dan\AiLoomPlanner\Services\LoomVideoService;
use Dan\AiLoomPlanner\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LoomVideoServiceTest extends TestCase
{
    protected LoomVideoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoomVideoService;
    }

    // ─── URL Parsing ───────────────────────────────────────────────

    /** @test */
    public function it_extracts_video_id_from_standard_share_url(): void
    {
        $id = $this->service->extractVideoId('https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $id);
    }

    /** @test */
    public function it_extracts_video_id_from_share_url_with_query_params(): void
    {
        $id = $this->service->extractVideoId(
            'https://www.loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4?sid=abc123&t=0'
        );

        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $id);
    }

    /** @test */
    public function it_extracts_video_id_from_embed_url(): void
    {
        $id = $this->service->extractVideoId('https://www.loom.com/embed/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $id);
    }

    /** @test */
    public function it_extracts_video_id_from_url_without_www(): void
    {
        $id = $this->service->extractVideoId('https://loom.com/share/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', $id);
    }

    /** @test */
    public function it_extracts_uuid_format_video_id(): void
    {
        $id = $this->service->extractVideoId(
            'https://www.loom.com/share/a1b2c3d4-e5f6-a1b2-c3d4-e5f6a1b2c3d4'
        );

        $this->assertSame('a1b2c3d4-e5f6-a1b2-c3d4-e5f6a1b2c3d4', $id);
    }

    /** @test */
    public function it_returns_null_for_invalid_url(): void
    {
        $this->assertNull($this->service->extractVideoId('https://www.youtube.com/watch?v=abc'));
        $this->assertNull($this->service->extractVideoId('not-a-url'));
        $this->assertNull($this->service->extractVideoId(''));
        $this->assertNull($this->service->extractVideoId('https://www.loom.com/share/too-short'));
    }

    // ─── Duration Formatting ───────────────────────────────────────

    /** @test */
    public function it_formats_seconds_only_duration(): void
    {
        $this->assertSame('45s', LoomVideoService::formatDuration(45));
    }

    /** @test */
    public function it_formats_minutes_and_seconds_duration(): void
    {
        $this->assertSame('4m 32s', LoomVideoService::formatDuration(272));
    }

    /** @test */
    public function it_formats_exact_minutes(): void
    {
        $this->assertSame('2m 0s', LoomVideoService::formatDuration(120));
    }

    /** @test */
    public function it_returns_unknown_for_null_duration(): void
    {
        $this->assertSame('Unknown', LoomVideoService::formatDuration(null));
    }

    /** @test */
    public function it_returns_unknown_for_zero_duration(): void
    {
        $this->assertSame('Unknown', LoomVideoService::formatDuration(0));
    }

    // ─── Full Extraction ───────────────────────────────────────────

    /** @test */
    public function it_throws_on_invalid_url_during_extract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not extract video ID');

        $this->service->extract('https://not-loom.com/video/123');
    }

    /** @test */
    public function it_extracts_video_data_from_oembed_and_page(): void
    {
        $videoId = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

        Http::fake([
            // oEmbed response
            'www.loom.com/v1/oembed*' => Http::response([
                'title' => 'My Walkthrough',
                'duration' => 180,
                'thumbnail_url' => 'https://cdn.loom.com/thumb.jpg',
            ]),
            // Share page — returns HTML with no embedded transcript
            "www.loom.com/share/{$videoId}" => Http::response('<html><body>No transcript here</body></html>'),
            // Transcription API fallback — returns empty
            "www.loom.com/v1/videos/{$videoId}/transcriptions*" => Http::response([], 404),
            // Thumbnail download
            'cdn.loom.com/*' => Http::response('fake-image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $data = $this->service->extract("https://www.loom.com/share/{$videoId}");

        $this->assertSame($videoId, $data['id']);
        $this->assertSame('My Walkthrough', $data['title']);
        $this->assertSame(180, $data['duration']);
        $this->assertSame("https://www.loom.com/share/{$videoId}", $data['url']);
    }

    /** @test */
    public function it_gracefully_handles_oembed_failure(): void
    {
        $videoId = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

        Http::fake([
            'www.loom.com/v1/oembed*' => Http::response([], 500),
            "www.loom.com/share/{$videoId}" => Http::response('<html><body></body></html>'),
            "www.loom.com/v1/videos/{$videoId}/transcriptions*" => Http::response([], 404),
        ]);

        $data = $this->service->extract("https://www.loom.com/share/{$videoId}");

        $this->assertSame($videoId, $data['id']);
        $this->assertSame('Untitled Loom Video', $data['title']);
        $this->assertNull($data['duration']);
    }

    /** @test */
    public function it_extracts_transcript_from_transcription_api_fallback(): void
    {
        $videoId = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

        Http::fake([
            'www.loom.com/v1/oembed*' => Http::response(['title' => 'Video', 'duration' => 60]),
            "www.loom.com/share/{$videoId}" => Http::response('<html><body></body></html>'),
            "www.loom.com/v1/videos/{$videoId}/transcriptions" => Http::response([
                'captions' => [
                    ['text' => 'Hello world', 'start' => 0],
                    ['text' => 'This is a test', 'start' => 5],
                ],
            ]),
        ]);

        $data = $this->service->extract("https://www.loom.com/share/{$videoId}");

        $this->assertSame('Hello world This is a test', $data['transcript_text']);
        $this->assertCount(2, $data['transcript_segments']);
        $this->assertSame('Hello world', $data['transcript_segments'][0]['text']);
    }
}
