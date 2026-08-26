-- Migration 128: a scheduled report can be delivered as a real workbook.
--
-- The Reports Center "Export Excel" used to hand over a CSV, and a schedule
-- could only email the same thing. A CSV is not a spreadsheet in any sense
-- that matters to the person who opens it: no heading saying what the file is,
-- no borders, columns too narrow to read, and every figure stored as text, so
-- selecting a column of amounts and reading its total does nothing at all.
--
-- The download is now a formatted .xlsx, and a schedule has to be able to send
-- the same file — an emailed copy that looks different from the downloaded one
-- is the sort of difference somebody eventually has to explain to a client.
ALTER TABLE `report_schedules`
  MODIFY COLUMN `export_format` ENUM('csv', 'html', 'both', 'xlsx') NOT NULL DEFAULT 'xlsx';
