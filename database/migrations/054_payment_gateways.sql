-- Online payment gateways for client invoice collection (eSewa, Khalti,
-- Fonepay, Stripe). payment_gateways holds one credential row per company +
-- provider; payment_intents tracks each client pay attempt and links to the
-- invoice_payment_requests row once the provider confirms the money.

CREATE TABLE IF NOT EXISTS `payment_gateways` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `provider` ENUM('esewa','khalti','fonepay','stripe') NOT NULL,
  `mode` ENUM('test','live') NOT NULL DEFAULT 'test',
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `merchant_code` VARCHAR(190) DEFAULT NULL,
  `secret_key` VARCHAR(255) DEFAULT NULL,
  `public_key` VARCHAR(255) DEFAULT NULL,
  `extra_config` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_gateway` (`company_id`, `provider`),
  CONSTRAINT `fk_payment_gateways_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_intents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `provider` VARCHAR(30) NOT NULL,
  `mode` ENUM('test','live') NOT NULL DEFAULT 'test',
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'NPR',
  `token` VARCHAR(80) NOT NULL,
  `provider_ref` VARCHAR(190) DEFAULT NULL,
  `status` ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `client_user_id` INT UNSIGNED DEFAULT NULL,
  `payment_request_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_intent_token` (`token`),
  KEY `idx_payment_intents_invoice` (`invoice_id`),
  KEY `idx_payment_intents_company` (`company_id`, `status`),
  CONSTRAINT `fk_payment_intents_company` FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_intents_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `task_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
