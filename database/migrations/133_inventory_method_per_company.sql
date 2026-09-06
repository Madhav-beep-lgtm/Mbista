-- Migration 133: which inventory system a company keeps is the COMPANY's.
--
-- `inventory_accounting` lived in `settings`, whose primary key is the setting
-- key alone -- one row for the whole installation. On a platform where every
-- client is a separate set of books that is one switch shared by all of them:
-- turning a jewellery shop periodic turned the cafe next door periodic too,
-- silently, from that moment on, and nothing on either screen said so.
--
-- The reason it was global was consolidation -- a group whose books are kept
-- two different ways cannot be consolidated without restating one of them
-- first -- and that reason is real, but it is about a GROUP. It says nothing
-- about two unrelated clients who merely share a server. The consolidation
-- concern is answered where consolidation happens, not by making every shop in
-- the country keep its books the same way.
--
-- NULL means "whatever the installation is set to", so this migration changes
-- no company's behaviour on the day it runs. A company only stops following
-- the installation default once somebody chooses for it.
ALTER TABLE `companies`
  ADD COLUMN `inventory_accounting` ENUM('perpetual', 'periodic') DEFAULT NULL AFTER `is_client_company`;
