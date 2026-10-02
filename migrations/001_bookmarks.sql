-- Run against the existing player database. No videos or settings are changed.
CREATE TABLE IF NOT EXISTS video_bookmarks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id INT UNSIGNED NOT NULL,
    position_seconds INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX bookmarks_video_position (video_id, position_seconds, id),
    CONSTRAINT bookmarks_video_fk FOREIGN KEY (video_id)
        REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
