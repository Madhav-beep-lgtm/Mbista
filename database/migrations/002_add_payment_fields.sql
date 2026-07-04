ALTER TABLE orders
	ADD COLUMN IF NOT EXISTS payment_method VARCHAR(40) NOT NULL DEFAULT 'manual',
	ADD COLUMN IF NOT EXISTS payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
	ADD COLUMN IF NOT EXISTS transaction_id VARCHAR(190) DEFAULT NULL;

UPDATE orders
SET payment_method = 'manual'
WHERE payment_method IS NULL OR payment_method = '';

UPDATE orders
SET payment_status = 'pending'
WHERE payment_status IS NULL OR payment_status = '';

UPDATE orders
SET payment_status = 'pending'
WHERE payment_status NOT IN ('pending', 'paid', 'failed', 'refunded');

ALTER TABLE orders
	MODIFY COLUMN payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending';

INSERT INTO settings (setting_key, setting_value)
SELECT 'payment_mode', 'manual'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'payment_mode');

INSERT INTO settings (setting_key, setting_value)
SELECT 'payment_label', 'Manual payment / bank transfer'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'payment_label');

INSERT INTO settings (setting_key, setting_value)
SELECT 'bank_name', 'Your Bank Name'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'bank_name');

INSERT INTO settings (setting_key, setting_value)
SELECT 'bank_account_name', 'M.Bista Altiora Complete Hosting'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'bank_account_name');

INSERT INTO settings (setting_key, setting_value)
SELECT 'bank_account_number', '0000000000000000'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'bank_account_number');

INSERT INTO settings (setting_key, setting_value)
SELECT 'payment_note', 'After placing the order, send the transaction reference to support.'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'payment_note');

INSERT INTO settings (setting_key, setting_value)
SELECT 'stripe_checkout_url', ''
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'stripe_checkout_url');

INSERT INTO settings (setting_key, setting_value)
SELECT 'paypal_checkout_url', ''
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'paypal_checkout_url');
