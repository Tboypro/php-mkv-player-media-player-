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

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/media_conversion.php';

$videoId = (int)($argv[1] ?? 0);
$thumbOnly = ($argv[2] ?? '') === 'thumb-only';
$cloudFailure = '';
$usedMode = null;

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
    $note = substr($note, 0, 250);
    $stmt->bind_param('si', $note, $id);
    $stmt->execute();
    $stmt->close();
}

require_once __DIR__ . '/includes/cloudconvert.php';

// Keep an unexpected worker failure from leaving a card stuck at 'processing'.
set_exception_handler(function (Throwable $error) use ($videoId) {
    error_log('Conversion worker failed: ' . $error->getMessage());
    mark_failed($videoId, 'Conversion worker failed. Check PHP CLI extensions, database access and server error log.');
    exit(1);
});

$targetPath = VIDEOS_DIR . '/' . $video['stored_filename'];
// Legacy thumb-only jobs remain supported. New uploads are staged until probed.
if (!$thumbOnly) {
    $source = ORIGINALS_DIR . '/' . $video['source_filename'];
    $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    if (in_array($extension, NATIVE_EXTENSIONS, true) && media_browser_playable($source, $extension)) {
        set_note($videoId, 'Preparing video...');
        if (!rename($source, $targetPath)) {
            mark_failed($videoId, 'Could not save uploaded video. Check disk space and permissions.');
            exit(1);
        }
        $thumbOnly = true;
    } elseif (pathinfo($targetPath, PATHINFO_EXTENSION) !== 'mp4') {
        $video['stored_filename'] = pathinfo($video['stored_filename'], PATHINFO_FILENAME) . '.mp4';
        $stmt = db()->prepare('UPDATE videos SET stored_filename = ? WHERE id = ?');
        $stmt->bind_param('si', $video['stored_filename'], $videoId);
        $stmt->execute();
        $stmt->close();
        $targetPath = VIDEOS_DIR . '/' . $video['stored_filename'];
    }
}

if (!$thumbOnly) {
    $srcPath = ORIGINALS_DIR . '/' . $video['source_filename'];

    if (!is_file($srcPath)) {
        mark_failed($videoId, 'Source file missing on disk.');
        exit(1);
    }

    $ffmpeg = escapeshellarg(FFMPEG_BIN);
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
    //     anyway. Round both dimensions down to even values for yuv420p.
    $scaleFilter = '-vf ' . escapeshellarg('scale=trunc(iw*min(1\,1080/ih)/2)*2:trunc(ih*min(1\,1080/ih)/2)*2');

    // Each attempt: [label, command]. We stop at the first one whose
    // OUTPUT is actually browser-playable, not just the first one that
    // exits 0 - a remux can "succeed" and still contain HEVC/AC3/DTS
    // that no browser can decode.
    $attempts = [
        // Attempt 1: pure remux, no re-encoding at all (fastest, works when
        // the video is already H.264 and audio is already AAC/MP3).
        "$ffmpeg -y -i $inArg -map 0:v:0 -map 0:a:0? -sn -dn -c copy -movflags +faststart $outArg",
        // Attempt 2: keep video as-is, re-encode only the audio track to AAC
        // (handles AC3/DTS audio while the video is already H.264).
        "$ffmpeg -y -i $inArg -map 0:v:0 -map 0:a:0? -sn -dn -c:v copy -c:a aac -b:a 192k -movflags +faststart $outArg",
        // Attempt 3: full transcode - slowest, but works for HEVC, VP9,
        // MPEG-2, or anything else the browser can't decode natively.
        // ultrafast + optional downscale keep this as light as possible on
        // CPU-only hardware.
        "$ffmpeg -y -i $inArg -map 0:v:0 -map 0:a:0? -sn -dn -c:v libx264 -preset ultrafast -crf 23 -pix_fmt yuv420p $scaleFilter -c:a aac -b:a 192k -movflags +faststart $outArg",
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
        if (cloudconvert_transcode($videoId, $srcPath, $targetPath, $cloudErr) && media_browser_playable($targetPath, 'mp4')) {
            $ok = true;
            $usedMode = 'cloud';
        } else {
            if ($cloudErr === '') $cloudErr = 'CloudConvert output was not browser-compatible.';
            $cloudFailure = $cloudErr;
            @unlink($targetPath);
            set_note($videoId, 'Online failed: ' . $cloudFailure . ' Local fallback starting...');
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
        set_note($videoId, ($cloudFailure !== '' ? 'Online failed: ' . $cloudFailure . ' ' : '') . $attemptLabels[$i]);
        [$code, $log] = run($cmd);
        $lastLog = $log;

        clearstatcache(true, $targetPath);
        $produced = ($code === 0 && is_file($targetPath) && filesize($targetPath) >= 1024);

        if ($produced && media_browser_playable($targetPath, 'mp4')) {
            // Verify every attempt, including the full transcode.
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
        mark_failed($videoId, ($cloudFailure !== '' ? 'Online failed: ' . $cloudFailure . ' ' : '') . 'Local conversion failed: ' . substr($lastLog, -200));
        exit(1);
    }
} else {
    if (!is_file($targetPath)) {
        mark_failed($videoId, 'Uploaded file missing on disk.');
        exit(1);
    }
}

// --- Duration via ffprobe ---
$ffprobe = escapeshellarg(FFPROBE_BIN);
$outArg = escapeshellarg($targetPath);
[, $durOut] = run("$ffprobe -v error -show_entries format=duration -of csv=p=0 $outArg");
$duration = (int)round((float)trim($durOut));

// --- Thumbnail: grab a frame ~10% into the video (or 3s in, whichever is smaller) ---
$thumbTime = $duration > 30 ? max(3, (int)($duration * 0.1)) : 1;
$thumbName = pathinfo($video['stored_filename'], PATHINFO_FILENAME) . '.jpg';
$thumbPath = THUMBS_DIR . '/' . $thumbName;
$thumbArg = escapeshellarg($thumbPath);
run(escapeshellarg(FFMPEG_BIN) . " -y -ss $thumbTime -i $outArg -frames:v 1 -vf scale=400:-1 $thumbArg");
if (!is_file($thumbPath)) {
    $thumbName = null;
}

$stmt = db()->prepare('UPDATE videos SET status = "ready", duration_seconds = ?, thumbnail = ?, convert_note = ?, actual_mode = ?, error_message = NULL WHERE id = ?');
$actualMode = $thumbOnly ? null : $usedMode;
$completionNote = $cloudFailure !== '' ? substr('Converted locally after online failed: ' . $cloudFailure, 0, 250) : null;
$stmt->bind_param('isssi', $duration, $thumbName, $completionNote, $actualMode, $videoId);
$stmt->execute();
$stmt->close();

// Keep the source until the ready state was saved successfully.
if (!$thumbOnly && !empty($video['source_filename'])) {
    @unlink(ORIGINALS_DIR . '/' . $video['source_filename']);
}

exit(0);
