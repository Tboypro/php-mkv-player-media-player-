<?php
/**
 * Q MP4 Player - convert.php
 * Runs on the CLI (spawned in the background by upload.php).
 *   php convert.php <video_id> [thumb-only]
 *
 * For files that need converting: tries a fast stream-copy remux first
 * (no re-encoding => seconds, not minutes), falls back to re-encoding only
 * the audio, then finally a full transcode if nothing else works.
 *
 * IMPORTANT: every attempt is verified with ffprobe afterwards. A remux
 * can exit 0 and produce a perfectly valid .mp4 file that is still
 * unplayable in a browser, because "-c copy" just repackages whatever
 * codec was already inside the MKV (e.g. HEVC/x265 video or AC3/DTS
 * audio) without checking whether that codec is something Chrome/Firefox
 * can actually decode. ffmpeg itself can decode almost anything (which is
 * why the thumbnail/duration step below always "works"), but the browser
 * can't - so we can't just trust "did ffmpeg exit 0", we have to check
 * what actually ended up in the output file.
 */

require_once __DIR__ . '/config.php';

$videoId = (int)($argv[1] ?? 0);
$thumbOnly = ($argv[2] ?? '') === 'thumb-only';

if ($videoId <= 0) {
    fwrite(STDERR, "Usage: php convert.php <video_id> [thumb-only]\n");
    exit(1);
}

$stmt = db()->prepare('SELECT * FROM videos WHERE id = ?');
$stmt->bind_param('i', $videoId);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$video) {
    fwrite(STDERR, "No video with id $videoId\n");
    exit(1);
}

