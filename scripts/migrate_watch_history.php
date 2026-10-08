<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config.php';
try {
    $conn = db();
    // Inspect each column so rerunning after an interrupted migration is safe.
    $columns = array_column($conn->query('SHOW COLUMNS FROM videos')->fetch_all(MYSQLI_ASSOC), 'Field');
    foreach (['is_completed' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'last_watched_at' => 'DATETIME NULL'] as $name => $type) {
        if (!in_array($name, $columns, true)) $conn->query("ALTER TABLE videos ADD COLUMN $name $type");
    }
    // Older installations did not record completion or exact viewing time.
    // Keep existing positions; do not infer completion from a zero position.
    echo "Watch history migration complete. Videos, progress and settings preserved.\n";
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Watch history migration failed: " . $e->getMessage() . "\n"); exit(1);
}
