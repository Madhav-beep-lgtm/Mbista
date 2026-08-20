-- An inventory item can BE a recipe ingredient.
--
-- The kitchen buys rice once. Before this it was entered twice: once into
-- inventory, where it is bought and valued, and again into the hospitality
-- ingredient master, where recipes cost it -- two records of the same sack of
-- rice, free to disagree about its name, its unit and what it cost.
--
-- Now the inventory item is the record, and ticking this box makes it available
-- to recipes. Only the things inventory has no opinion about -- what unit a
-- recipe measures it in, how much is lost trimming it -- stay on the ingredient.
ALTER TABLE `inventory_items`
    ADD COLUMN `is_ingredient` TINYINT(1) NOT NULL DEFAULT 0 AFTER `item_type`;

ALTER TABLE `hospitality_ingredients`
    ADD COLUMN `inventory_item_id` INT UNSIGNED DEFAULT NULL AFTER `company_id`,
    ADD UNIQUE KEY `uq_hosp_ingredient_item` (`company_id`, `inventory_item_id`);