function run(string $cmd): array
{
    exec($cmd . ' 2>&1', $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
}

function mark_failed(int $id, string $message): void
{
    $stmt = db()->prepare('UPDATE videos SET status = "failed", error_message = ? WHERE id = ?');
    $msg = substr($message, 0, 480);
    $stmt->bind_param('si', $msg, $id);
    $stmt->execute();
}

/**
 * Update the live "what's happening right now" text shown in the UI while
 * a video is still processing. Cheap to call often - the frontend polls
 * convert_status.php every few seconds and just displays whatever's here.
 */
function set_note(int $id, string $note): void
{
    $stmt = db()->prepare('UPDATE videos SET convert_note = ? WHERE id = ?');
    $stmt->bind_param('si', $note, $id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Ask ffprobe what codec a given stream (e.g. "v:0" or "a:0") is using.
 * Returns null if that stream doesn't exist (e.g. no audio track).
 */
function probe_codec(string $path, string $stream): ?string
{
    $arg = escapeshellarg($path);
    [$code, $out] = run(FFPROBE_BIN . " -v error -select_streams $stream -show_entries stream=codec_name -of csv=p=0 $arg");
    $val = trim($out);
    return ($code === 0 && $val !== '') ? strtolower($val) : null;
}

/**
 * Does this file's actual codecs play natively in a browser <video> tag?
 * (H.264 video is the safe universal choice; AAC/MP3/Opus audio, or no
 * audio track at all, are all fine.)
 */
function is_browser_playable(string $path): bool
{
    $vcodec = probe_codec($path, 'v:0');
    if ($vcodec !== 'h264') {
        return false;
    }
    $acodec = probe_codec($path, 'a:0');
    if ($acodec === null) {
        return true; // no audio track - fine
    }
    return in_array($acodec, ['aac', 'mp3', 'opus'], true);
}

/**
 * Height (in pixels) of the video stream, or null if it can't be read.
 */
function probe_height(string $path): ?int
{
    $arg = escapeshellarg($path);
    [$code, $out] = run(FFPROBE_BIN . " -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 $arg");
    $val = trim($out);
    return ($code === 0 && $val !== '') ? (int)$val : null;
}

/**
 * Small helper for authenticated JSON calls to the CloudConvert API.
 * Returns [httpStatusCode, decodedJsonBody].
 */
function cloudconvert_request(string $method, string $endpoint, ?array $jsonBody = null): array
{
    $ch = curl_init(CLOUDCONVERT_API_BASE . $endpoint);
    $headers = ['Authorization: Bearer ' . CLOUDCONVERT_API_KEY];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [0, ['error' => $curlErr]];
    }
    return [$httpCode, json_decode($resp, true) ?? []];
}

/**
 * Send the source file to CloudConvert, wait for it to remux/transcode it
 * to a browser-playable mp4 (h264/aac), and download the result to
 * $targetPath. Returns true on success; on any failure, $errorOut is set
 * and the caller should fall back to local conversion.
 */
function cloudconvert_transcode(int $videoId, string $srcPath, string $targetPath, string &$errorOut): bool
{
    if (CLOUDCONVERT_API_KEY === '') {
        $errorOut = 'CloudConvert API key is not set in config.php.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $errorOut = 'PHP curl extension is not available.';
        return false;
    }

    set_note($videoId, 'Contacting CloudConvert...');

    // 1. Create the job: upload -> convert to mp4/h264/aac -> export a URL.
    [$code, $job] = cloudconvert_request('POST', '/jobs', [
        'tasks' => [
            'import-file' => ['operation' => 'import/upload'],
            'convert-file' => [
                'operation' => 'convert',
                'input' => 'import-file',
                'output_format' => 'mp4',
                'video_codec' => 'x264',
                'audio_codec' => 'aac',
            ],
            'export-file' => [
                'operation' => 'export/url',
                'input' => 'convert-file',
            ],
        ],
    ]);
    if (($code !== 200 && $code !== 201) || empty($job['data']['tasks'])) {
        $errorOut = 'CloudConvert job creation failed (HTTP ' . $code . '): ' . json_encode($job);
        return false;
    }

    $importTask = null;
    foreach ($job['data']['tasks'] as $task) {
        if (($task['name'] ?? '') === 'import-file') {
            $importTask = $task;
            break;
        }
    }
    if (!$importTask || empty($importTask['result']['form']['url'])) {
        $errorOut = 'CloudConvert did not return an upload form.';
        return false;
    }

    // 2. Upload the source file directly to CloudConvert's storage.
    set_note($videoId, 'Uploading to CloudConvert...');
    $form = $importTask['result']['form'];
    $postFields = $form['parameters'] ?? [];
    $postFields['file'] = new CURLFile($srcPath);

    $ch = curl_init($form['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0); // uploads can take a while
    curl_exec($ch);
    $uploadCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadErr = curl_error($ch);
    curl_close($ch);
    if ($uploadCode >= 300) {
        $errorOut = "CloudConvert upload failed (HTTP $uploadCode): $uploadErr";
        return false;
    }

    // 3. Poll the job until it's finished, errors out, or we give up.
    $jobId = $job['data']['id'];
    $pollStart = time();
    $deadline = $pollStart + 1800; // up to 30 minutes
    $downloadUrl = null;

    while (time() < $deadline) {
        sleep(5);
        $elapsed = time() - $pollStart;
        set_note($videoId, "Converting on CloudConvert... ({$elapsed}s elapsed)");
        [$code, $status] = cloudconvert_request('GET', "/jobs/$jobId");
        if ($code !== 200) {
            continue;
        }
        $jobStatus = $status['data']['status'] ?? '';
        if ($jobStatus === 'error') {
            $errorOut = 'CloudConvert job failed: ' . json_encode($status['data']);
            return false;
        }
        if ($jobStatus === 'finished') {
            foreach ($status['data']['tasks'] as $task) {
                if (($task['name'] ?? '') === 'export-file' && !empty($task['result']['files'][0]['url'])) {
                    $downloadUrl = $task['result']['files'][0]['url'];
                }
            }
            break;
        }
    }

    if (!$downloadUrl) {
        $errorOut = 'CloudConvert conversion timed out or produced no output file.';
        return false;
    }

    // 4. Download the converted file to its final location.
    set_note($videoId, 'Downloading converted file from CloudConvert...');
    $fp = fopen($targetPath, 'wb');
    if (!$fp) {
        $errorOut = 'Could not open target file for writing.';
        return false;
    }
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_exec($ch);
    $dlCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($dlCode >= 300 || !is_file($targetPath) || filesize($targetPath) < 1024) {
        $errorOut = "Could not download converted file from CloudConvert (HTTP $dlCode).";
        return false;
    }

    return true;
}

$targetPath = VIDEOS_DIR . '/' . $video['stored_filename'];

if (!$thumbOnly) {
    $srcPath = ORIGINALS_DIR . '/' . $video['source_filename'];

    if (!is_file($srcPath)) {
        mark_failed($videoId, 'Source file missing on disk.');
        exit(1);
    }

    $ffmpeg = FFMPEG_BIN;
    $inArg = escapeshellarg($srcPath);
    $outArg = escapeshellarg($targetPath);

    // Chromebooks (Crostini) have no GPU passthrough for encoding, so a full
    // transcode is 100% CPU-bound on hardware that's usually low-power. Two
    // things keep attempt 3 as cheap as possible:
    //   - "ultrafast" preset instead of "veryfast" (biggest speed win available
    //     without hardware accel; output files are somewhat larger for the
    //     same quality, which is a fine trade-off for local/personal use).
    //   - downscale to 1080p if the source is bigger than that, since encoding
    //     time roughly scales with pixel count (4K can be 4-6x slower than
    //     1080p) and a laptop/Chromebook screen won't show the difference
    //     anyway. "-2" keeps the width even and preserves aspect ratio.
    $srcHeight = probe_height($srcPath);
    $scaleFilter = ($srcHeight !== null && $srcHeight > 1080) ? '-vf scale=-2:1080' : '';

    // Each attempt: [label, command]. We stop at the first one whose
    // OUTPUT is actually browser-playable, not just the first one that
    // exits 0 - a remux can "succeed" and still contain HEVC/AC3/DTS
    // that no browser can decode.
    $attempts = [
        // Attempt 1: pure remux, no re-encoding at all (fastest, works when
        // the video is already H.264 and audio is already AAC/MP3/Opus).
        "$ffmpeg -y -i $inArg -c copy -movflags +faststart $outArg",
        // Attempt 2: keep video as-is, re-encode only the audio track to AAC
        // (handles AC3/DTS audio while the video is already H.264).
        "$ffmpeg -y -i $inArg -c:v copy -c:a aac -b:a 192k -movflags +faststart $outArg",
        // Attempt 3: full transcode - slowest, but works for HEVC, VP9,
        // MPEG-2, or anything else the browser can't decode natively.
        // ultrafast + optional downscale keep this as light as possible on
        // CPU-only hardware.
        "$ffmpeg -y -i $inArg -c:v libx264 -preset ultrafast -crf 23 $scaleFilter -c:a aac -b:a 192k -movflags +faststart $outArg",
    ];

    $ok = false;
    $lastLog = '';
    $usedMode = 'local';

    // If this video was queued while "Online" mode was active, try
    // CloudConvert first. Any failure (no key, no internet, quota, timeout,
    // etc.) just falls through to the normal local ffmpeg attempts below -
    // the person still gets a working video, just slower than they hoped.
    if (($video['convert_mode'] ?? 'local') === 'cloud') {
        $cloudErr = '';
        if (cloudconvert_transcode($videoId, $srcPath, $targetPath, $cloudErr) && is_browser_playable($targetPath)) {
            $ok = true;
            $usedMode = 'cloud';
        } else {
            @unlink($targetPath);
            set_note($videoId, 'CloudConvert failed - converting locally instead...');
            fwrite(STDERR, "CloudConvert failed, falling back to local conversion: $cloudErr\n");
        }
    }

    $attemptLabels = [
        'Trying quick remux (no re-encoding)...',
        'Re-encoding audio track...',
        'Full video transcode - this is the slow step...',
    ];

    foreach ($attempts as $i => $cmd) {
        if ($ok) {
            break;
        }
        $isLastAttempt = ($i === count($attempts) - 1);
        set_note($videoId, $attemptLabels[$i] ?? 'Converting locally...');
        [$code, $log] = run($cmd);
        $lastLog = $log;

        $produced = ($code === 0 && is_file($targetPath) && filesize($targetPath) >= 1024);

        if ($produced && ($isLastAttempt || is_browser_playable($targetPath))) {
            // Either it's genuinely playable, or it's the last-resort
            // transcode (which always outputs h264/aac, so it's trusted
            // without re-probing).
            $ok = true;
            $usedMode = 'local';
            break;
        }

        // Either the command failed, or it "succeeded" but produced a file
        // with a codec the browser can't play - discard and try the next,
        // stronger attempt.
        @unlink($targetPath);
    }

    if (!$ok) {
        mark_failed($videoId, 'ffmpeg could not produce a browser-playable file: ' . $lastLog);
        exit(1);
    }
} else {
    if (!is_file($targetPath)) {
        mark_failed($videoId, 'Uploaded file missing on disk.');
        exit(1);
    }
}

// --- Duration via ffprobe ---
$ffprobe = FFPROBE_BIN;
$outArg = escapeshellarg($targetPath);
[, $durOut] = run("$ffprobe -v error -show_entries format=duration -of csv=p=0 $outArg");
$duration = (int)round((float)trim($durOut));

// --- Thumbnail: grab a frame ~10% into the video (or 3s in, whichever is smaller) ---
$thumbTime = $duration > 30 ? max(3, (int)($duration * 0.1)) : 1;
$thumbName = pathinfo($video['stored_filename'], PATHINFO_FILENAME) . '.jpg';
$thumbPath = THUMBS_DIR . '/' . $thumbName;
$thumbArg = escapeshellarg($thumbPath);
run(FFMPEG_BIN . " -y -ss $thumbTime -i $outArg -frames:v 1 -vf scale=400:-1 $thumbArg");
if (!is_file($thumbPath)) {
    $thumbName = null;
}

// --- Clean up original source file to save disk space ---
if (!$thumbOnly && !empty($video['source_filename'])) {
    @unlink(ORIGINALS_DIR . '/' . $video['source_filename']);
}

$stmt = db()->prepare('UPDATE videos SET status = "ready", duration_seconds = ?, thumbnail = ?, convert_note = NULL, actual_mode = ? WHERE id = ?');
$actualMode = $thumbOnly ? null : $usedMode;
$stmt->bind_param('issi', $duration, $thumbName, $actualMode, $videoId);
$stmt->execute();
$stmt->close();

exit(0);