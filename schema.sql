-- ==========================================================
-- Q MP4 Player
-- Database Schema
--
-- Import:
-- mysql -u root -p < schema.sql
-- ==========================================================

CREATE DATABASE IF NOT EXISTS q_mp4_player
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE q_mp4_player;

-- ==========================================================
-- Videos
-- ==========================================================

CREATE TABLE IF NOT EXISTS videos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,

    -- Original uploaded file
    source_filename VARCHAR(255) NULL,

    -- Browser-playable file
    stored_filename VARCHAR(255) NOT NULL,

    thumbnail VARCHAR(255) NULL,

    filesize_bytes BIGINT UNSIGNED DEFAULT 0,
    duration_seconds INT UNSIGNED DEFAULT 0,

    -- Resume playback position (seconds)
    last_position INT UNSIGNED DEFAULT 0,

    -- Conversion status
    status ENUM('processing','ready','failed')
        DEFAULT 'processing',

    -- Selected conversion mode
    convert_mode ENUM('local','cloud')
        NOT NULL DEFAULT 'local',

    -- Live progress shown in the UI
    convert_note VARCHAR(255) NULL,

    -- Actual method used
    actual_mode ENUM('local','cloud') NULL,

    error_message VARCHAR(500) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- Application Settings
-- ==========================================================

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value)
VALUES ('conversion_mode', 'local')
ON DUPLICATE KEY UPDATE
setting_value = VALUES(setting_value);
