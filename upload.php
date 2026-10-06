<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/media_conversion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['video']['error'] ?? 'no file';
    json_out(['error' => 'Upload failed (code ' . $err . '). Check php.ini upload_max_filesize / post_max_size.'], 400);
}

$file = $_FILES['video'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
    json_out(['error' => 'Unsupported file type: .' . $ext], 400);
}

$title = trim($_POST['title'] ?? '');
if ($title === '') {
    $title = pathinfo($file['name'], PATHINFO_FILENAME);
}

$unique = date('Ymd_His') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
$safeOriginalName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);

// Capture this upload's visible choice, independent of other tabs/settings changes.
$convertMode = $_POST['conversion_mode'] ?? get_setting('conversion_mode', 'local');
if (!in_array($convertMode, ['local', 'cloud'], true)) {
    json_out(['error' => 'Invalid conversion mode.'], 400);
}
$phpCli = conversion_php_binary();
if (!$phpCli || !function_exists('shell_exec')) {
    json_out(['error' => 'Cannot start conversion worker. Configure PHP_CLI_BIN in config.php to your PHP CLI executable and enable shell_exec.'], 500);
}
// Always probe first, including MP4/WebM: an extension does not identify its codecs.
$sourceName = $unique . '_src.' . $ext;
$srcPath = ORIGINALS_DIR . '/' . $sourceName;
if (!move_uploaded_file($file['tmp_name'], $srcPath)) {
    json_out(['error' => 'Could not save uploaded file to disk.'], 500);
}
$storedName = $unique . '.' . (in_array($ext, NATIVE_EXTENSIONS, true) ? $ext : 'mp4');
$status = 'processing';

$stmt = db()->prepare(
    'INSERT INTO videos (title, original_filename, stored_filename, source_filename, filesize_bytes, status, convert_mode)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$filesize = $file['size'];
$stmt->bind_param('ssssiss', $title, $safeOriginalName, $storedName, $sourceName, $filesize, $status, $convertMode);
$stmt->execute();
$videoId = $stmt->insert_id;
$stmt->close();

$script = escapeshellarg(BASE_DIR . '/convert.php');
$phpBin = escapeshellarg($phpCli);
// Worker catches runtime errors and records them on the video card.
$cmd = "$phpBin $script " . (int)$videoId . " > /dev/null 2>&1 & echo $!";
$pid = trim((string)shell_exec($cmd));
if (!ctype_digit($pid)) {
    $message = 'Could not start conversion worker. Check PHP CLI configuration and server permissions.';
    $stmt = db()->prepare('UPDATE videos SET status = "failed", error_message = ? WHERE id = ?');
    $stmt->bind_param('si', $message, $videoId);
    $stmt->execute();
    json_out(['error' => $message], 500);
}

json_out(['success' => true, 'id' => $videoId, 'status' => $status]);
