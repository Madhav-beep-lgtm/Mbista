-- 065: Merge service agreements into Work Portal contracts.
-- Each service_contracts row (the wiring record: client link, task linkage,
-- billing status) can carry exactly ONE bilingual service agreement, which is
-- its printable contract document.
ALTER TABLE `service_agreements`
  ADD COLUMN `contract_id` INT UNSIGNED DEFAULT NULL AFTER `client_id`,
  ADD UNIQUE KEY `uq_agreements_contract` (`contract_id`),
  ADD CONSTRAINT `fk_agreements_contract` FOREIGN KEY (`contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL;
