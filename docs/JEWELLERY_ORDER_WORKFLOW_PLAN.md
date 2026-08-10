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

### B. Status vocabulary (§1) — **SHIPPED** (migration 093)

The enum gained `partially_received`, `invoiced` and `closed`, derived as:

```
partially_received   some items back, not all
received             every item back
invoiced             a posted sale exists, goods not yet handed over
delivered            handed over, balance still owed
closed               delivered and the balance is nil — automatic, both at
                     delivery and when a later settlement clears the bill
                     (jw_refresh_bill), stepping back if that settlement
                     is reversed
```

`assigned` keeps its meaning. Existing rows were recomputed in the manner
of 090. The workshop's sync owns draft→received; `invoiced` onward belongs
to the billing machinery and a person, and the sync never overwrites them.

Shipping this fixed a real wiring break: the sale screen attempted delivery
right after SAVING (a draft), which the engine rightly refuses, and nothing
retried after posting — so orders sold through the normal save-then-post flow
sat on the ready-to-deliver board forever. The sale now carries
`jewellery_sales.order_id` from the save, and the post_sale handler delivers
(and auto-closes) on that durable link.

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

### D. Advance allocation table (§8, §9) — **SHIPPED** (migration 094)

`jewellery_advance_allocations` (sale_id, settlement_id, amount): each row is
one decision — this bill took this much from that advance entry. The billing
screen lists every open advance the customer holds — this order's, a previous
order's — and the user **ticks entries and types amounts**; ticking the rows
IS the advance figure, so there is no second field to disagree. Per entry:

```
remaining = amount − allocations on non-cancelled sales
                   − FIFO share of that order's refunds
```

Drafts reserve their share; one rupee can never fund two bills; an entry
funding a bill refuses to unpost until the sale is unwound. Legacy callers
that still send the single number get it spread oldest-first across the
delivering order's own entries so the invariant (advance_amount = sum of
rows) holds for every sale — the screen never takes that path. Sales already
stored were back-filled by the same FIFO rule in the repair step.

### E. Fine weight beside actual weight (§5) — **SHIPPED** (migration 095)

Stones are weighed apart on kaligad receipts: `stone_weight` and
`net_gold_weight` on `jewellery_order_receipts`, and the fine equivalent —
with it the wastage, the recovery and the value into stock — is computed
over gross − stone. Counting the stones credited the kaligad with metal he
never returned. Existing receipts backfill stone 0 / net = gross.

Actual weight and fine equivalent now show together on: the sale/purchase
entry rail (live Fine equivalent row, from each line's chosen purity), the
printed bill (per-line fine under net, plus a fine-gold-equivalent total in
grams), the orders list, the ready-to-deliver board, and the receive-back
preview. Inventory and stock reports already carried both.

### F. Order reports (§14) — **SHIPPED**

Four new views on `jewellery-reports.php`, four `jw_report_*` functions:

- **Order Status** (`?view=orders`) — one row per order with actual AND fine
  weight, quote, advance held/applied/unapplied, bill and balance; the status
  filter makes it Pending Manufacturing, Completed Orders, Pending Delivery
  or Customer Order History.
- **Gold Out / Workshop** (`?view=workshop`) — every issue and what came back;
  "only metal still out" is Gold Pending Return; grouped kaligad-wise or
  purity-wise for the production registers.
- **Advance Register** (`?view=advreg`) — every entry with what it funded and
  what it still holds, plus the Adjustment Register: one row per allocation,
  the record 094 made possible.
- **Order Profitability** (`?view=profit`) — bill revenue and COGS beside the
  workshop's wages and borne wastage for the same order.

All four export CSV / Excel / print from the same queries the screen renders.

### G. Mapping confirmation before posting (ground rule 8) — **SHIPPED**

The Post button on a draft sale or purchase now leads to a confirmation card:
the ledger legs (which account debited, which credited, balanced totals) and
the stock movements the posting would make, with a "Confirm & Post — exactly
the entries above" button. The preview IS the posting — dry-run inside a
transaction and rolled back — so what the user confirms and what the ledger
receives are the same code path and can never drift apart
(`jewellery_preview_posting()`, proven leg-for-leg in the trading suite).

Forms where the user already names every mapping — the advance tender grid,
the settlement form with its bill-by-bill allocations, the kaligad issue form,
the receive screen with its preview-then-confirm — were rule-8 compliant as
built: submitting them IS the confirmation.

The `auto_post` settings checkbox turned out to be dead — no code ever read
it. It promised silent posting and delivered nothing; it has been removed
rather than implemented, since a setting that silences the confirmation has
no place in this workflow.

### H. An ordered item can be a piece already on the shelf — **SHIPPED** (migration 106)

Everything above assumes an ordered item is a *job*. The commonest counter
conversation is not: a customer sees a ring in the case, likes it, and asks the
shop to hold it. That is an order — customer, advance, promised day, bill — with
nothing to make.

`jewellery_order_lines.source` (`workshop` | `stock`) says which, and
`stock_receipt_id` names WHICH physical piece off the Ready to Sale board. The
receipt is the piece: one per assignment, its own weights, its own purity, its
own item. Naming only the item would not do — two 22K rings of the same item
code are two different objects.

What follows from it:

- **The piece states its own facts.** Item, purity, unit, pieces, gross and
  stone are read off the receipt before pricing, never from the form. The rate,
  making and stone money stay the shop's to set — that is the deal, not a fact
  about the object.
- **One ring, one customer.** A piece a live order names is not offered again;
  the board says who holds it, and a second order is refused by name. A
  cancelled or deleted order hands it back and keeps the record of what it held.
- **No kaligad, ever.** Stock lines are absent from `jewellery_assign_order_payload()`
  and `jewellery_pending_order_lines()`, and `jewellery_save_assignment()`
  refuses one in a sentence.
- **Finished when written.** `jewellery_sync_order_status()` counts a shelf line
  as already back, so an all-shelf order is `received` immediately and reaches
  the ready-to-deliver board; a mixed order reads `partially_received` until the
  made half returns. The 090 recompute in the self-repair had to learn the same
  rule — it runs on every page load, and a rule it did not know it silently
  undid. `jewellery_delete_order()` now allows `received` when no assignment
  exists, or an order taken by mistake could never be taken back off the books.
- **Billing.** The workshop receipt re-measures the first *workshop* line, not
  line one — otherwise one physical piece would be billed at another's weight.

Proven end to end in `database/test_jewellery_order_from_stock.php`.

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
