-- Match the Jewellery AML register's company/date/status filter.
ALTER TABLE `jewellery_aml_cases`
  ADD INDEX `idx_jw_aml_register` (`company_id`, `case_date`, `status`);
