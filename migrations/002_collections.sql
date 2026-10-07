CREATE TABLE IF NOT EXISTS collections (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS collection_videos (
 collection_id INT UNSIGNED NOT NULL,
 video_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (collection_id, video_id),
 CONSTRAINT collection_membership_fk FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
 CONSTRAINT collection_video_fk FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
