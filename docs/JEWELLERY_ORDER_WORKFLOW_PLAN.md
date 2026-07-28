# Jewellery Order Workflow — Gap Analysis & Improvement Plan

An **incremental** improvement to the order flow that already ships in
`app/jewellery_workshop.php`, `app/jewellery_trade.php` and their screens. This
document is the plan agreed before any code is written; it exists so the scope
of each change is visible against what the module already does.

Read alongside [JEWELLERY_ACCOUNTING_PLAN.md](JEWELLERY_ACCOUNTING_PLAN.md),
which describes the vertical the order flow sits inside.

## What already exists

The requested flow —

```
Customer Order → Manufacturing → Kaligad Assignment → Gold Issue →
Gold Receipt → Sales Invoice → Payment Settlement → Customer Delivery
```

— is **already the shape of the module**. The tables, the engine functions and
the screens are all there:

| Stage | Table | Engine | Screen |
| --- | --- | --- | --- |
| Order | `jewellery_orders` + `jewellery_order_lines` | `jewellery_save_order()` | `jewellery-workshop.php?view=orders` |
| Kaligad assignment | `order_lines.karigar_id` / `delivery_date` | `jewellery_save_order()` | same |
| Gold issue | `jewellery_order_assignments` | `jewellery_issue_to_karigar()` | `?view=assignments` |
| Gold receipt | `jewellery_order_receipts` | `jewellery_receive_from_karigar()` | `?view=assignments` |
| Sales invoice | `jewellery_sales` / `_sale_lines` | `jewellery_order_sale_prefill()` | `jewellery-trade.php` |
| Advance | `jewellery_settlements` (`order_id`, `is_advance`) | `jewellery_save_settlement()` | `?view=orders` |
| Payment | `jewellery_settlements` + `_settlement_allocations` | `jewellery_save_settlement()` | `jewellery-trade.php` |
| Delivery | `orders.delivered_sale_id` / `delivered_at` | `jewellery_deliver_order()` | `?view=delivery` |

So this is **not** a build. It is a set of named gaps.

## Section-by-section gap table

| § | Requirement | Verdict |
| --- | --- | --- |
| 1 | Unique order reference | **Exists** — `orders.order_no`, unique per company, prefix from `jewellery_settings.order_no_prefix` (default `JO`), sequence `JO-00001` via `jw_next_no()`. **Gap: no fiscal year segment.** |
| 1 | Reference is the master key | **Exists** — assignments, receipts, settlements, sales and the delivery record all carry `order_id`. |
| 1 | Status auto-updates | **Partial** — `jewellery_sync_order_status()` derives status from every line. Enum is `draft, confirmed, assigned, received, delivered, cancelled`. **Gap: no `partially_received`, `invoiced`, `closed`.** |
| 2 | Multiple ornaments per order | **Exists** — `jewellery_order_lines` (migration 087). |
| 3 | Kaligad per line item | **Exists** — `karigar_id`, `delivery_date`, `assignment_id` per line (migration 088). Assigned date, expected date, remarks and status live on the assignment row. |
| 4 | Gold issue to kaligad | **Exists** — gross, fine, purity, date, `issue_no`, store txns, voucher, all linked to order + line + kaligad. |
| 5 | Gold receipt at any purity | **Exists** — received weight, purity, fine equivalent, wastage split, making charge, net payable. **Gap: no `stone_weight` / net gold weight on the receipt.** |
| 5 | Show actual **and** fine weight together | **Gap** — the order and stock screens do; `jewellery-trade.php` (sale entry) and `jewellery-invoice.php` (printed bill) show gross/stone/net/wastage/total but **never the fine equivalent**. |
| 6 | Sell from the received ornament | **Exists** — `jewellery_order_sale_prefill()` prices every line from the order and swaps in what actually came back. |
| 7 | Advance modes | **Gap** — `settlements.mode` is `cash, bank, metal, adjustment`. Cheque, QR, wallet, credit note, opening advance, carry-forward from a previous order, excess from a previous invoice and a user-defined "other" have nowhere to go. |
| 8 | Multiple advances accumulate | **Partial** — many advance rows per order already work. **Gap: no Total / Adjusted / Remaining summary anywhere.** |
| 9 | Advance adjustment, entry by entry | **Gap** — `sales.advance_amount` is a single number. Which advance rows it consumed is not recorded, so it cannot be shown, audited or reversed per entry. This is also the largest breach of the manual-mapping rule. |
| 10 | Post-sale part payments | **Exists** — settlements + allocations, bill stays part-paid until the balance clears. |
| 11 | Payment methods | **Gap** — same enum as §7. |
| 12 | Customer ledger posting | **Exists** — every posted document writes a voucher; advances post to the party's own advance ledger (`accounting_parties.advance_ledger_id`). |
| 13 | Document flow | **Exists** end to end. |
| 14 | Reporting | **Partial** — Kaligad Ledger, Kaligad Statement, Bill-wise and Uncollected Orders ship. **Gap: Order Status, Pending Manufacturing, Gold Issued to Kaligad, Gold Pending Return, Advance Register, Advance Adjustment Register, Order Profitability.** |

