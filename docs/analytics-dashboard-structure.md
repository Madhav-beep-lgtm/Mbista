# Analytics Dashboard Structure

This document explains how the admin analytics dashboard is built so future updates can be made safely.

## Route and View

- Route file: `public_html/admin/index.php`
- Page class: `admin-layout admin-dashboard`
- Shared shell: `app/views/partials/admin_header.php` and `app/views/partials/admin_footer.php`

## Data Sources

The dashboard reads live metrics from these tables when available:

- `users`: active staff count and staff performance rows
- `client_profiles`: active client count and pending invoice client labels
- `service_contracts`: active engagements count
- `client_tasks`: task status totals, open task totals, completion progress, recent tasks
- `task_invoices`: invoice status totals, pending invoices, amount pipeline and collection rate

All metrics are defensive:

- checks use `table_exists()` before querying optional modules
- defaults remain `0` or empty arrays when tables have no data
- UI still renders in empty-state mode

## Dashboard Sections

The dashboard is grouped in this order:

1. Hero and quick links
2. KPI strip (`.admin-stats`)
3. Analytics grid (`.admin-dashboard-grid`)
4. Recent tasks table

### KPI Strip

Current KPI cards include:

- Companies
- Active staff
- Active clients
- Open tasks
- Overall task progress percent
- Pending invoice count
- Pending invoice amount
- Invoice collection rate

### Analytics Grid

The analytics grid uses two content types:

- chart cards: `.admin-chart-card`
- analysis cards: `.admin-analysis-card`

Current cards:

- Task progress by status (bar chart)
- Invoice pipeline by status (doughnut chart)
- Staff performance snapshot (table)
- Pending invoices (draft + issued) table

## Chart Layer

Charts are rendered in `public_html/admin/index.php` using Chart.js UMD from CDN:

- script source: `https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js`
- task chart canvas id: `taskStatusChart`
- invoice chart canvas id: `invoiceStatusChart`

Chart data is injected from PHP arrays via `json_encode()` into an inline script block.

## Styling Layer

Analytics styling lives in `public_html/assets/css/style.css`.

Primary classes:

- `.admin-dashboard-grid`
- `.admin-chart-card`
- `.admin-analysis-card`
- `body.admin-dashboard .admin-stats`

Dark mode variants:

- `body.theme-dark.admin-dashboard .admin-chart-card`
- `body.theme-dark.admin-dashboard .admin-analysis-card`
- `body.theme-dark.admin-dashboard .admin-chart-card canvas`

Responsive behavior:

- Under `@media (max-width: 980px)`, grid collapses to one column.
- Chart canvas height is reduced for smaller screens.

## Safe Extension Checklist

When adding new analytics cards:

1. Add data query blocks near existing metric queries in `public_html/admin/index.php`.
2. Keep table checks with `table_exists()` if module tables may be missing.
3. Add visual card markup inside `.admin-dashboard-grid`.
4. If chart-based, add a unique canvas id and create one Chart instance.
5. Add matching CSS only in dashboard-specific selectors to avoid global regressions.
6. Validate with PHP lint and CSS diagnostics before deploy.

## Validation Commands

Run these checks after dashboard changes:

```powershell
& "c:/xampp/php/php.exe" -l "c:/M.Bista New/public_html/admin/index.php"
```

Use editor diagnostics for CSS:

- `public_html/assets/css/style.css` should show no errors.
