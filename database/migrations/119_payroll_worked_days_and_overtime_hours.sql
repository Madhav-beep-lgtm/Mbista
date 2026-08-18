-- Payroll: worked days and overtime hours as real inputs, and per-component
-- pro-rating.
--
-- payroll_run_inputs
--   Recalculating a run does `DELETE FROM payroll_run_lines` and rebuilds every
--   line, so anything typed onto a line is destroyed the next time somebody
--   presses Recalculate. Worked days and overtime hours are TYPED FACTS, not
--   derived figures, so they live in their own table keyed on (run, employee) and
--   survive recalculation - the same reason payroll_run_components keeps period
--   overrides in a table of its own rather than on the line.
--
-- payroll_run_lines.worked_days / period_days / overtime_hours / overtime_rate
--   The same numbers as they were USED in the calculation, written alongside the
--   result. A payslip has to be able to say "26 of 30 days, 12 hours at 129.81"
--   long after the settings that produced those rates were changed, and a figure
--   that can only be recomputed from today's settings cannot be audited.
--
-- payroll_components.prorate_worked_days
--   Off for every existing component, so no run recalculates to a different
--   figure than it does today. Switched on per component it pro-rates that one by
--   worked days over standard days - basic and a per-day allowance yes, a fixed
--   phone allowance or a loan recovery no.
--
--   NOTE: a company that also deducts unpaid leave (payroll_settings.
--   deduct_unpaid_leave) and switches this on for basic would reduce the same
--   absence twice. The component form warns about exactly that where the switch
--   is set.
--
-- Re-runnable: every clause is IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS payroll_run_inputs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id INT UNSIGNED NOT NULL,
    payroll_employee_id INT UNSIGNED NOT NULL,
    worked_days DECIMAL(6,2) NULL DEFAULT NULL,
    overtime_hours DECIMAL(8,2) NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    updated_by INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_run_employee_input (run_id, payroll_employee_id),
    KEY idx_run_inputs_employee (payroll_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payroll_run_lines
    ADD COLUMN IF NOT EXISTS worked_days DECIMAL(6,2) NULL DEFAULT NULL AFTER unpaid_leave_days,
    ADD COLUMN IF NOT EXISTS period_days DECIMAL(6,2) NULL DEFAULT NULL AFTER worked_days,
    ADD COLUMN IF NOT EXISTS overtime_hours DECIMAL(8,2) NULL DEFAULT NULL AFTER period_days,
    ADD COLUMN IF NOT EXISTS overtime_rate DECIMAL(14,4) NULL DEFAULT NULL AFTER overtime_hours;

ALTER TABLE payroll_components
    ADD COLUMN IF NOT EXISTS prorate_worked_days TINYINT(1) NOT NULL DEFAULT 0 AFTER calc_basis;

-- The snapshot a run keeps of each component, so a line recalculated next year
-- pro-rates the way the component was set WHEN THE RUN WAS MADE, not the way
-- somebody has since changed it. payroll_run_components already snapshots
-- taxable, include_in_gross and the ledgers for the same reason.
ALTER TABLE payroll_run_components
    ADD COLUMN IF NOT EXISTS prorate_worked_days TINYINT(1) NOT NULL DEFAULT 0 AFTER calc_method;

-- Basic salary is not a component - it has no row to carry a per-component flag -
-- so pro-rating it is a company setting. Off by default, so no existing run
-- recalculates to a different figure; on, basic becomes basic x worked / standard.
ALTER TABLE payroll_settings
    ADD COLUMN IF NOT EXISTS prorate_basic_worked_days TINYINT(1) NOT NULL DEFAULT 0 AFTER standard_working_days;
