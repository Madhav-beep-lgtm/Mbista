-- Migration 130: a schedule delivers a file, and PDF is one of the files.
--
-- The format list read: "Excel (.xlsx) + HTML", "CSV + HTML", "CSV only",
-- "HTML only". Three of those four say something about HTML, and none of them
-- needed to: the runner puts the report table in the BODY of every scheduled
-- email whatever the format says. "+ HTML" was never a choice being offered,
-- it was a description of what always happens, and "HTML only" only ever meant
-- "and do not attach anything".
--
-- So the list becomes the three things that are actually different -- the file
-- that arrives attached -- and PDF joins it, because a client who is sent a
-- management pack usually wants to read it, not open it in a spreadsheet.
--
-- Existing schedules are moved rather than stranded. 'both' was CSV with the
-- same HTML body every format gets, so it becomes 'csv' and nothing about that
-- delivery changes. 'html' asked for no attachment at all; it becomes 'xlsx',
-- which is the one change of behaviour here and is the deliberate one -- the
-- email reads exactly as before and now carries the workbook as well.
UPDATE `report_schedules` SET `export_format` = 'csv' WHERE `export_format` = 'both';
UPDATE `report_schedules` SET `export_format` = 'xlsx' WHERE `export_format` = 'html';
ALTER TABLE `report_schedules`
  MODIFY COLUMN `export_format` ENUM('xlsx', 'csv', 'pdf') NOT NULL DEFAULT 'xlsx';
