CREATE TABLE IF NOT EXISTS site_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 site_id BIGINT UNSIGNED NOT NULL,
 template_key ENUM('home','section','tag','article','page','login') NOT NULL,
 draft_json LONGTEXT NOT NULL,
 published_json LONGTEXT NULL,
 revision_number INT UNSIGNED NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 published_at DATETIME NULL,
 UNIQUE KEY uq_site_template(site_id,template_key),
 CONSTRAINT fk_site_template_site FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE,
 CONSTRAINT fk_site_template_editor FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS site_template_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 template_id BIGINT UNSIGNED NOT NULL,
 revision_number INT UNSIGNED NOT NULL,
 editor_id BIGINT UNSIGNED NULL,
 event ENUM('save','publish','restore') NOT NULL,
 content_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_site_template_revision(template_id,revision_number),
 KEY idx_template_rev_event(template_id,event),
 FOREIGN KEY(template_id) REFERENCES site_templates(id) ON DELETE CASCADE,
 FOREIGN KEY(editor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS site_menu_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 site_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(120) NOT NULL,
 url VARCHAR(1000) NOT NULL,
 position INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 KEY idx_menu_site_order(site_id,position,id),
 FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;