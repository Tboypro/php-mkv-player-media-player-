<?php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); json_out(['error'=>'POST required'],405); }
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
if ($id === false || $position === false) json_out(['error'=>'invalid params'],400);
$completed = ($_POST['completed'] ?? '0') === '1' ? 1 : 0;
$started = ($_POST['started'] ?? '0') === '1' || $position > 0 || $completed ? 1 : 0;
$stmt = db()->prepare('UPDATE videos SET last_position = IF(duration_seconds > 0, LEAST(?, duration_seconds), ?), is_completed = IF(?, ?, is_completed), last_watched_at = IF(?, NOW(), last_watched_at) WHERE id = ? AND status = \'ready\'');
$stmt->bind_param('iiiiii', $position, $position, $started, $completed, $started, $id);
$stmt->execute(); $stmt->close(); json_out(['success'=>true]);
