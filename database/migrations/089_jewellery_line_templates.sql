-- 089: Saved line sets, so a repeated document is not retyped every time.
--
-- A shop bills the same shapes over and over — a chain at 22K with 8% wastage
-- and a flat making charge, a ring set with a stone. Typing those columns from
-- scratch each time is where the mistakes come from, and the mistakes land in
-- the books.
--
-- A template is just the LINES, stored as they would have been posted from the
-- grid. It carries no party, no date and no document number, because those are
-- what make one document different from another.
--
-- doc_type keeps a purchase template out of the sale form's list: the two grids
-- look alike but a purchase has no diamond columns to fill.

CREATE TABLE IF NOT EXISTS `jewellery_line_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `doc_type` ENUM('sale','purchase') NOT NULL DEFAULT 'sale',
  `name` VARCHAR(120) NOT NULL,
  `lines_json` MEDIUMTEXT NOT NULL,
  `line_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_jw_template` (`company_id`, `doc_type`, `name`),
  KEY `idx_jw_template_company` (`company_id`, `doc_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
