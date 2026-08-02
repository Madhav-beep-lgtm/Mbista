-- 105: Jewellery tag printing on a Zebra ZD230 (203 dpi).
--
-- A jewellery tag is the one label in this system whose geometry the software
-- cannot know. Tag stock is bought by the roll from whoever the shop buys from:
-- dumbbell strips come in 65x11, 75x12 and a dozen other sizes, the gap between
-- them varies, and the way the roll is wound decides whether text runs along
-- the strip or across it. Getting that wrong prints every tag misaligned.
--
-- So none of it is hard-coded. The measurements live here, per company, and the
-- print screen offers a calibration tag to print, look at, and adjust — which is
-- the only reliable way to land a label on stock nobody has measured for you.
--
-- Millimetres, not dots. The shop owner has a ruler, not a dot counter; the
-- renderer multiplies by dpi/25.4 when it builds the ZPL. Storing dots would
-- also silently break the day a 300 dpi printer replaces the 203.

ALTER TABLE `jewellery_settings`
  -- Printed on the tag itself. Usually the company name, but shops trading under
  -- a shorter shopfront name need the short one — 12mm of tag is not generous.
  ADD COLUMN `tag_shop_name` VARCHAR(60) DEFAULT NULL AFTER `masters_seeded`,
  -- The physical stock, and the one place a label gets misread: WIDTH is across
  -- the print head (ZPL ^PW), HEIGHT is along the direction of feed (^LL). For a
  -- 75mm dumbbell strip fed end-first that means width 12, height 75 — not the
  -- other way round. The print screen labels them in those words rather than
  -- "width" and "height" so nobody has to remember which is which.
  ADD COLUMN `tag_width_mm` DECIMAL(6,1) NOT NULL DEFAULT 12.0 AFTER `tag_shop_name`,
  ADD COLUMN `tag_height_mm` DECIMAL(6,1) NOT NULL DEFAULT 75.0 AFTER `tag_width_mm`,
  ADD COLUMN `tag_gap_mm` DECIMAL(6,1) NOT NULL DEFAULT 3.0 AFTER `tag_height_mm`,
  -- How much of each end is the barcode wing that folds around the ornament.
  ADD COLUMN `tag_wing_mm` DECIMAL(6,1) NOT NULL DEFAULT 22.0 AFTER `tag_gap_mm`,
  ADD COLUMN `tag_dpi` SMALLINT UNSIGNED NOT NULL DEFAULT 203 AFTER `tag_wing_mm`,
  -- Darkness 0-30 and speed in inches/sec. Thermal tags on synthetic stock
  -- usually need more heat and less speed than paper, or the bars come out grey
  -- and the scanner refuses them.
  ADD COLUMN `tag_darkness` TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER `tag_dpi`,
  ADD COLUMN `tag_speed` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `tag_darkness`,
  -- Which way the text runs relative to the feed. Decided by how the roll is
  -- wound, which is why it is a setting and not an assumption.
  ADD COLUMN `tag_rotation` ENUM('0','90','180','270') NOT NULL DEFAULT '0' AFTER `tag_speed`,
  -- Nudge, for when the stock is loaded a millimetre off. Calibration prints a
  -- tag with a border so this can be dialled in by eye.
  ADD COLUMN `tag_offset_x_mm` DECIMAL(5,1) NOT NULL DEFAULT 0.0 AFTER `tag_rotation`,
  ADD COLUMN `tag_offset_y_mm` DECIMAL(5,1) NOT NULL DEFAULT 0.0 AFTER `tag_offset_x_mm`,
  -- Gap/notch sensing suits die-cut tags; continuous suits plain strip.
  ADD COLUMN `tag_media` ENUM('gap','continuous','mark') NOT NULL DEFAULT 'gap' AFTER `tag_offset_y_mm`,
  -- A stone line on a plain gold piece is a wasted line out of the four or five
  -- that fit, so it can be suppressed when it would read "Stone : 0.000".
  ADD COLUMN `tag_hide_empty_stone` TINYINT(1) NOT NULL DEFAULT 1 AFTER `tag_media`;
