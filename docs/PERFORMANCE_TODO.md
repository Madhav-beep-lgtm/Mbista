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
