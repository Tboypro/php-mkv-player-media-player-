<?php
/** Probe both streams in one call; a probe error must never mean "no audio". */
function media_browser_playable(string $path, string $container): bool
{
    $cmd = escapeshellarg(FFPROBE_BIN) . ' -v error -show_streams -of json ' . escapeshellarg($path) . ' 2>/dev/null';
    exec($cmd, $lines, $code);
    if ($code !== 0) return false;
    $data = json_decode(implode("\n", $lines), true);
    $video = null; $audio = null;
    foreach ($data['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && $video === null) $video = $stream;
        if (($stream['codec_type'] ?? '') === 'audio' && $audio === null) $audio = $stream;
    }
    if (!$video) return false;
    $v = $video['codec_name'] ?? '';
    $a = $audio['codec_name'] ?? null;
    // Conservative cross-browser baseline for MP4. H.264 10-bit/4:4:4 needs transcoding.
    if ($container === 'mp4' || $container === 'm4v') {
        return $v === 'h264' && ($video['pix_fmt'] ?? '') === 'yuv420p'
            && ($a === null || in_array($a, ['aac', 'mp3'], true));
    }
    return $container === 'webm' && in_array($v, ['vp8', 'vp9', 'av1'], true)
        && ($a === null || in_array($a, ['opus', 'vorbis'], true));
}

/** PHP_BINARY may be php-fpm or Apache, which cannot run a background CLI script. */
function conversion_php_binary(): ?string
{
    $candidates = defined('PHP_CLI_BIN') ? [PHP_CLI_BIN] : [];
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) $candidates[] = PHP_BINARY;
    $candidates[] = PHP_BINDIR . '/php';
    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }
    return null;
}
