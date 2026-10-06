<?php
/** CloudConvert v2 transport. No API key or signed URL is included in user errors. */
function cloudconvert_error(string $stage, int $http, array $body = [], string $network = ''): string
{
    $hints = [401 => 'API key rejected', 403 => 'API key lacks permission (task.read/task.write)',
        402 => 'account credits or plan limit', 429 => 'rate limit reached'];
    $detail = $hints[$http] ?? ($body['message'] ?? $body['error'] ?? $network);
    if (!is_string($detail)) $detail = '';
    $detail = preg_replace('~https?://\S+~i', '[URL omitted]', $detail);
    if (defined('CLOUDCONVERT_API_KEY') && CLOUDCONVERT_API_KEY !== '') {
        $detail = str_replace(CLOUDCONVERT_API_KEY, '[key omitted]', $detail);
    }
    $detail = preg_replace('/[\x00-\x1f\x7f]/', ' ', $detail);
    return substr("$stage (HTTP $http)" . ($detail !== '' ? ': ' . $detail : ''), 0, 260);
}

function cloudconvert_request(string $method, string $endpoint, ?array $jsonBody = null): array
{
    $ch = curl_init(rtrim(CLOUDCONVERT_API_BASE, '/') . $endpoint);
    $headers = ['Authorization: Bearer ' . CLOUDCONVERT_API_KEY, 'Accept: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return [0, ['error' => $err]];
    $data = json_decode($resp, true);
    return [$code, is_array($data) ? $data : ['error' => 'Invalid JSON response']];
}

/** A completed transfer requires BOTH cURL success and a 2xx response. */
function cloudconvert_transfer($ch): array
{
    curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 3600,
        CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 120,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $result = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$result !== false && $http >= 200 && $http < 300, $http, $error];
}

function cloudconvert_transcode(int $videoId, string $srcPath, string $targetPath, string &$errorOut): bool
{
    if (trim(CLOUDCONVERT_API_KEY) === '') {
        $errorOut = 'CloudConvert API key is not set in config.php.';
        return false;
    }
    if (!function_exists('curl_init')) {
        $errorOut = 'PHP curl extension is missing in the conversion worker.';
        return false;
    }
    set_note($videoId, 'Contacting CloudConvert...');
    [$code, $job] = cloudconvert_request('POST', '/jobs', ['tasks' => [
        'import-file' => ['operation' => 'import/upload'],
        'convert-file' => ['operation' => 'convert', 'input' => 'import-file',
            'output_format' => 'mp4', 'video_codec' => 'x264', 'audio_codec' => 'aac'],
        'export-file' => ['operation' => 'export/url', 'input' => 'convert-file'],
    ]]);
    if (!in_array($code, [200, 201], true) || empty($job['data']['id']) || empty($job['data']['tasks'])) {
        $errorOut = cloudconvert_error('CloudConvert job creation failed', $code, $job);
        return false;
    }
    $form = null;
    foreach ($job['data']['tasks'] as $task) {
        if (($task['name'] ?? '') === 'import-file') $form = $task['result']['form'] ?? null;
    }
    if (empty($form['url']) || !isset($form['parameters']) || !is_array($form['parameters'])) {
        $errorOut = 'CloudConvert did not return a valid upload form.';
        return false;
    }
    set_note($videoId, 'Uploading to CloudConvert...');
    $fields = $form['parameters'];
    // CloudConvert requires every returned parameter, followed by the file LAST.
    unset($fields['file']);
    $fields['file'] = new CURLFile($srcPath, 'application/octet-stream', basename($srcPath));
    $ch = curl_init($form['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);
    [$ok, $http, $err] = cloudconvert_transfer($ch);
    if (!$ok) {
        $errorOut = cloudconvert_error('CloudConvert upload failed', $http, [], $err);
        return false;
    }

    $start = time();
    $downloadUrl = null;
    $failures = 0;
    while (time() - $start < 1800) {
        set_note($videoId, 'Converting on CloudConvert... (' . (time() - $start) . 's elapsed)');
        [$code, $status] = cloudconvert_request('GET', '/jobs/' . rawurlencode($job['data']['id']));
        if ($code !== 200 || !isset($status['data']['status'])) {
            // Do not spend 30 minutes retrying invalid credentials or a missing job.
            if (($code >= 400 && $code < 500 && !in_array($code, [408, 429], true)) || ++$failures >= 5) {
                $errorOut = cloudconvert_error('Could not check CloudConvert job', $code, $status);
                return false;
            }
            sleep(min(30, 5 * $failures));
            continue;
        }
        $failures = 0;
        if ($status['data']['status'] === 'error') {
            // Task message/code are useful; dumping the entire job exposes signed URLs.
            foreach ($status['data']['tasks'] ?? [] as $task) {
                if (($task['status'] ?? '') === 'error') {
                    $message = ($task['name'] ?? 'task') . ': ' . ($task['code'] ?? '') . ' ' . ($task['message'] ?? 'failed');
                    $errorOut = cloudconvert_error('CloudConvert job failed', $code, ['message' => $message]);
                    return false;
                }
            }
            $errorOut = 'CloudConvert job failed without a task error message.';
            return false;
        }
        if ($status['data']['status'] === 'finished') {
            foreach ($status['data']['tasks'] ?? [] as $task) {
                if (($task['name'] ?? '') === 'export-file') $downloadUrl = $task['result']['files'][0]['url'] ?? null;
            }
            break;
        }
        sleep(5);
    }
    if (!$downloadUrl) {
        $errorOut = 'CloudConvert timed out or produced no downloadable output.';
        return false;
    }
    set_note($videoId, 'Downloading converted file from CloudConvert...');
    $part = $targetPath . '.part';
    $fp = @fopen($part, 'wb');
    if (!$fp) {
        $errorOut = 'Could not open converted file for writing. Check disk space and permissions.';
        return false;
    }
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5]);
    [$ok, $http, $err] = cloudconvert_transfer($ch);
    fclose($fp);
    clearstatcache(true, $part);
    if (!$ok || !is_file($part) || filesize($part) < 1024) {
        @unlink($part);
        $errorOut = cloudconvert_error('CloudConvert download failed or was incomplete', $http, [], $err);
        return false;
    }
    if (!rename($part, $targetPath)) {
        @unlink($part);
        $errorOut = 'Could not save CloudConvert output. Check disk space and permissions.';
        return false;
    }
    return true;
}
