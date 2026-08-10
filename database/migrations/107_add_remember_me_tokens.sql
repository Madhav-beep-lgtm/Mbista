-- Persistent-login tokens for the optional "Remember me" flow.
-- MySQL/cPanel compatible; the application schema uses unsigned user IDs.
CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_remember_tokens_user` (`user_id`),
    KEY `idx_remember_tokens_token` (`token_hash`),
    CONSTRAINT `fk_remember_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
