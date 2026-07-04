CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    requested_ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_password_resets_token_hash (token_hash),
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expiry (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
