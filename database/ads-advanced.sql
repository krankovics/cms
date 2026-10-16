CREATE TABLE IF NOT EXISTS ad_zone_rules (
 zone_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 position_type ENUM('manual','header','footer','sidebar','paragraph','css','sticky') NOT NULL DEFAULT 'manual',
 position_value SMALLINT UNSIGNED NOT NULL DEFAULT 3,
 css_selector VARCHAR(255) NOT NULL DEFAULT '',
 device_target ENUM('all','desktop','tablet','mobile') NOT NULL DEFAULT 'all',
 page_types VARCHAR(160) NOT NULL DEFAULT 'home,section,tag,article',
 width SMALLINT UNSIGNED NOT NULL DEFAULT 300,
 height SMALLINT UNSIGNED NOT NULL DEFAULT 250,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_ad_zone_rule FOREIGN KEY (zone_id) REFERENCES ad_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_code_creatives (
 creative_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 format ENUM('image','code') NOT NULL DEFAULT 'image',
 code_html MEDIUMTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_ad_code_creative FOREIGN KEY (creative_id) REFERENCES ad_creatives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ad_serves (
 token CHAR(32) NOT NULL PRIMARY KEY,
 site_id BIGINT UNSIGNED NOT NULL,
 zone_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 creative_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 rendered_at DATETIME NULL,
 viewable_at DATETIME NULL,
 clicked_at DATETIME NULL,
 INDEX idx_ad_serves_site_date (site_id,created_at),
 INDEX idx_ad_serves_zone_date (zone_id,created_at),
 INDEX idx_ad_serves_campaign_date (campaign_id,created_at),
 INDEX idx_ad_serves_creative_date (creative_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cms_page_analytics_daily (
 site_id BIGINT UNSIGNED NOT NULL,
 event_date DATE NOT NULL,
 page_path VARCHAR(255) NOT NULL,
 page_kind VARCHAR(30) NOT NULL DEFAULT 'home',
 device ENUM('desktop','tablet','mobile') NOT NULL DEFAULT 'desktop',
 pageviews BIGINT UNSIGNED NOT NULL DEFAULT 0,
 engaged_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
 engaged_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
 scroll_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(site_id,event_date,page_path,device),
 INDEX idx_cms_page_analytics_day (event_date,site_id),
 CONSTRAINT fk_page_analytics_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;