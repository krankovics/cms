CREATE TABLE IF NOT EXISTS article_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 article_id BIGINT UNSIGNED NOT NULL,
 editor_id BIGINT UNSIGNED NULL,
 revision_number INT UNSIGNED NOT NULL,
 title VARCHAR(500) NOT NULL,
 lead TEXT NULL,
 content_json LONGTEXT NULL,
 status VARCHAR(30) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_article_revision(article_id,revision_number),
 KEY idx_revision_article(article_id),
 CONSTRAINT fk_revision_article FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
