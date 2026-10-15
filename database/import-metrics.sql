CREATE TABLE IF NOT EXISTS import_item_metrics (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id BIGINT UNSIGNED NOT NULL,
 job_id BIGINT UNSIGNED NOT NULL,
 site_id BIGINT UNSIGNED NOT NULL,
 result ENUM('imported','unchanged','discovered','retry','failed') NOT NULL,
 response_bytes INT UNSIGNED NOT NULL DEFAULT 0,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
 block_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 link_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 quality_flags JSON NULL,
 processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_import_metric_item (item_id),
 KEY idx_import_metric_job (job_id,result),
 CONSTRAINT fk_import_metric_item FOREIGN KEY (item_id) REFERENCES import_items(id) ON DELETE CASCADE,
 CONSTRAINT fk_import_metric_job FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE,
 CONSTRAINT fk_import_metric_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
