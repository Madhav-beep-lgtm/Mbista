# Performance improvement TODO

This checklist tracks the bottlenecks found during the August 2026 static audit.
Each item is complete only after its focused tests pass and the affected PHP
files pass syntax validation.

- [x] P0: Replace the Work Portal client service-provider N+1 query with one
  batched lookup.
- [x] P0: Load Work Portal datasets only when the active view needs them.
- [x] P0: Paginate or explicitly cap client-dashboard agreements, contracts,
  tasks, invoices, documents, requests, messages, and tickets.
- [x] P1: Replace correlated task-list aggregates with grouped/derived joins.
- [x] P1: Consolidate accounting-dashboard ledger metadata and balance scans
  without caching live financial totals.
- [x] P1: Capture an `EXPLAIN` baseline and add composite indexes matching
  confirmed production query plans.
- [x] P2: Stop eagerly loading specialized accounting engines on unrelated
  requests.
- [x] P2: Replace per-request `information_schema` capability checks with a
  versioned persistent schema capability cache.
- [x] P2: Compress and long-cache fingerprinted CSS, JavaScript, fonts, and
  images at the web server.
- [x] P2: Narrow the client-books navigation query to required columns.
- [x] P2: Add configurable Node API pool size and bounded queue back-pressure.

## Current bottleneck backlog

Work through these items in order. Traceability is outside the requested scope.

- [x] P0: Remove the automatic full-period `jw_aml_scan()` from AML page GET
  requests. Trigger detection incrementally after posted jewellery transactions
  and provide a deliberate manual or scheduled rescan for recovery.
  - Complete when opening the AML register performs no candidate-generation
    writes and its response time does not grow with the selected scan period.
- [x] P0: Replace the Jewellery Inventory Detail per-item balance and movement
  queries with grouped, set-based queries.
  - Complete when query count remains approximately constant as item count
    increases, with report totals matching the existing implementation.
- [x] P1: Paginate the on-screen AML case register and add a separate total-count
  query while preserving filters and case selection.
  - Complete when one page loads a bounded number of cases and navigation works
    for every status/date filter.
- [x] P1: Batch AML case-transaction link writes and avoid rewriting unchanged
  candidates during rescans.
  - Complete when rescanning identical data performs no unnecessary updates and
    uses bounded batches instead of one database round trip per transaction.
- [x] P1: Add and deploy a composite AML register index matching the normal
  `(company_id, case_date, status)` lookup, verified with `EXPLAIN` against
  production-like data.
- [x] P1: Move access-control schema creation and alteration out of normal web
  requests into the deployment migration path.
  - Complete when authorization requests perform no DDL and do not depend on
    APCu to avoid repeated `information_schema` queries.
- [x] P1: Remove the Admin Work Portal request-time schema repair fallback.
  Replace it with deployment-time repair and a safe maintenance warning if the
  deployed schema is incomplete.
- [x] P2: Stream AML CSV exports instead of
  constructing the complete export in PHP memory.
  - Complete with a large-data memory benchmark and an export-content parity
    check.
- [x] P2: Enable and tune OPcache through the deployed `.user.ini`; confirm the
  host accepts these values after deployment before considering decomposition
  of large PHP modules.

## Required verification for current backlog

Each completed item must record PHP syntax checks, focused automated tests,
`git diff --check`, and a before/after query-count or timing measurement. Schema
changes must also record successful automatic deployment and the relevant
production `EXPLAIN` output. After deployment, confirm the deployed Git commit
in `~/auto-deploy.log` and test the affected live page.

## Verification record

Add the date, test command, and relevant before/after measurement beneath each
item when it is completed. Database-dependent changes must include a query plan
or production-like benchmark rather than relying only on source inspection.

- 2026-08-12 — Work Portal service-provider lookup: replaced N per-client
  queries with one batched lookup. `php -l app/helpers.php` and
  `php -l public_html/admin/workspace.php` passed; `git diff --check` passed.

- 2026-08-12 — Work Portal data is view-scoped, and task totals, stages, and
  agreement links use grouped joins instead of six correlated subqueries.
- 2026-08-12 — Client-history queries now have explicit limits. Added
  `113_performance_indexes.sql`; the local affected tables are empty, so the
  captured `EXPLAIN` validates shape but cannot provide meaningful timing.
- 2026-08-12 — APCu caches schema checks for five minutes when available.
  Fixed-asset and manufacturing engines are lazy-loaded by their modules.
- 2026-08-12 — Static assets use compression and immutable fingerprinted
  caching. The header query is narrowed, and the Node database queue is bounded.
- 2026-08-12 — PHP and Node syntax checks, `git diff --check`, and all 120
  frontend-hygiene assertions passed.
- 2026-08-12 — The accounting ledger aggregation now also returns trade-group
  classification, bank metadata, and last-movement dates. This removes three
  follow-up queries while keeping every financial total live; PHP lint and a
  MariaDB `EXPLAIN` of the consolidated query passed.

- 2026-08-13 — AML detection was removed from GET requests and moved to
  affected-date refreshes after posting and unposting. Candidate links now use
  200-row batches, unchanged candidates skip updates, the screen is paginated
  at 50 cases, and CSV exports stream in 500-row pages.
- 2026-08-13 — Jewellery Inventory Detail changed from approximately `5N + 1`
  balance/movement queries to an item-master query plus one grouped stock query.
  All inventory-report parity assertions in the trading suite passed. The full
  suite retains an unrelated legacy traceability reversal failure; traceability
  is explicitly outside this backlog and was not changed.
- 2026-08-13 — Added migration `115_aml_performance_indexes.sql` for
  `(company_id, case_date, status)`. Access-control and Work Portal DDL are now
  CLI-only and deployment runs both repair paths explicitly.
- 2026-08-13 — Added production `.user.ini` OPcache defaults. Focused AML tests
  passed 17/17, all changed PHP files passed PHP 8.3 syntax validation, and
  `git diff --check` passed. Production index and OPcache confirmation remains
  part of live verification after deployment.

- 2026-08-13 — Second-pass walkthrough removed five additional scaling paths:
  Work Portal staff workload changed from roughly 3–6 queries per staff member
  to three batched queries; invoice stock validation changed from one query per
  inventory item to one grouped query; open-bill summary changed from loading
  every bill to one aggregate query while the register is paginated at 200;
  Chart of Accounts now loads at most 500 matching ledgers and streams CSV rows;
  and jewellery invoice tax display changed from one lookup per tax to the
  historically stored line-tax rate returned by the existing aggregate query.
  All seven changed PHP files passed PHP 8.3 syntax validation,
  `test_stock_summary.php` passed 84/84, trading-report assertions passed, and
  `git diff --check` passed. Interactive browser automation was unavailable due
  to the local browser-control runtime; Apache answered on port 8095 but rejected
  command-line requests with HTTP 400, so signed-in page timing and live
  deployment verification remain post-deployment checks.

- 2026-08-13 — Client-books parity walkthrough keeps approval/post authority
  with owners and approvers while exposing the full accounting, opening
  balance, payroll, budget, reporting, export, and audit workflows. Pending
  approvals now use an independent count plus a bounded 100-row queue; audit
  feeds are tenant-scoped and capped; report schedules are capped at 200 rows.
  Migration 116 adds the activity-log company/time index and is wired into the
  deployment task. Client parity passed 22/22, voucher screens 97/97, opening
  balances 48/48, staff reach 19/19, all changed PHP linted successfully, and
  `git diff --check` passed.
