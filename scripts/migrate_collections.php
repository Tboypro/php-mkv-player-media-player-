<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config.php';
try {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/002_collections.sql');
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') db()->query($statement);
    echo "Collections migration complete. Existing videos preserved.\n";
} catch (mysqli_sql_exception $e) { fwrite(STDERR, "Collections migration failed: " . $e->getMessage() . "\n"); exit(1); }
