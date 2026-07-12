-- 032: Public website Insights publishing.
-- Posts authored in the M.Bista superadmin portal (admin/insights.php) and
-- rendered on the public insights/ pages by category. Categories mirror the
-- insights section subpages: articles, tax-updates, audit-insights,
-- accounting-updates, business-advisory, publications, news-events, downloads.

CREATE TABLE IF NOT EXISTS `insight_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `summary` VARCHAR(500) DEFAULT NULL,
  `body` MEDIUMTEXT,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `attachment_name` VARCHAR(200) DEFAULT NULL,
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insight_posts_list` (`category`, `status`, `published_at`),
  CONSTRAINT `fk_insight_posts_author` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
