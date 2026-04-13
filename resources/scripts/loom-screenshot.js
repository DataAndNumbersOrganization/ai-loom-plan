/**
 * Loom Screenshot Capture
 *
 * Uses Playwright to load a Loom video embed, capture frames at regular intervals,
 * and deduplicate using perceptual hashing.
 *
 * Usage: node loom-screenshot.js <loom-url> [--duration=SECONDS] [--output-dir=PATH] [--interval=SECONDS] [--debug]
 * Output: JSON to stdout with { screenshots: [{ path, timestamp, hash, formatted_time }] }
 */

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const loomUrl = process.argv[2];
const debugMode = process.argv.includes('--debug');

// Parse --duration=N
const durationArg = process.argv.find(a => a.startsWith('--duration='));
const duration = durationArg ? parseInt(durationArg.split('=')[1], 10) : null;

// Parse --output-dir=PATH
const outputArg = process.argv.find(a => a.startsWith('--output-dir='));
const outputDir = outputArg ? outputArg.split('=')[1] : path.join(process.cwd(), 'storage/app/temp/loom-screenshots');

// Parse --interval=N (seconds between captures, default 1)
const intervalArg = process.argv.find(a => a.startsWith('--interval='));
const interval = intervalArg ? parseInt(intervalArg.split('=')[1], 10) : 1;

if (!loomUrl || !loomUrl.includes('loom.com')) {
    console.error(JSON.stringify({ error: 'Please provide a valid Loom URL as the first argument' }));
    process.exit(1);
}

// Extract video ID from the URL
function extractVideoId(url) {
    const match = url.match(/loom\.com\/(?:share|embed)\/([a-f0-9]{32}|[a-f0-9-]{36})/i);
    return match ? match[1] : null;
}

/**
 * Determine which timestamps to screenshot based on interval.
 * Captures 1 frame every N seconds.
 */
function getTargetTimestamps(durationSecs, intervalSecs) {
    if (!durationSecs || durationSecs < 1 || intervalSecs < 1) {
        return [{ ts: 0 }];
    }

    const targets = [];
    for (let ts = 0; ts < durationSecs; ts += intervalSecs) {
        targets.push({ ts: Math.floor(ts) });
    }
    return targets;
}

