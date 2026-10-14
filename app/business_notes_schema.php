<?php
declare(strict_types=1);
db()->exec("CREATE TABLE IF NOT EXISTS business_voucher_notes (
 voucher_id INT UNSIGNED NOT NULL PRIMARY KEY,
 company_id INT UNSIGNED NOT NULL,
 original_voucher_id INT UNSIGNED NOT NULL,
 business_type VARCHAR(32) NOT NULL,
 applied_at DATETIME NULL,
 UNIQUE KEY uniq_business_note_source (original_voucher_id),
 KEY idx_business_notes_company (company_id),
 CONSTRAINT fk_business_note_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
 CONSTRAINT fk_business_note_original FOREIGN KEY (original_voucher_id) REFERENCES vouchers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (table_exists('jewellery_stock_units')) {
 $holder=db()->query("SHOW COLUMNS FROM jewellery_stock_units LIKE 'current_holder_type'")->fetch();
 if ($holder && !str_contains($holder['Type'],"'supplier'")) {
  db()->exec("ALTER TABLE jewellery_stock_units MODIFY current_holder_type ENUM('stock','karigar','refinery','customer','supplier') NOT NULL DEFAULT 'stock'");
 }
}
