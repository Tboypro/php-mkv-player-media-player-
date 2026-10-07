<?php
require_once __DIR__ . '/includes/library.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); json_out(['error' => 'POST required.'], 405); }
session_start(); $expected = $_SESSION['bookmarks_csrf'] ?? ''; session_write_close();
if (!$expected || !hash_equals($expected, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) json_out(['error' => 'Refresh the page and try again.'], 403);
$action = $_POST['action'] ?? '';
if ($action !== 'favorite') json_out(['error' => 'Unknown action.'], 400);
function positive_id(string $key): int {
    $id = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) json_out(['error' => 'Invalid ' . $key . '.'], 400); return $id;
}
$videoId = positive_id('video_id');
$favorite = $_POST['value'] ?? '';
if (!in_array($favorite, ['0', '1'], true)) json_out(['error' => 'Invalid favorite value.'], 400);
$conn = db();
try {
    $conn->begin_transaction();
    if (!library_query('SELECT id FROM videos WHERE id = ? FOR UPDATE', 'i', [$videoId])->get_result()->fetch_assoc()) { $conn->rollback(); json_out(['error' => 'Video not found.'], 404); }
    library_query('UPDATE videos SET is_favorite = ? WHERE id = ?', 'ii', [(int)$favorite, $videoId]);
    $conn->commit(); json_out(['success' => true]);
} catch (mysqli_sql_exception $e) {
    $conn->rollback(); error_log('Library action: ' . $e->getMessage()); json_out(['error' => 'Unable to save. Check the database migration and retry.'], 503);
}
