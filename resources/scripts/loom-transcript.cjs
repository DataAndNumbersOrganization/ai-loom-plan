/**
 * Loom Transcript Extractor
 *
 * Uses Playwright to load a Loom video page, wait for the transcript
 * to render client-side, and extract it.
 *
 * Usage: node loom-transcript.cjs <loom-url>
 * Output: JSON to stdout with { text, segments, title, duration }
 */

const { chromium } = require('@playwright/test');

const loomUrl = process.argv[2];
const debugMode = process.argv.includes('--debug');

if (!loomUrl || !loomUrl.includes('loom.com')) {
    console.error(JSON.stringify({ error: 'Please provide a valid Loom URL as the first argument' }));
    process.exit(1);
}

/**
 * Parse a WebVTT file into transcript segments.
 */
function parseVTT(vttContent) {
    const segments = [];
    const blocks = vttContent.split('\n\n');

    for (const block of blocks) {
        const lines = block.trim().split('\n');
        // Find the timestamp line (contains -->)
        let tsLine = null;
        let textLines = [];

        for (const line of lines) {
            if (line.includes('-->')) {
                tsLine = line;
            } else if (tsLine && line.trim() && !line.startsWith('WEBVTT') && !line.match(/^\d+$/)) {
                // Strip VTT tags like <c> </c>
                textLines.push(line.replace(/<[^>]+>/g, '').trim());
            }
        }

        if (tsLine && textLines.length > 0) {
            // Parse start timestamp: "00:00:01.234 --> 00:00:05.678"
            const match = tsLine.match(/(\d{2}):(\d{2}):(\d{2})[.,](\d{3})/);
            let ts = null;
            if (match) {
                ts = parseInt(match[1]) * 3600 + parseInt(match[2]) * 60 + parseInt(match[3]) + parseInt(match[4]) / 1000;
            }

            const text = textLines.join(' ');
            if (text) {
                segments.push({ text, ts });
            }
        }
    }

    return segments;
}

