<?php
// Uses this installation's DB_* settings, including the personal database name.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config.php';
try {
    db()->query(file_get_contents(dirname(__DIR__) . '/migrations/001_bookmarks.sql'));
    echo "Bookmarks table is ready. Existing videos and settings were preserved.\n";
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "Bookmark migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
