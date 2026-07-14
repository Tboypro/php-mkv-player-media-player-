<?php
require_once __DIR__ . '/config.php';

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

$isNative = in_array($ext, NATIVE_EXTENSIONS, true);

// Which mode is active right now - locked onto this video so a later toggle
// change doesn't affect videos already queued/converting.
$convertMode = $isNative ? 'local' : get_setting('conversion_mode', 'local');

if ($isNative) {
    // Already browser-friendly - move straight into the videos folder, no conversion needed.
    $storedName = $unique . '.' . $ext;
    $destPath = VIDEOS_DIR . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        json_out(['error' => 'Could not save uploaded file to disk.'], 500);
    }
    $status = 'ready';
    $sourceName = null;
} else {
    // Needs remuxing/transcoding (e.g. mkv, avi, mov, flv, wmv) - stash original, convert.php will do the work.
    $sourceName = $unique . '_src.' . $ext;
    $srcPath = ORIGINALS_DIR . '/' . $sourceName;
    if (!move_uploaded_file($file['tmp_name'], $srcPath)) {
        json_out(['error' => 'Could not save uploaded file to disk.'], 500);
    }
    $storedName = $unique . '.mp4'; // target filename convert.php will produce
    $status = 'processing';
}

$stmt = db()->prepare(
    'INSERT INTO videos (title, original_filename, stored_filename, source_filename, filesize_bytes, status, convert_mode)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$filesize = $file['size'];
$stmt->bind_param('ssssiss', $title, $safeOriginalName, $storedName, $sourceName, $filesize, $status, $convertMode);
$stmt->execute();
$videoId = $stmt->insert_id;
$stmt->close();

if (!$isNative) {
    // Fire the conversion off in the background so this request returns immediately.
    $script = escapeshellarg(BASE_DIR . '/convert.php');
    $phpBin = escapeshellarg(PHP_BINARY);
    $cmd = "$phpBin $script " . (int)$videoId . " > /dev/null 2>&1 &";
    shell_exec($cmd);
} else {
    // Still generate a thumbnail + duration for native files, in the background.
    $script = escapeshellarg(BASE_DIR . '/convert.php');
    $phpBin = escapeshellarg(PHP_BINARY);
    $cmd = "$phpBin $script " . (int)$videoId . " thumb-only > /dev/null 2>&1 &";
    shell_exec($cmd);
}

json_out(['success' => true, 'id' => $videoId, 'status' => $status]);