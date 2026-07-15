-- Backfill VAT figures on task_invoices rows created by amount-only insert
-- paths (Work Portal issue, pre-VAT-migration rows). Those inserts left
-- taxable_amount / vat_amount / total_amount at their column default 0.00,
-- so the printed invoice showed zero and the auto-posted sales voucher was
-- silently skipped. Rebuild the figures from `amount` using the row's own
-- vat_rate (column default 13.00), matching how invoice.php stores them.

UPDATE task_invoices
SET taxable_amount = amount,
    vat_amount = ROUND(amount * (COALESCE(vat_rate, 13.00) / 100), 2),
    total_amount = ROUND(amount + (amount * (COALESCE(vat_rate, 13.00) / 100)), 2)
WHERE amount > 0
  AND COALESCE(total_amount, 0) = 0;
