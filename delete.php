<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT stored_filename, source_filename, thumbnail FROM videos WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$video) {
    json_out(['error' => 'not found'], 404);
}

if (!empty($video['stored_filename'])) @unlink(VIDEOS_DIR . '/' . $video['stored_filename']);
if (!empty($video['source_filename'])) @unlink(ORIGINALS_DIR . '/' . $video['source_filename']);
if (!empty($video['thumbnail'])) @unlink(THUMBS_DIR . '/' . $video['thumbnail']);

$stmt = db()->prepare('DELETE FROM videos WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

json_out(['success' => true]);
