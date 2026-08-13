# MBCA Jewellery Traceability Overhaul

This package upgrades the existing MBCA jewellery module without replacing its accounting, voucher, stock-ledger, tax, party, permission, or tenant-isolation logic.

## What is included

- Excel opening-stock template matching the supplied layout exactly:
  `SN, Stock type, Stock group, Item code, Item name, Metal, Purity, Unit, Pieces, Gross weight, Rate, Amount, Customer name, Order number`.
- Styled XLSX header, borders, widths, frozen header row, filter controls, and screenshot-style sample data.
- New-item creation with jewellery design, hallmark, weight, wastage, making, stone, reorder, and tax defaults.
- Showroom stock orders with one stock-order number grouping multiple workshop assignments.
- Exact physical trace IDs (`TRC-00000001`, etc.) and an append-only lifecycle history.
- Direct reservation or sale of an exact item from Ready to Sale / showroom stock.
- Exact traced COGS on sale, with weighted-average fallback for older untraced data.
- Trace coverage for opening stock, imports, purchases, stock orders, customer workshop orders, old jewellery received in exchange, reservations, sales, delivery, unposting, and cancellation.
- A new **Jewellery > Item Traceability** screen with searchable status, origin, custody, order, sale, and event history.
- Automatic, honest adoption of pre-upgrade on-hand balances as `legacy balance adopted`; the update does not invent historical purchases or receipts.

## Missing and excess links corrected

- `stock_receipt_id` existed in schema/tests but was not connected through the live order and sale flows.
- Ready to Sale only showed workshop receipts, omitting opening and purchased showroom stock.
- Physical stock purpose was stored on the reusable item master, causing the same style to flip between showroom and customer stock. Physical purpose now lives on each trace.
- A showroom item could still be routed to a kaligad; this is now rejected.
- Multi-item order billing could reuse the latest receipt only for the first line; each line now uses its own exact receipt/trace.
- The same physical showroom item could be selected more than once; duplicate reservation and sale are now blocked transactionally.
- Purchase, sale, cancellation, deletion, and reversal paths did not preserve a physical history. They now append trace events and refuse unsafe reversals.
- Old jewellery accepted in exchange entered aggregate stock without a physical identity. It now receives a trace and blocks sale reversal after later movement.

## Before installation

1. Back up the application directory and database.
2. Use a Git checkout of the MBCA project. The installer performs `git apply --check` before changing files.
3. If the target has overlapping local modifications, commit or copy them first. The installer stops rather than overwriting them.

## Windows PowerShell

```powershell
Expand-Archive .\MBCA-Jewellery-Traceability-Overhaul.zip -DestinationPath .\MBCA-Overhaul -Force
& .\MBCA-Overhaul\INSTALL.ps1 -RepoPath "C:\path\to\Mbista"
```

To apply the update and run the database-backed regression suite in the same step (use this instead of the command above; PHP CLI and the configured test database are required):

```powershell
& .\MBCA-Overhaul\INSTALL.ps1 -RepoPath "C:\path\to\Mbista" -RunTests
```

## Linux or macOS

```bash
unzip MBCA-Jewellery-Traceability-Overhaul.zip -d MBCA-Overhaul
bash MBCA-Overhaul/INSTALL.sh /path/to/Mbista
```

Add `--run-tests` to execute the focused PHP database tests.

## Database upgrade

The normal Jewellery pages run the existing accounting repair system. On the first page load after installation it applies migration `111_jewellery_item_traceability.sql` and safely finishes a partially applied deployment. If your deployment runs SQL migrations separately, run the normal migration process before opening the module.

## Verification after installation

1. Open **Jewellery > Opening** and download the Excel template.
2. Create a new jewellery item, or import a screenshot-format opening sheet.
3. Open **Stock Orders**, create a showroom order, issue it, and receive it.
4. Open **Ready to Sale**, select **Bill order** or **Sell now** on a trace.
5. Open **Item Traceability**, search the trace code, and confirm the full lifecycle.

The installers lint every changed PHP file when PHP CLI is available. Database tests are opt-in because they create and remove dedicated test fixtures.

## Rollback

Restore the pre-install database backup and application backup together. Reversing only the code after the database migration is not a complete rollback, because the new trace tables intentionally retain audit history.
