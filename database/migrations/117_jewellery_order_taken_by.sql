-- Who took the order across the counter.
--
-- Two columns on purpose. sales_employee_id links to the payroll employee when
-- the person is on the employee list; sales_person holds the plain name when
-- they are not. A shop that has not filled in its employee list yet still has
-- to record who served the customer, and a name typed before an employee
-- record existed must not be lost when one is created later.
ALTER TABLE `jewellery_orders`
    ADD COLUMN `sales_employee_id` INT UNSIGNED DEFAULT NULL AFTER `customer_phone`,
    ADD COLUMN `sales_person` VARCHAR(120) DEFAULT NULL AFTER `sales_employee_id`;
