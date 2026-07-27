# Jewellery Accounting — Design & Implementation

A full jewellery vertical for the accounting platform: dual-unit (weight **and**
value) stock, daily metal rates, karigar (kaligad) order flow, refinery jobs,
bill-wise party accounting and automated double-entry posting.

**Status: all seven phases shipped.** Every phase carries its own self-contained
regression test; see [Verification](#verification).

## Design decisions (agreed before build)

| Decision | Choice |
| --- | --- |
| **Activation** | Per-client flag `client_profiles.jewellery_accounting_enabled`, Super Admin controlled — exactly the Hospitality precedent. The module appears only inside that client's **books company**, never in the firm's own workspaces. |
| **Kaligad wages** | Selectable per kaligad: `engagement_type = contractor` gets an `accounting_parties` row and wages become a bill-wise trade payable; `employee` links to `payroll_employees` and accrues to the generic karigar-payable ledger for payroll to pick up. |
| **VAT** | Per item: `vat_applicable` flag plus `vat_base` (`full_value` / `making_only` / `stone_only`), each defaulting to a company-wide setting. Covers diamond/gem full-value VAT and ornament making-charge-only VAT without a code change. |

## The three ideas everything else follows from

### 1. Fine weight is the pivot

Ornaments arrive in mixed purities, so gross weights are not comparable — 1 tola
of 22K is not 1 tola of 24K. Everything reduces to **fine** weight (pure metal
content), and every rate reduces to a rate per unit of pure metal:

```
fine      = gross × fineness ÷ 1000          (fineness is parts per 1000)
fine_rate = quoted_rate × 1000 ÷ quoted_fineness
```

Multiply the two and a 22K item values correctly off a 24K quote. A shop that
maintains a single 24K line on the rate board can still price its whole
inventory. Weight units (gram, tola, aana, laal, carat) each declare their gram
equivalent, so any unit converts to any other through that one pivot.

### 2. Two parallel ledgers

Standard double-entry only moves money. A jewellery house also has to answer
*"how many tola of 22K is with karigar Ram right now?"* — a question no money
ledger can answer. So every transaction writes **both**:

- **The money leg** — real vouchers through `create_voucher_with_entries()`, the
  same single choke point every other module posts through, so the tenant guard,
  fiscal-year lock and balance check apply unchanged.
- **The metal leg** — `jewellery_stock_txns` rows carrying gross weight, fine
  weight, metal, purity and a **holder** (own stock / a karigar / a refinery /
  a customer). That holder column is the whole trick.

### 3. One settlement identity

A counter sale is rarely paid one way; it is cash plus old gold plus credit in
whatever mix the customer brings. Every sale therefore balances three legs:

```
received_amount  +  exchange_amount  +  balance_amount  ==  total_amount
```

Metal-to-metal and metal-to-cash are not special cases in this model, they are
corners of it — a sale settled entirely in old gold is `exchange == total`, and
metal-to-cash is an old-gold *purchase* paid from the cash ledger.

## What shipped, phase by phase

| Phase | Feature | Where |
| --- | --- | --- |
| 1 | Client flag, settings, weight units, metals, purities, **daily rate master**, ledger-mapping ladder, RBAC capability, nav | `app/jewellery_engine.php`, migration 070 |
| 2 | Item master (per-item VAT + making basis), **dual weight/value stock ledger**, opening stock with GL posting, metal position | `app/jewellery_stock.php`, migration 071 |
| 3 | Supplier purchases and walk-in old-gold purchases, landed cost, **bill-wise** party accounting, settlements with allocation | `app/jewellery_trade.php`, migration 072 |
| 4 | Sales with metal/making/stone split, **old-gold exchange**, per-item VAT, COGS at weighted average | `app/jewellery_trade.php`, migration 072 |
| 5 | Kaligad masters, daily order management, assignment with metal issue, receipt with **wage + wastage settlement**, received-but-not-delivered board | `app/jewellery_workshop.php`, migration 073 |
| 6 | Refinery jobs — issue, receive, refining loss and charges | `app/jewellery_workshop.php`, migration 073 |
| 7 | Sales detailed, purchase detailed, inventory detailed, VAT register, kaligad ledger and wages, bill-wise outstanding, CSV export | `app/jewellery_reports.php`, `admin/jewellery-reports.php` |

### Why wastage is the whole karigar problem

Issue a karigar 10 tola of 22K and you do not get 10 tola back — metal is
genuinely consumed in the making. The shop agrees an **allowed** wastage
percentage up front; everything beyond it is the karigar's loss and comes out of
their wages. One receipt therefore settles three things atomically:

- **metal** — the finished piece comes back in, and the wastage is written off
  the karigar's holding so it lands at *exactly* zero
- **wages** — the making charge is earned
- **excess** — wastage over the allowance is recovered from those wages

The recovery can legitimately exceed the wages (a karigar who lost a lot on a
small job ends up owing the shop). Nothing special-cases that: vouchers are
assembled as **signed legs** and `jw_build_entries()` flips the sign, turning the
payable into a receivable on its own.

## Pages

| Page | Views |
| --- | --- |
| `admin/jewellery.php` | Dashboard · Daily Rates · Items · Opening Stock · Stock & Metal Position · Metals & Units · Settings |
| `admin/jewellery-trade.php` | Purchases · Sales · Bills & Settlement |
| `admin/jewellery-workshop.php` | Orders · Kaligad Issue & Receive · Ready to Deliver · Kaligads · Refinery |
| `admin/jewellery-reports.php` | Summary · Sales Detailed · Purchase Detailed · Inventory Detailed · VAT Register · Kaligad Ledger · Bill-wise |

## Posting map

Every ledger is resolved through `jewellery_ledger_mappings` (item → category →
company default). **Nothing is ever guessed** — an unmapped purpose raises and
the entry refuses to post, rather than landing somewhere wrong.

| Document | Debits | Credits |
| --- | --- | --- |
| Purchase | item stock (landed cost), VAT input | party (credit) or cash/bank |
| Sale | cash/bank, old-gold stock, party balance, sales discount, **COGS** | sales metal/making/stone, VAT output, other charges, **item stock at cost** |
| Opening stock | item stock | opening equity |
| Settlement | party (paid) or cash/bank/stock (received) | the mirror of it |
| Karigar issue | metal with karigar | own stock *(skipped when unmapped — value simply stays in own stock, which is equally correct)* |
| Karigar receipt | making expense, wastage loss net of recovery | karigar payable, stock |
| Refinery receive | refined stock, refining loss, refinery charges | metal with refinery, party/cash |

## Verification

```
php database/test_jewellery_foundation.php     68 assertions
php database/test_jewellery_stock.php          95 assertions
php database/test_jewellery_trading.php        91 assertions
php database/test_jewellery_workshop.php       89 assertions
php database/test_jewellery_page_actions.php   33 assertions   every POST handler
php database/test_jewellery_page_render.php    22 assertions   every view, warnings fail
```

The page-action test runs each handler in its own process (they all end in
`redirect()`, which exits) and exists specifically to catch the class of bug
where a prepared statement is handed placeholders its SQL never declared — that
only ever fires on the real POST path, never in an engine test.

## Non-negotiables carried from the existing codebase

- Every posting flows through `create_voucher_with_entries()` — never a direct
  `INSERT` into `vouchers`/`voucher_entries`.
- Every table is `company_id`-scoped with an FK to `companies` and cascade delete.
- Schema ships twice: a numbered file in `database/migrations/` **and** a step in
  `accounting_module_repair_database()`. Migrations 072/073 are large enough that
  their repair steps *replay the migration file itself* via
  `accounting_repair_run_migration_file()` rather than duplicating ~600 lines of
  DDL that could drift; `database/` is rsynced to production by `deploy/tasks.sh`,
  so the file is there.
- Every jewellery `source_type` is registered in `voucher_mutation_blocker()`, so
  the Voucher Register refuses to delete a jewellery voucher behind the module's
  back. Reversal happens on the module's own page, which rolls the register back
  in the same transaction.
- Page gates check the client flag server-side; a hidden menu is never the only
  protection.
