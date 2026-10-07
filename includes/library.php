<?php
require_once dirname(__DIR__) . "/config.php";
function library_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
    if (empty($_SESSION['bookmarks_csrf'])) $_SESSION['bookmarks_csrf'] = bin2hex(random_bytes(32));
    $token = $_SESSION['bookmarks_csrf'];
    session_write_close();
    return $token;
}
function library_query(string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = db()->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute(); return $stmt;
}
function library_ready(): void {
    try { db()->query('SELECT is_favorite FROM videos LIMIT 0'); }
    catch (mysqli_sql_exception $e) {
        http_response_code(503);
        exit('Favorites update needed. In your player folder run: php scripts/migrate_favorites.php');
    }
}
function progress_percent(array $v): float {
    return (int)$v['duration_seconds'] > 0 ? min(100, max(0, 100 * (int)$v['last_position'] / (int)$v['duration_seconds'])) : 0;
}
function thumb(array $v, string $class = ''): void {
    if ($v['thumbnail']) echo '<img class="' . h($class) . '" src="uploads/thumbnails/' . rawurlencode(basename($v['thumbnail'])) . '" alt="" loading="lazy">';
    else echo '<div class="thumbnail-fallback" aria-hidden="true">' . ui_icon('play') . '</div>';
}
function ui_icon(string $name): string {
    $paths = [
        'play' => '<path d="m9 5 11 7-11 7z" fill="currentColor" stroke="none"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'folder' => '<path d="M3 6h7l2 2h9v12H3z"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'grid' => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>',
        'list' => '<path d="M3 4h18v5H3zM3 15h18v5H3z"/>',
        'back' => '<path d="m10 5-7 7 7 7M3 12h18"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'more' => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>'
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
function brand(): string { return '<a class="q-brand" href="index.php"><span class="q-mark">' . ui_icon('play') . '</span>Q Player</a>'; }
