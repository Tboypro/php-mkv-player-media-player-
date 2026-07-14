<?php
require_once __DIR__ . '/config.php';

$id = (int)($_POST['id'] ?? 0);
$position = (int)($_POST['position'] ?? 0);

if ($id <= 0 || $position < 0) {
    json_out(['error' => 'invalid params'], 400);
}

$stmt = db()->prepare('UPDATE videos SET last_position = ? WHERE id = ?');
$stmt->bind_param('ii', $position, $id);
$stmt->execute();
$stmt->close();

json_out(['success' => true]);
