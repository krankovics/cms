-- Reusable imported image records, per-article captions and a retryable queue.
-- Media bytes stay private until a rights-cleared publication decision.
CREATE TABLE IF NOT EXISTS media_import_sources (
 media_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 site_id BIGINT UNSIGNED NOT NULL,
 source_url VARCHAR(1000) NOT NULL,
 source_url_hash CHAR(64) NOT NULL,
 source_host VARCHAR(255) NOT NULL,
 source_article_url VARCHAR(1000) NOT NULL DEFAULT '',
 width_px INT UNSIGNED NOT NULL DEFAULT 0,
 height_px INT UNSIGNED NOT NULL DEFAULT 0,
 sha256 CHAR(64) NOT NULL,
 photographer VARCHAR(255) NOT NULL DEFAULT '',
 copyright_holder VARCHAR(255) NOT NULL DEFAULT '',
 rights_status ENUM('unknown','cleared','restricted') NOT NULL DEFAULT 'unknown',
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_media_original_url (site_id,source_url_hash),
 KEY idx_media_source_host (source_host),
 KEY idx_media_import_sha (sha256),
 FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
 FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS article_media_uses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 site_id BIGINT UNSIGNED NOT NULL,
 article_id BIGINT UNSIGNED NOT NULL,
 media_id BIGINT UNSIGNED NOT NULL,
 role ENUM('cover','inline') NOT NULL DEFAULT 'inline',
 original_url VARCHAR(1000) NOT NULL,
 alt_text VARCHAR(500) NOT NULL DEFAULT '',
 caption TEXT NULL,
 photo_credit VARCHAR(500) NOT NULL DEFAULT '',
 position_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_article_media_role (article_id,media_id,role),
 KEY idx_article_media_site (site_id,article_id),
 KEY idx_article_media_media (media_id),
 FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
 FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
 FOREIGN KEY(media_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS media_import_queue (
 site_id BIGINT UNSIGNED NOT NULL,
 article_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 next_offset SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 found_count INT UNSIGNED NOT NULL DEFAULT 0,
 imported_count INT UNSIGNED NOT NULL DEFAULT 0,
 failed_count INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_error VARCHAR(500) NOT NULL DEFAULT '',
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (site_id,article_id),
 KEY idx_media_queue_status (status,available_at),
 FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
 FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;