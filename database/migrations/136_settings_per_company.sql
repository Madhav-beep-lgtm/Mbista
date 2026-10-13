-- A setting can belong to one company.
--
-- `settings` was keyed on setting_key alone: one row for the whole
-- installation, 53 of them, shared by 19 companies. So a client's PAN, VAT
-- number, bank account, invoice prefix and VAT rate were not that client's at
-- all — they were everybody's, and setting one set it for every other set of
-- books on the server.
--
-- This is the same fault that was found in `inventory_accounting`, where one
-- switch silently turned every client's books periodic. It was fixed there by
-- moving the choice onto the company. There are 53 keys here rather than one,
-- so the scope moves onto the row instead.
--
-- company_id 0 is the installation default, NOT a company. It has to be 0
-- rather than NULL: MySQL permits any number of NULLs in a unique key, so a
-- nullable column would allow two competing defaults for the same key and
-- nothing would say which won.
--
-- Nothing changes on the day this runs. Every existing row becomes the
-- installation default, every lookup falls back to it, and a company only
-- stops following it once somebody sets a value for that company.
ALTER TABLE `settings`
  ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 0 FIRST,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (`setting_key`, `company_id`);
