<?php
require_once __DIR__ . '/includes/library.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); json_out(['error' => 'POST required.'], 405); }
session_start(); $expected = $_SESSION['bookmarks_csrf'] ?? ''; session_write_close();
if (!$expected || !hash_equals($expected, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) json_out(['error' => 'Refresh the page and try again.'], 403);
$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['favorite', 'create_collection', 'rename_collection', 'delete_collection', 'add_to_collection', 'remove_from_collection'], true)) json_out(['error' => 'Unknown action.'], 400);
function positive_id(string $key): int {
    $id = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) json_out(['error' => 'Invalid ' . $key . '.'], 400); return $id;
}
$name = $_POST['name'] ?? '';
if (in_array($action, ['create_collection', 'rename_collection'], true)) {
    $max = 120;
    if (!is_string($name) || !preg_match('//u', $name) || trim($name) === '' || preg_match_all('/./us', $name) > $max) json_out(['error' => "Enter a name of 1–$max characters."], 400);
    $name = trim($name);
}
$videoId = in_array($action, ['favorite', 'add_to_collection', 'remove_from_collection'], true) ? positive_id('video_id') : 0;
$collectionId = in_array($action, ['rename_collection', 'delete_collection', 'add_to_collection', 'remove_from_collection'], true) ? positive_id('collection_id') : 0;
$favorite = $_POST['value'] ?? '';
if ($action === 'favorite' && !in_array($favorite, ['0', '1'], true)) json_out(['error' => 'Invalid favorite value.'], 400);
$conn = db();
try {
    $conn->begin_transaction();
    if ($videoId && !library_query('SELECT id FROM videos WHERE id = ? FOR UPDATE', 'i', [$videoId])->get_result()->fetch_assoc()) { $conn->rollback(); json_out(['error' => 'Video not found.'], 404); }
    if ($collectionId && !library_query('SELECT id FROM collections WHERE id = ? FOR UPDATE', 'i', [$collectionId])->get_result()->fetch_assoc()) { $conn->rollback(); json_out(['error' => 'Collection not found.'], 404); }
    switch ($action) {
        case 'favorite': library_query('UPDATE videos SET is_favorite = ? WHERE id = ?', 'ii', [(int)$favorite, $videoId]); break;
        case 'create_collection': library_query('INSERT INTO collections (name) VALUES (?)', 's', [$name]); $collectionId = $conn->insert_id; break;
        case 'rename_collection': library_query('UPDATE collections SET name = ? WHERE id = ?', 'si', [$name, $collectionId]); break;
        case 'delete_collection': library_query('DELETE FROM collections WHERE id = ?', 'i', [$collectionId]); break;
        case 'add_to_collection': library_query('INSERT INTO collection_videos (collection_id, video_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE video_id = VALUES(video_id)', 'ii', [$collectionId, $videoId]); break;
        case 'remove_from_collection': library_query('DELETE FROM collection_videos WHERE collection_id = ? AND video_id = ?', 'ii', [$collectionId, $videoId]); break;
    }
    $conn->commit(); json_out(['success' => true, 'collection_id' => $collectionId]);
} catch (mysqli_sql_exception $e) {
    $conn->rollback(); error_log('Library action: ' . $e->getMessage()); json_out(['error' => 'Unable to save. Check the database migration and retry.'], 503);
}
