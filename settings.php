<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(['conversion_mode' => get_setting('conversion_mode', 'local')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['conversion_mode'] ?? '';

    if (!in_array($mode, ['local', 'cloud'], true)) {
        json_out(['error' => 'conversion_mode must be "local" or "cloud"'], 400);
    }

    if ($mode === 'cloud' && CLOUDCONVERT_API_KEY === '') {
        json_out(['error' => 'Add your CloudConvert API key to CLOUDCONVERT_API_KEY in config.php before switching to online conversion.'], 400);
    }

    if ($mode === 'cloud' && !function_exists('curl_init')) {
        json_out(['error' => 'Online conversion needs the PHP curl extension. Enable it for both your web server and PHP CLI.'], 400);
    }

    set_setting('conversion_mode', $mode);
    json_out(['success' => true, 'conversion_mode' => $mode]);
}

json_out(['error' => 'GET or POST only'], 405);