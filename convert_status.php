<?php
require_once __DIR__ . '/config.php';

$ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
if (empty($ids)) {
    json_out(['videos' => []]);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$stmt = db()->prepare("SELECT id, status, duration_seconds, thumbnail, error_message, convert_note, actual_mode FROM videos WHERE id IN ($placeholders)");
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$result = $stmt->get_result();

$videos = [];
while ($row = $result->fetch_assoc()) {
    $videos[] = [
        'id' => (int)$row['id'],
        'status' => $row['status'],
        'duration' => (int)$row['duration_seconds'],
        'duration_formatted' => format_duration((int)$row['duration_seconds']),
        'thumbnail' => $row['thumbnail'],
        'error' => $row['error_message'],
        'note' => $row['convert_note'],
        'actual_mode' => $row['actual_mode'],
    ];
}
$stmt->close();

json_out(['videos' => $videos]);