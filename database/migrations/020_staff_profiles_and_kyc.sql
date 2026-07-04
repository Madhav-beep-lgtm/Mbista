-- Public staff profiles and confidential staff KYC documents

CREATE TABLE IF NOT EXISTS staff_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  job_title VARCHAR(150) DEFAULT NULL,
  department VARCHAR(150) DEFAULT NULL,
  education VARCHAR(255) DEFAULT NULL,
  qualifications VARCHAR(255) DEFAULT NULL,
  expertise VARCHAR(255) DEFAULT NULL,
  bio TEXT DEFAULT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
  team_category ENUM('leadership', 'management', 'professional') NOT NULL DEFAULT 'professional',
  show_on_public_team TINYINT(1) NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 0,
  employment_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_staff_profiles_user (user_id),
  KEY idx_staff_profiles_public (show_on_public_team, display_order),
  CONSTRAINT fk_staff_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_kyc_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_user_id INT UNSIGNED NOT NULL,
  document_type ENUM('citizenship', 'national_id', 'passport', 'pan') NOT NULL,
  stored_filename VARCHAR(190) NOT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  file_size INT UNSIGNED DEFAULT NULL,
  verification_status ENUM('submitted', 'verified', 'rejected', 'requires_update') NOT NULL DEFAULT 'submitted',
  verified_by INT UNSIGNED DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_staff_kyc_stored_filename (stored_filename),
  KEY idx_staff_kyc_staff (staff_user_id),
  KEY idx_staff_kyc_type (document_type),
  KEY idx_staff_kyc_status (verification_status),
  CONSTRAINT fk_staff_kyc_staff FOREIGN KEY (staff_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_kyc_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_staff_kyc_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE settings SET setting_value = 'Integrated expertise for business, finance, investment, and education.' WHERE setting_key = 'hero_title';
UPDATE settings SET setting_value = 'A professional group spanning audit and assurance, business consulting, corporate training, investment holding, and education services, delivered with integrity and discipline.' WHERE setting_key = 'hero_description';
