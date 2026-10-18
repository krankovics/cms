-- Isolated AdOcean inventory. A source import never activates delivery.
CREATE TABLE IF NOT EXISTS ad_external_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 filename VARCHAR(190) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 row_count INT UNSIGNED NOT NULL,
 master_count INT UNSIGNED NOT NULL,
 leaf_count INT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_external_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_external_nodes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 parent_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 folder_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 name VARCHAR(255) NOT NULL,
 node_type ENUM('FOLDER','MASTER','LEAF') NOT NULL,
 source_path TEXT NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 is_slave TINYINT(1) NOT NULL DEFAULT 0,
 master_code_id VARCHAR(190) NOT NULL DEFAULT '',
 leaf_code_id VARCHAR(190) NOT NULL DEFAULT '',
 source_code TEXT NULL,
 UNIQUE KEY uq_external_node (import_id,source_id),
 KEY idx_external_folder (import_id,folder_source_id,node_type),
 KEY idx_external_master (import_id,master_code_id),
 CONSTRAINT fk_external_node_import FOREIGN KEY (import_id) REFERENCES ad_external_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_external_sites (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 import_id BIGINT UNSIGNED NOT NULL,
 folder_source_id BIGINT UNSIGNED NOT NULL,
 folder_name VARCHAR(255) NOT NULL,
 site_id BIGINT UNSIGNED NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_external_folder_map (import_id,folder_source_id),
 KEY idx_external_site_enabled (site_id,enabled),
 CONSTRAINT fk_external_map_import FOREIGN KEY (import_id) REFERENCES ad_external_imports(id) ON DELETE CASCADE,
 CONSTRAINT fk_external_map_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
