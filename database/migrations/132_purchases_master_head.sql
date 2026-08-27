-- Migration 132: Purchases becomes a head of its own in the chart of accounts.
--
-- A purchase is not an expense and it is not inventory. It is a PURCHASE: a
-- trading-account debit that, together with opening stock and less closing
-- stock, gives the cost of what was actually sold. Filed under Direct Expenses
-- it reads as money spent and gone; filed under Inventory it reads as an asset
-- that never passes through the profit and loss at all. Neither is what a
-- purchase is, and a chart of accounts that offers no third answer forces the
-- bookkeeper to pick a wrong one.
--
-- So `purchases` joins the primary heads. It sits between income and direct
-- expenses because that is the order a trading account is read in:
--
--     Sales                                     (direct_income)
--     less  Opening stock                       (stock in hand, brought forward)
--           Purchases                           (purchases)          <- new
--           Direct expenses                     (direct_expense)
--     add   Closing stock                       (stock in hand, carried down)
--     =     GROSS PROFIT
--
-- Its nature is `expense`, so every existing report that asks "is this ledger
-- an expense?" keeps working without being taught a new word. What changes is
-- only that purchases can now be grouped as themselves rather than buried
-- among wages and rent.
--
-- Nothing is reclassified here. This adds the head; moving a company's
-- existing purchase ledgers into it is a separate, deliberate act.
ALTER TABLE `ledger_groups`
  MODIFY COLUMN `master_key` ENUM(
    'equity',
    'non_current_liability',
    'current_liability',
    'non_current_asset',
    'current_asset',
    'direct_income',
    'indirect_income',
    'purchases',
    'direct_expense',
    'indirect_expense'
  ) NOT NULL;