function formatTimestamp(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m${s.toString().padStart(2, '0')}s`;
}

(async () => {
    let browser;
    try {
        const videoId = extractVideoId(loomUrl);
        if (!videoId) {
            console.error(JSON.stringify({ error: 'Could not extract video ID from URL' }));
            process.exit(1);
        }

        // Ensure output directory exists
        fs.mkdirSync(outputDir, { recursive: true });

        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            viewport: { width: 1280, height: 720 },
        });
        const page = await context.newPage();

        // Use the embed URL for cleaner video-only view
        const embedUrl = `https://www.loom.com/embed/${videoId}`;

        if (debugMode) {
            console.error(`[debug] Navigating to: ${embedUrl}`);
            console.error(`[debug] Duration: ${duration || 'unknown'}s`);
            console.error(`[debug] Output dir: ${outputDir}`);
        }

        await page.goto(embedUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });

        // Wait for initial page load
        await page.waitForTimeout(3000);

        // Strategy: click anywhere on the page to trigger play (Loom embeds start on click)
        // Then also try explicit play button selectors
        try {
            // Click the center of the page to dismiss any overlay and start playback
            await page.click('body', { position: { x: 640, y: 360 } });
            await page.waitForTimeout(1000);
        } catch (e) {
            if (debugMode) console.error(`[debug] Body click failed: ${e.message}`);
        }

        try {
            const playSelectors = [
                '[data-testid="play-button"]',
                '[aria-label="Play"]',
                'button[class*="play"]',
                '[class*="PlayButton"]',
                '.loom-play-button',
            ];
            for (const sel of playSelectors) {
                const btn = await page.$(sel);
                if (btn && await btn.isVisible()) {
                    await btn.click();
                    await page.waitForTimeout(1000);
                    break;
                }
            }
        } catch (e) {
            if (debugMode) console.error(`[debug] Play button click failed: ${e.message}`);
        }

        // Wait for the video element to exist and have loaded data
        const hasVideo = await page.evaluate(() => {
            return new Promise((resolve) => {
                const check = (attempts) => {
                    const video = document.querySelector('video');
                    if (!video) {
                        if (attempts < 20) {
                            setTimeout(() => check(attempts + 1), 500);
                        } else {
                            resolve(false);
                        }
                        return;
                    }

                    // Try to force play
                    video.play().catch(() => {});

                    // Wait until video has enough data (readyState >= 2 = HAVE_CURRENT_DATA)
                    const waitForData = (dataAttempts) => {
                        if (video.readyState >= 2) {
                            resolve(true);
                            return;
                        }
                        if (dataAttempts < 30) {
                            setTimeout(() => waitForData(dataAttempts + 1), 500);
                        } else {
                            // Even if readyState is low, video element exists
                            resolve(video.readyState >= 1);
                        }
                    };
                    waitForData(0);
                };
                check(0);
            });
        });

        if (debugMode) {
            const videoState = await page.evaluate(() => {
                const v = document.querySelector('video');
                return v ? { readyState: v.readyState, paused: v.paused, currentTime: v.currentTime, duration: v.duration, src: v.src?.substring(0, 100) } : null;
            });
            console.error(`[debug] Video ready: ${hasVideo}`, JSON.stringify(videoState));
        }

        const targets = getTargetTimestamps(duration, interval);
        const screenshots = [];
        let lastHash = null;
        let capturedCount = 0;
        let totalFrames = 0;

        for (const target of targets) {
            totalFrames++;
            const secondsPadded = target.ts.toString().padStart(4, '0');
            const filename = `loom-${videoId.substring(0, 12)}-${secondsPadded}s.jpg`;
            const filepath = path.join(outputDir, filename);

            if (hasVideo) {
                // Seek the video to the target timestamp (including 0 for initial frame)
                const seekResult = await page.evaluate(async (seekTo) => {
                    const video = document.querySelector('video');
                    if (!video) return { success: false, reason: 'no video element' };

                    try {
                        video.pause();

                        return new Promise((resolve) => {
                            const onSeeked = () => {
                                video.removeEventListener('seeked', onSeeked);
                                // Small delay for the frame to paint
                                setTimeout(() => {
                                    resolve({
                                        success: true,
                                        currentTime: video.currentTime,
                                        readyState: video.readyState,
                                    });
                                }, 500);
                            };
                            video.addEventListener('seeked', onSeeked);
                            video.currentTime = seekTo;

                            // Timeout if seeking takes too long
                            setTimeout(() => {
                                video.removeEventListener('seeked', onSeeked);
                                resolve({
                                    success: true,
                                    currentTime: video.currentTime,
                                    readyState: video.readyState,
                                    note: 'seek timeout',
                                });
                            }, 5000);
                        });
                    } catch (e) {
                        return { success: false, reason: e.message };
                    }
                }, target.ts);

                if (debugMode) {
                    console.error(`[debug] Seek to ${target.ts}s (${target.label}):`, JSON.stringify(seekResult));
                }

                // Extra wait for the frame to fully render
                await page.waitForTimeout(500);
            }

            // Extract the video frame via canvas and compute perceptual hash
            const frameData = await page.evaluate(() => {
                const video = document.querySelector('video');
                if (!video || video.videoWidth === 0) return null;

                // Draw frame to full-size canvas
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.80);

                // Compute perceptual hash (aHash)
                const hashCanvas = document.createElement('canvas');
                hashCanvas.width = 8;
                hashCanvas.height = 8;
                const hashCtx = hashCanvas.getContext('2d');
                hashCtx.drawImage(video, 0, 0, 8, 8);
                
                const imageData = hashCtx.getImageData(0, 0, 8, 8);
                const pixels = imageData.data;
                
                // Convert to grayscale and compute mean
                const grayscale = [];
                for (let i = 0; i < pixels.length; i += 4) {
                    const r = pixels[i];
                    const g = pixels[i + 1];
                    const b = pixels[i + 2];
                    const gray = Math.floor(r * 0.299 + g * 0.587 + b * 0.114);
                    grayscale.push(gray);
                }
                
                const mean = grayscale.reduce((a, b) => a + b, 0) / grayscale.length;
                
                // Build 64-bit hash as hex string
                let hash = '';
                for (let i = 0; i < grayscale.length; i += 4) {
                    let byte = 0;
                    for (let j = 0; j < 4 && i + j < grayscale.length; j++) {
                        if (grayscale[i + j] >= mean) {
                            byte |= (1 << (7 - j * 2));
                            if (i + j + 1 < grayscale.length && grayscale[i + j + 1] >= mean) {
                                byte |= (1 << (6 - j * 2));
                            }
                        } else if (i + j + 1 < grayscale.length && grayscale[i + j + 1] >= mean) {
                            byte |= (1 << (6 - j * 2));
                        }
                    }
                    hash += byte.toString(16).padStart(2, '0');
                }

                return { dataUrl, hash };
            });

            if (frameData) {
                // Check if this frame is a duplicate of the previous frame
                if (lastHash && frameData.hash === lastHash) {
                    if (debugMode) {
                        console.error(`[debug] Frame at ${target.ts}s is duplicate, skipping`);
                    }
                    continue;
                }

                // Strip the data:image/jpeg;base64, prefix and write to file
                const base64Data = frameData.dataUrl.replace(/^data:image\/jpeg;base64,/, '');
                fs.writeFileSync(filepath, Buffer.from(base64Data, 'base64'));

                capturedCount++;
                lastHash = frameData.hash;

                screenshots.push({
                    path: filepath,
                    timestamp: target.ts,
                    hash: frameData.hash,
                    formatted_time: formatTimestamp(target.ts),
                });

                if (debugMode) {
                    console.error(`[debug] Frame captured at ${target.ts}s: ${filepath} (hash: ${frameData.hash})`);
                }
            } else {
                // Fallback: try page screenshot anyway
                await page.screenshot({ path: filepath, fullPage: false, type: 'jpeg', quality: 70 });
                capturedCount++;
                screenshots.push({
                    path: filepath,
                    timestamp: target.ts,
                    hash: null,
                    formatted_time: formatTimestamp(target.ts),
                });

                if (debugMode) {
                    console.error(`[debug] Canvas failed, used page screenshot: ${filepath}`);
                }
            }
        }

        if (debugMode) {
            console.error(`[debug] Captured ${capturedCount} unique frames from ${totalFrames} total frames`);
        }

        console.log(JSON.stringify({ screenshots }));
        await browser.close();
    } catch (error) {
        if (browser) await browser.close();
        console.error(JSON.stringify({ error: error.message }));
        process.exit(1);
    }
})();