## Ground rule 8 — mandatory manual mapping

The rule is that nothing posts without the user seeing and confirming the
mapping. Where the module stands today:

- **Already confirmed** — `jewellery_preview_receipt()` shows the computed
  wastage, wages and recovery before `receive_karigar` is submitted.
- **Already manual** — the sale is drafted, reviewed and posted as two separate
  acts; `jewellery_deliver_order()` refuses anything but a posted bill.
- **Not confirmed** — the company setting `jewellery_settings.auto_post`
  (default **on**) posts vouchers as documents are saved, with no mapping
  screen. Issue and refinery movements post the same way.
- **Not confirmed** — advance application at billing: the number is applied,
  the source entries are never shown.

## Proposed work, in the order it must happen

Each numbered item is one commit, tested, reviewed before the next starts.

### A. Order reference gains a fiscal-year segment (§1)

`jw_next_no()` grows an optional fiscal-year segment so a new order numbers as
`ORD-2083-000001`. Existing numbers are untouched and the sequence continues
from whatever the company already uses. Requires a decision — see
[Open questions](#open-questions).

### B. Status vocabulary (§1)

Extend the enum with `partially_received`, `invoiced` and `closed`.
`jewellery_sync_order_status()` gains the corresponding rules:

```
partially_received   some items back, not all
received             every item back
invoiced             a posted sale exists, goods not yet handed over
delivered            handed over
closed               delivered and the balance is nil
```

`assigned` keeps its meaning (metal is out with a kaligad = the prompt's
"Gold Issued"/"In Production"). Existing rows are recomputed by a migration in
the manner of 090.

### C. Advance and payment modes (§7, §11) — **SHIPPED** (migration 092)

Reshaped by a rule stated after this plan was written: **one payment is
routinely made several ways at once** — part cash, part Fonepay, part old gold,
at the same counter in the same minute. A wider enum alone cannot say that, so
the split became child rows: `jewellery_settlement_tenders`, one row per way
the customer paid, each with its own `mode`, `mode_label`, `reference`,
`ledger_id` and (for old gold) full item/purity/unit/weight context. The rows
must sum to the settlement's own amount; posting gives each way its own voucher
leg and each metal row its own stock movement. The header's `mode` widened to
name single modes it could not (`cheque`, `qr`, `wallet`, `card`, `other`) and
to read `mixed` when the rows disagree. The advance and refund forms on the
workshop page are now tender grids. `metal` covers old gold and raw gold;
`adjustment` covers journal adjustments; `other` + `mode_label` covers credit
notes and anything else the shop names. The advance *sources* the prompt lists
(opening balance, previous order, excess from a previous invoice) are not
modes — they are the allocation problem in D.

### D. Advance allocation table (§8, §9) — the core change

A new append-only table:

```
jewellery_advance_allocations
    sale_id          which bill consumed it
    settlement_id    which advance entry it came from
    amount           how much of that entry
    allocated_at / allocated_by
```

Billing then shows every open advance on that customer — this order's,
a previous order's, an opening balance, an unallocated excess — and the user
**ticks the entries and types the amounts**. Nothing is applied automatically.
`sales.advance_amount` becomes the sum of the rows, so existing bills stay
correct and nothing already stored has to change.

This makes §8's summary computable: received, allocated, remaining.

### E. Fine weight beside actual weight (§5)

Add the fine column to `jewellery-trade.php`'s line grid and to
`jewellery-invoice.php`'s printed bill. Add `stone_weight` and the derived net
gold weight to `jewellery_order_receipts`.

### F. Order reports (§14)

New views on `jewellery-reports.php`, functions in `app/jewellery_reports.php`,
following `jw_report_*` exactly: Order Status, Pending Manufacturing, Gold
Issued to Kaligad, Gold Pending Return, Advance Register, Advance Adjustment
Register, Order Profitability. Kaligad-wise Production and Purity-wise
Manufacturing are groupings of the same query.

### G. Mapping confirmation for auto-posted documents (ground rule 8)

Turn `auto_post` into a per-document confirmation step rather than a silent
default: the document is saved as a draft, the proposed voucher lines are
displayed, and posting is a second action. Sequenced last because it touches
purchases, sales and settlements as well as orders.

## Open questions

These change the work materially and are asked before any code is written.

1. **Order number format.** The shop's existing orders are numbered `JO-00001`.
   Should new orders switch to `ORD-<FY>-<seq>`, keep the current format, or
   keep the configurable prefix and only add the fiscal year?
2. **Fiscal year in the number.** `2083` is a Bikram Sambat year. The repo
   stores fiscal years in `fiscal_years` with AD start/end dates and has
   `app/nepali_date.php`. Which year is meant — the BS year of the order date,
   or a label on the fiscal year record?
3. **"Closed" status.** Is an order closed automatically when it is delivered
   and paid in full, or is closing a deliberate act by a user?

## Verification

Each item ships with additions to the existing self-contained suites —
`database/test_jewellery_workshop.php`, `test_jewellery_advances.php`,
`test_jewellery_invoice.php` — run as `php database/test_jewellery_*.php`.
