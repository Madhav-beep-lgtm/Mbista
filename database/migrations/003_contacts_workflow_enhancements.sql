ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(190) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS assigned_admin_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS replied_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    details TEXT DEFAULT NULL,
    actor_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_entity (entity_type, entity_id, created_at),
    KEY idx_activity_actor (actor_id),
    CONSTRAINT fk_activity_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
    ADD CONSTRAINT fk_contacts_assigned_admin
    FOREIGN KEY (assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL;
