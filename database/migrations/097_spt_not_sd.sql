-- 097: The levy is called by its name — SPT, not SD.
--
-- The 0.5% levy on a jewellery bill is the Skills PROMOTION Tax. The seed
-- coded it "SD" (Skills Development), and because the bill prints the tax
-- straight off its stored code, every invoice carried "SD Taxable Amt" and
-- "SD Tax 0.5%" — the wrong name on a statutory document, which the shop
-- rightly refused to accept.
--
-- New books seed code SPT, name "Skills Promotion Tax". This renames what
-- the old seed already wrote: the register row, and the stored per-line tax
-- rows the tax reports group by. Nothing about the money changes — same tax,
-- same rate, same amounts, right name.
--
-- The tax-register UPDATE is guarded against a company that somehow already
-- holds an SPT code: renaming its SD row would collide with the unique
-- (company_id, code) key, so such a company is left for a person to look at.

UPDATE `jewellery_taxes` t
   SET t.`code` = 'SPT', t.`name` = 'Skills Promotion Tax'
 WHERE t.`code` = 'SD' AND t.`output_purpose` = 'spt_output'
   AND NOT EXISTS (SELECT 1 FROM (SELECT `company_id` FROM `jewellery_taxes` WHERE `code` = 'SPT') s
                   WHERE s.`company_id` = t.`company_id`);

UPDATE `jewellery_line_taxes`
   SET `tax_code` = 'SPT', `tax_name` = 'Skills Promotion Tax'
 WHERE `tax_code` = 'SD' AND `output_purpose` = 'spt_output';
