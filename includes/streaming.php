<?php
/**
 * Resolve one byte range. Unsupported/malformed ranges are ignored (200),
 * valid but unsatisfiable ranges return 416. Multipart responses are not used.
 * See RFC 9110, section 14: https://www.rfc-editor.org/rfc/rfc9110.html#section-14
 *
 * @return array{status: int, start: int, length: int}
 */
function video_byte_range(?string $header, int $size): array
{
    $full = ['status' => 200, 'start' => 0, 'length' => $size];
    if ($header === null || !preg_match('/\Abytes=(\d*)-(\d*)\z/i', trim($header), $m)
        || ($m[1] === '' && $m[2] === '')) {
        return $full;
    }

    $unsatisfiable = ['status' => 416, 'start' => 0, 'length' => 0];
    if ($size === 0) {
        return $unsatisfiable;
    }
    if ($m[1] === '') {
        // bytes=-500 means the LAST 500 bytes, not bytes 0 through 500.
        $length = min((int)$m[2], $size);
        return $length === 0 ? $unsatisfiable
            : ['status' => 206, 'start' => $size - $length, 'length' => $length];
    }

    $start = (int)$m[1];
    $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    if ($start >= $size || $start > $end) {
        return $unsatisfiable;
    }
    return ['status' => 206, 'start' => $start, 'length' => $end - $start + 1];
}

/** Stream a trusted on-disk video path after the caller has checked access. */
function stream_video_file(string $path, string $mime): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Content-Length: 0');
        return;
    }

    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(404);
        header('Content-Length: 0');
        return;
    }
    $stat = fstat($fp);
    if ($stat === false) {
        fclose($fp);
        http_response_code(500);
        header('Content-Length: 0');
        return;
    }
    $size = $stat['size'];
    // Range only applies to GET. Without validators we cannot honor If-Range,
    // so send the complete representation when that conditional is present.
    $rangeHeader = $method === 'GET' && !isset($_SERVER['HTTP_IF_RANGE'])
        ? ($_SERVER['HTTP_RANGE'] ?? null) : null;
    $range = video_byte_range($rangeHeader, $size);

    // Compression/output buffering would invalidate byte offsets or consume
    // memory for an entire movie. Keep only one chunk in memory at a time.
    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0 && @ob_end_clean()) {
    }
    if ($range['length'] > 0 && fseek($fp, $range['start']) !== 0) {
        fclose($fp);
        http_response_code(500);
        header('Content-Length: 0');
        return;
    }

    http_response_code($range['status']);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $range['length']);
    if ($range['status'] === 416) {
        header("Content-Range: bytes */$size");
    } elseif ($range['status'] === 206) {
        $end = $range['start'] + $range['length'] - 1;
        header("Content-Range: bytes {$range['start']}-$end/$size");
    }

    if ($method !== 'HEAD' && $range['status'] !== 416) {
        $remaining = $range['length'];
        while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
            $chunk = fread($fp, min(1024 * 1024, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
    }
    fclose($fp);
}
