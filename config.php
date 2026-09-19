<?php
/**
 * Q MP4 Player - config.php
 * Central place for DB connection, paths and small helpers.
 * Edit the DB_* constants below to match your local MySQL setup.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---- Database ----
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'q_mp4_player');

// ---- Filesystem paths ----
define('BASE_DIR', __DIR__);
define('ORIGINALS_DIR', BASE_DIR . '/uploads/originals');
define('VIDEOS_DIR', BASE_DIR . '/uploads/videos');
define('THUMBS_DIR', BASE_DIR . '/uploads/thumbnails');
 
// ---- ffmpeg / ffprobe binaries ----
// If these aren't on your PATH inside Crostini, set full paths e.g. '/usr/bin/ffmpeg'
define('FFMPEG_BIN', 'ffmpeg');
define('FFPROBE_BIN', 'ffprobe');
 
// Formats accepted for upload (extension check, first line of defense only)
define('ALLOWED_EXTENSIONS', ['mkv', 'mp4', 'webm', 'mov', 'avi', 'm4v', 'flv', 'wmv']);
 
// Formats that Chrome can already play natively - skipped from re-encoding,
// just copied straight into uploads/videos.
define('NATIVE_EXTENSIONS', ['mp4', 'webm', 'm4v']);
 
// ---- CloudConvert (optional "online" conversion) ----
// CloudConvert API configuration (optional)
// Leave blank to keep the "Online" toggle disabled until you add one.
define('CLOUDCONVERT_API_KEY', '');
define('CLOUDCONVERT_API_BASE', 'https://api.cloudconvert.com/v2');
 
date_default_timezone_set('Africa/Lagos');
 
/**
 * Read a value from the `settings` table (see migration_convert_mode.sql).
 */
function get_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['setting_value'] : $default;
}
 
/**
 * Write/update a value in the `settings` table.
 */
function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}
 
function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            die('Database connection failed. Check DB_* settings in config.php and confirm MySQL is running. (' . h($e->getMessage()) . ')');
        }
    }
    return $conn;
}
 
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
 
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
 
function format_duration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}
 
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $val = $bytes;
    while ($val >= 1024 && $i < count($units) - 1) {
        $val /= 1024;
        $i++;
    }
    return round($val, 1) . ' ' . $units[$i];
}
 
foreach ([ORIGINALS_DIR, VIDEOS_DIR, THUMBS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}
 
