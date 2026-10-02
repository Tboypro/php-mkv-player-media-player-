<?php
require_once __DIR__ . '/config.php';
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_out(['error' => 'GET or POST required.'], 405);
}
if ($method === 'POST') {
    session_start();
    $expected = $_SESSION['bookmarks_csrf'] ?? '';
    session_write_close();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        json_out(['error' => 'Your session changed. Refresh the page and try again.'], 403);
    }
}
$input = $method === 'GET' ? $_GET : $_POST;
$videoId = filter_var($input['video_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($videoId === false) json_out(['error' => 'A valid video ID is required.'], 400);
$action = $method === 'GET' ? 'list' : ($input['action'] ?? '');
if (!in_array($action, ['list', 'create', 'rename', 'delete'], true) || ($method === 'POST' && $action === 'list')) {
    json_out(['error' => 'Unknown bookmark action.'], 400);
}
$name = '';
$position = 0;
$bookmarkId = 0;
if (in_array($action, ['create', 'rename'], true)) {
    $raw = $input['name'] ?? '';
    if (!is_string($raw) || !preg_match('//u', $raw)) json_out(['error' => 'Invalid bookmark name.'], 400);
    $name = trim($raw);
    // Unicode length without requiring mbstring.
    if (preg_match_all('/./us', $name) > 120) json_out(['error' => 'Use at most 120 characters.'], 400);
}
if ($action === 'create') {
    $position = filter_var($input['position'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($position === false) json_out(['error' => 'A valid timestamp is required.'], 400);
}
if (in_array($action, ['rename', 'delete'], true)) {
    $bookmarkId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($bookmarkId === false) json_out(['error' => 'A valid bookmark ID is required.'], 400);
}
$conn = db();
$writing = $method === 'POST';
try {
    if ($writing) $conn->begin_transaction();
    // Serialise writes with video deletion; the foreign key removes child bookmarks.
    $stmt = $conn->prepare('SELECT duration_seconds, status FROM videos WHERE id = ?' . ($writing ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $videoId);
    $stmt->execute();
    $video = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$video || $video['status'] !== 'ready') {
        if ($writing) $conn->rollback();
        json_out(['error' => 'Ready video not found.'], 404);
    }
    if ($action === 'create') {
        if ((int)$video['duration_seconds'] <= 0 || $position > (int)$video['duration_seconds']) {
            $conn->rollback();
            json_out(['error' => 'Timestamp is outside this video or its duration is unavailable.'], 400);
        }
        $stmt = $conn->prepare('INSERT INTO video_bookmarks (video_id, position_seconds, name) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $videoId, $position, $name);
        $stmt->execute();
        $bookmarkId = $conn->insert_id;
        $stmt->close();
    } elseif ($action === 'rename' || $action === 'delete') {
        $stmt = $conn->prepare('SELECT id FROM video_bookmarks WHERE id = ? AND video_id = ?');
        $stmt->bind_param('ii', $bookmarkId, $videoId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) {
            $conn->rollback();
            json_out(['error' => 'Bookmark not found for this video.'], 404);
        }
        if ($action === 'rename') {
            $stmt = $conn->prepare('UPDATE video_bookmarks SET name = ? WHERE id = ? AND video_id = ?');
            $stmt->bind_param('sii', $name, $bookmarkId, $videoId);
        } else {
            $stmt = $conn->prepare('DELETE FROM video_bookmarks WHERE id = ? AND video_id = ?');
            $stmt->bind_param('ii', $bookmarkId, $videoId);
        }
        $stmt->execute();
        $stmt->close();
    }
    if ($writing) $conn->commit();
    if ($action !== 'list') json_out(['success' => true, 'id' => $bookmarkId], $action === 'create' ? 201 : 200);
    $stmt = $conn->prepare('SELECT id, position_seconds, name FROM video_bookmarks WHERE video_id = ? ORDER BY position_seconds, id');
    $stmt->bind_param('i', $videoId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json_out(['bookmarks' => $rows]);
} catch (mysqli_sql_exception $e) {
    if ($writing) $conn->rollback();
    error_log('Bookmark database error: ' . $e->getMessage());
    json_out(['error' => $e->getCode() === 1146
        ? 'Bookmarks need a database update. Run php scripts/migrate_bookmarks.php in your player folder.'
        : 'Bookmarks could not be saved or loaded. Please try again.'], 503);
}
