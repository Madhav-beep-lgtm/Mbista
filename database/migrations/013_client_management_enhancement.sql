ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE client_profiles
  ADD COLUMN IF NOT EXISTS registration_no VARCHAR(80) DEFAULT NULL AFTER client_code,
  ADD COLUMN IF NOT EXISTS industry_id INT UNSIGNED DEFAULT NULL AFTER registration_no,
  ADD COLUMN IF NOT EXISTS authorized_person_position VARCHAR(140) DEFAULT NULL AFTER pan_no,
  ADD COLUMN IF NOT EXISTS authorized_signatory_name VARCHAR(190) DEFAULT NULL AFTER authorized_person_position,
  ADD COLUMN IF NOT EXISTS contact_number VARCHAR(60) DEFAULT NULL AFTER authorized_signatory_name,
  ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT NULL AFTER contact_number,
  ADD COLUMN IF NOT EXISTS company_logo_path VARCHAR(255) DEFAULT NULL AFTER website,
  ADD COLUMN IF NOT EXISTS client_status ENUM('active', 'suspended', 'inactive') NOT NULL DEFAULT 'active' AFTER company_logo_path,
  ADD KEY IF NOT EXISTS idx_client_profiles_industry (industry_id),
  ADD KEY IF NOT EXISTS idx_client_profiles_status (client_status),
  ADD KEY IF NOT EXISTS idx_client_profiles_registration (registration_no),
  ADD KEY IF NOT EXISTS idx_client_profiles_pan (pan_no);

CREATE TABLE IF NOT EXISTS industries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_industries_name (name),
  KEY idx_industries_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_provider_entities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED DEFAULT NULL,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(60) DEFAULT NULL,
  logo_path VARCHAR(255) DEFAULT NULL,
  contact_email VARCHAR(190) DEFAULT NULL,
  contact_number VARCHAR(60) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  website VARCHAR(255) DEFAULT NULL,
  authorized_signatory_name VARCHAR(190) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_service_provider_entities_code (code),
  KEY idx_service_provider_entities_company (company_id),
  KEY idx_service_provider_entities_active (is_active),
  CONSTRAINT fk_service_provider_entities_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_service_provider_entities (
  client_id INT UNSIGNED NOT NULL,
  service_provider_entity_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, service_provider_entity_id),
  KEY idx_client_service_provider_entity (service_provider_entity_id),
  CONSTRAINT fk_client_service_provider_client FOREIGN KEY (client_id) REFERENCES client_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_client_service_provider_entity FOREIGN KEY (service_provider_entity_id) REFERENCES service_provider_entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE client_tasks
  ADD COLUMN IF NOT EXISTS service_provider_entity_id INT UNSIGNED DEFAULT NULL AFTER client_id,
  ADD KEY IF NOT EXISTS idx_client_tasks_service_provider_entity (service_provider_entity_id),
  ADD CONSTRAINT fk_client_tasks_service_provider_entity FOREIGN KEY (service_provider_entity_id) REFERENCES service_provider_entities(id) ON DELETE SET NULL;

INSERT INTO industries (name, is_active)
SELECT seed.name, 1
FROM (
  SELECT 'Accounting and Audit' AS name
  UNION ALL SELECT 'Banking and Finance'
  UNION ALL SELECT 'Cooperative'
  UNION ALL SELECT 'Education'
  UNION ALL SELECT 'Healthcare'
  UNION ALL SELECT 'Hydropower'
  UNION ALL SELECT 'Information Technology'
  UNION ALL SELECT 'Jewellery'
  UNION ALL SELECT 'Manufacturing'
  UNION ALL SELECT 'NGO/INGO'
  UNION ALL SELECT 'Real Estate'
  UNION ALL SELECT 'Retail and Trading'
  UNION ALL SELECT 'Other'
) seed
WHERE NOT EXISTS (SELECT 1 FROM industries i WHERE i.name = seed.name);

INSERT INTO service_provider_entities (company_id, name, code, is_active)
SELECT c.id, c.name, c.code, c.is_active
FROM companies c
WHERE NOT EXISTS (
  SELECT 1
  FROM service_provider_entities spe
  WHERE spe.company_id = c.id
     OR spe.code = c.code
);

UPDATE client_profiles
SET client_status = CASE WHEN is_active = 1 THEN 'active' ELSE 'suspended' END
WHERE client_status IS NULL OR client_status = '';
