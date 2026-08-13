-- Indexes supporting the portal/dashboard query shapes introduced in the
-- August 2026 performance pass. Apply once through the normal migration flow.

ALTER TABLE client_tasks
  ADD INDEX idx_client_tasks_company_status_created (company_id, status, created_at),
  ADD INDEX idx_client_tasks_company_client_created (company_id, client_id, created_at);

ALTER TABLE task_stages
  ADD INDEX idx_task_stages_task_status (task_id, status);

ALTER TABLE task_invoices
  ADD INDEX idx_task_invoices_task_company_status (task_id, company_id, status),
  ADD INDEX idx_task_invoices_company_created (company_id, created_at);

ALTER TABLE messages
  ADD INDEX idx_messages_thread_created (thread_id, created_at);

ALTER TABLE support_ticket_messages
  ADD INDEX idx_ticket_messages_ticket_created (ticket_id, created_at);

ALTER TABLE support_tickets
  ADD INDEX idx_support_tickets_client_created (client_id, created_at);

ALTER TABLE documents
  ADD INDEX idx_documents_client_visibility_active_created (client_id, visibility, is_active, created_at);

ALTER TABLE document_requests
  ADD INDEX idx_document_requests_client_created (client_id, created_at);

ALTER TABLE agreement_task_links
  ADD INDEX idx_agreement_task_links_task_id_id (task_id, id);
