<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config.php';
try {
    $conn = db();
    $columns = array_column($conn->query('SHOW COLUMNS FROM videos')->fetch_all(MYSQLI_ASSOC), 'Field');
    if (!in_array('is_favorite', $columns, true)) $conn->query('ALTER TABLE videos ADD COLUMN is_favorite TINYINT UNSIGNED NOT NULL DEFAULT 0');
    echo "Favorites migration complete. Existing videos and progress preserved.\n";
} catch (mysqli_sql_exception $e) { fwrite(STDERR, "Favorites migration failed: " . $e->getMessage() . "\n"); exit(1); }
