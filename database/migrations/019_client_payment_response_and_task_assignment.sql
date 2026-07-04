-- Client payment-request responses and task/stage-level staff assignment

ALTER TABLE invoice_payment_requests
  ADD COLUMN client_declared_status ENUM('none', 'partial', 'complete') NOT NULL DEFAULT 'none' AFTER notes,
  ADD COLUMN client_declared_amount DECIMAL(12,2) DEFAULT NULL AFTER client_declared_status,
  ADD COLUMN client_declared_method VARCHAR(100) DEFAULT NULL AFTER client_declared_amount,
  ADD COLUMN client_declared_reference VARCHAR(190) DEFAULT NULL AFTER client_declared_method,
  ADD COLUMN client_declared_on DATE DEFAULT NULL AFTER client_declared_reference,
  ADD COLUMN client_declared_note TEXT DEFAULT NULL AFTER client_declared_on,
  ADD COLUMN client_declared_at TIMESTAMP NULL DEFAULT NULL AFTER client_declared_note,
  ADD KEY idx_invoice_payment_requests_declared (client_declared_status);

ALTER TABLE client_tasks
  ADD COLUMN assigned_staff_user_id INT UNSIGNED DEFAULT NULL AFTER team_id,
  ADD KEY idx_client_tasks_assigned_staff (assigned_staff_user_id),
  ADD CONSTRAINT fk_client_tasks_assigned_staff FOREIGN KEY (assigned_staff_user_id)
    REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE task_stages
  ADD COLUMN assigned_staff_user_id INT UNSIGNED DEFAULT NULL AFTER stage_fee,
  ADD KEY idx_task_stages_assigned_staff (assigned_staff_user_id),
  ADD CONSTRAINT fk_task_stages_assigned_staff FOREIGN KEY (assigned_staff_user_id)
    REFERENCES users (id) ON DELETE SET NULL;