(async () => {
    let browser;
    try {
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        });
        const page = await context.newPage();

        // Collect API responses — both JSON transcript data and VTT captions
        let apiTranscript = null;
        let vttText = null;
        const apiCalls = [];
        page.on('response', async (response) => {
            const url = response.url();
            if (debugMode && url.includes('loom.com') && !url.includes('.js') && !url.includes('.css') && !url.includes('.png') && !url.includes('.svg')) {
                apiCalls.push({ url: url.substring(0, 200), status: response.status() });
            }

            // Capture VTT caption files (most reliable source)
            if (url.includes('captions') && url.includes('.vtt')) {
                try {
                    const text = await response.text();
                    if (text && text.includes('WEBVTT') && !vttText) {
                        vttText = text;
                    }
                } catch (e) { /* skip */ }
            }

            // Capture JSON transcript/transcription responses
            if (url.includes('/transcriptions') || url.includes('/transcript') || url.includes('graphql')) {
                try {
                    const data = await response.json();
                    // GraphQL responses with transcript data
                    if (data?.data?.fetchVideoTranscript || data?.data?.transcription) {
                        apiTranscript = data.data.fetchVideoTranscript || data.data.transcription;
                    }
                    // Direct transcript responses with phrases
                    if (data?.phrases && !apiTranscript) {
                        apiTranscript = data;
                    }
                    // Other transcript structures
                    if ((data?.captions || data?.transcription) && !apiTranscript) {
                        apiTranscript = data;
                    }
                } catch (e) { /* Not JSON, skip */ }
            }
        });

        await page.goto(loomUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });

        // Wait for Loom's client-side JS to load and make API calls
        await page.waitForTimeout(8000);

        // Strategy 1: Try to find and click the transcript tab/button to reveal it
        try {
            const transcriptButton = await page.$('[data-testid="transcript-button"], [aria-label*="Transcript"], button:has-text("Transcript")');
            if (transcriptButton) {
                const isVisible = await transcriptButton.isVisible();
                if (isVisible) {
                    await transcriptButton.click();
                    await page.waitForTimeout(2000);
                }
            }
        } catch (e) {
            // Transcript button not found or not clickable — continue
        }

        // Strategy 2: Extract transcript from the DOM
        const transcript = await page.evaluate(() => {
            const result = { text: null, segments: [], title: null, duration: null };

            // Get title
            const titleEl = document.querySelector('[data-testid="video-title"], h1, .css-1dbjc4n [class*="title"]');
            if (titleEl) {
                result.title = titleEl.textContent.trim();
            }

            // Look for transcript segments in the DOM
            // Loom renders transcript segments as individual elements
            const segmentSelectors = [
                '[data-testid="transcript-segment"]',
                '[data-testid="transcript-line"]',
                '.transcript-segment',
                '.transcript-line',
                '[class*="TranscriptLine"]',
                '[class*="transcript"] [class*="segment"]',
                '[class*="transcript"] [class*="line"]',
                '[class*="transcript"] p',
                '[class*="Transcript"] span[class*="text"]',
            ];

            for (const selector of segmentSelectors) {
                const elements = document.querySelectorAll(selector);
                if (elements.length > 0) {
                    elements.forEach((el) => {
                        const text = el.textContent.trim();
                        if (text) {
                            // Try to get timestamp from nearby element or data attribute
                            const tsAttr = el.getAttribute('data-timestamp') || el.getAttribute('data-time') || el.getAttribute('data-ts');
                            const tsEl = el.querySelector('[class*="timestamp"], [class*="time"]') || el.previousElementSibling;
                            let ts = null;
                            if (tsAttr) {
                                ts = parseFloat(tsAttr);
                            } else if (tsEl && tsEl.textContent.match(/\d+:\d+/)) {
                                const parts = tsEl.textContent.match(/(\d+):(\d+)/);
                                if (parts) ts = parseInt(parts[1]) * 60 + parseInt(parts[2]);
                            }
                            result.segments.push({ text, ts });
                        }
                    });
                    break;
                }
            }

            // If no segments found via selectors, try to find transcript container
            if (result.segments.length === 0) {
                const containerSelectors = [
                    '[data-testid="transcript"]',
                    '[class*="transcript-container"]',
                    '[class*="TranscriptPanel"]',
                    '[class*="transcript-panel"]',
                    '[class*="Transcript"]',
                    '#transcript',
                ];

                for (const selector of containerSelectors) {
                    const container = document.querySelector(selector);
                    if (container) {
                        const text = container.textContent.trim();
                        if (text && text.length > 20) {
                            result.text = text;
                            break;
                        }
                    }
                }
            }

            // Strategy 3: Look for transcript data in window/global state
            const globals = ['__NEXT_DATA__', '__LOOM__', '__APOLLO_STATE__', '__loom__'];
            for (const g of globals) {
                if (window[g]) {
                    try {
                        const json = JSON.stringify(window[g]);
                        // Check if it contains transcript-like data
                        if (json.includes('transcript') || json.includes('Transcript') || json.includes('caption')) {
                            result._rawState = g;
                            // Try to extract from NEXT_DATA
                            if (g === '__NEXT_DATA__' && window[g].props?.pageProps) {
                                const pp = window[g].props.pageProps;
                                if (pp.transcript) result._nextTranscript = pp.transcript;
                                if (pp.video?.transcript) result._nextTranscript = pp.video.transcript;
                                if (pp.transcription) result._nextTranscript = pp.transcription;
                            }
                        }
                    } catch (e) {
                        // Skip
                    }
                }
            }

            // Compose full text from segments
            if (result.segments.length > 0 && !result.text) {
                result.text = result.segments.map(s => s.text).join(' ');
            }

            return result;
        });

        // Strategy A: Parse VTT captions (most reliable)
        if (!transcript.text && vttText) {
            const vttSegments = parseVTT(vttText);
            if (vttSegments.length > 0) {
                transcript.segments = vttSegments;
                transcript.text = vttSegments.map(s => s.text).join(' ');
            }
        }

        // Strategy B: Parse API-intercepted transcript (phrases structure)
        if (!transcript.text && apiTranscript) {
            if (apiTranscript.phrases && Array.isArray(apiTranscript.phrases)) {
                // Loom's phrases structure: [{ts, value}, ...]
                transcript.segments = apiTranscript.phrases
                    .map(p => ({ text: p.value || p.text || '', ts: p.ts || p.start || null }))
                    .filter(s => s.text);
                transcript.text = transcript.segments.map(s => s.text).join(' ');
            } else if (Array.isArray(apiTranscript)) {
                transcript.segments = apiTranscript.map(s => ({
                    text: s.text || s.source_text || s.value || '',
                    ts: s.ts || s.start || s.startTime || null,
                })).filter(s => s.text);
                transcript.text = transcript.segments.map(s => s.text).join(' ');
            } else if (apiTranscript.captions) {
                transcript.segments = apiTranscript.captions.map(s => ({
                    text: s.text || s.source_text || '',
                    ts: s.ts || s.start || null,
                })).filter(s => s.text);
                transcript.text = transcript.segments.map(s => s.text).join(' ');
            } else if (typeof apiTranscript === 'object' && apiTranscript.text) {
                transcript.text = apiTranscript.text;
            }
        }

        // Strategy C: Handle _nextTranscript if found
        if (!transcript.text && transcript._nextTranscript) {
            const nt = transcript._nextTranscript;
            if (Array.isArray(nt)) {
                transcript.segments = nt.map(s => ({
                    text: s.text || s.source_text || s.value || '',
                    ts: s.ts || s.start || null,
                })).filter(s => s.text);
                transcript.text = transcript.segments.map(s => s.text).join(' ');
            } else if (typeof nt === 'string') {
                transcript.text = nt;
            }
        }

        // Clean up internal fields
        delete transcript._rawState;
        delete transcript._nextTranscript;

        if (debugMode) {
            transcript._debug = {
                apiCalls: apiCalls.slice(0, 30),
                apiTranscriptFound: !!apiTranscript,
                apiTranscriptKeys: apiTranscript ? Object.keys(apiTranscript) : null,
            };
            // Also get available window globals
            const globals = await page.evaluate(() => {
                const found = {};
                for (const g of ['__NEXT_DATA__', '__LOOM__', '__APOLLO_STATE__', '__loom__', '__INITIAL_STATE__']) {
                    if (window[g]) found[g] = typeof window[g];
                }
                // Check for any window property containing 'loom' or 'transcript'
                for (const key of Object.keys(window)) {
                    const kl = key.toLowerCase();
                    if ((kl.includes('loom') || kl.includes('transcript') || kl.includes('video')) && !kl.includes('event')) {
                        found[key] = typeof window[key];
                    }
                }
                return found;
            });
            transcript._debug.windowGlobals = globals;
        }

        console.log(JSON.stringify(transcript));
        await browser.close();
    } catch (error) {
        if (browser) await browser.close();
        console.error(JSON.stringify({ error: error.message }));
        process.exit(1);
    }
})();
