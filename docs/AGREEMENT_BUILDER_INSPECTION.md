# Service Agreement Builder — Inspection Report

Date: 2026-07-24 · Scope: clone the "Working Procedures" drafting methodology
onto Work Portal → Contracts → Service Agreements.

## 1. Working Procedures / Sahakari ERP source

**Not present in this repository or workspace.** The only "sahakari" artefact is
`public_html/assets/css/theme-sahakari-green.css` (a colour theme). Per the
specification's fallback rule, the drafting method is reconstructed from the
specification, following MBCA's own architecture throughout.

## 2. Existing related files

| Area | File(s) |
|---|---|
| Contracts (Work Portal) | `public_html/admin/workspace.php` (view=contracts), `public_html/admin/export-contract.php` |
| Service Agreements (classic) | `public_html/admin/service-agreements.php` (flat bilingual form), `public_html/admin/export-agreement.php` (NP/EN/bilingual print) |
| Client master | `client_profiles` table; admin client pages |
| Tasks | `client_tasks` (has `contract_id` FK — tasks already link to contracts), workspace.php view=tasks |
| Client portal | `public_html/dashboard.php` (views: contracts, documents, tasks, invoices…) |
| RBAC | `app/access_control.php` — `rbac_modules()` module.action grants, `user_can_do()`, `require_permission()`; admins implicitly full within authorized companies |
| Audit | `log_activity()` → `activity_logs`; `log_field_changes()` → `audit_change_history` (field-level before/after) |
| Attachments | `documents` table + MIME-sniffing secure upload helpers (`secure_uploads/`), client `visibility` flag, version lineage |
| Bilingual | NP/EN column pairs on `service_agreements`, `bs_format()` BS dates, Devanagari print CSS |
| Exports | Print-HTML → browser PDF (invoices, agreements); `export_csv()`; DOC/DOCX accepted as uploads; no Word generation yet |
| Migrations | `database/migrations/NNN_*.sql` + idempotent repair steps in `app/accounting_module_repair.php` |
| Concurrency | `SELECT … FOR UPDATE` row-lock transaction pattern (used for company-scoped sequences) |

## 3. Existing DB tables involved

`service_contracts`, `service_agreements` (065: 1:1 `contract_id` link),
`client_profiles`, `client_tasks`, `documents`, `activity_logs`,
`audit_change_history`, `staff_permissions`.

## 4. Current Service Agreement workflow

Single flat form (fixed 10-chapter firm format, bilingual fields, Annex-1
service rows, witnesses), status `draft|final`, 3 print modes. No hierarchy,
no versioning, no approvals, no templates, no clause-level task links, no
client snapshot, no portal visibility.

## 5. Gaps vs the Working Procedures drafting method

| Requirement | Current | Plan |
|---|---|---|
| Section/subsection hierarchy, stable IDs | flat columns | new `agreement_sections` (parent_id tree, sort_order; numbers computed for display only) |
| Auto renumbering | n/a | computed on render from tree order (chaptered docs number clauses continuously — the firm's दफा style; flat docs use 1 / 1.1 / 1.1.1) |
| Versioning / immutable approvals | none | `agreement_versions` (JSON snapshots, UNIQUE(agreement, version)) |
| Lifecycle | draft/final | `workflow_status` (draft → … → archived) with guarded transitions, maker-checker |
| Review comments | none | `agreement_comments` (per section/version, open/resolved) |
| Templates + clause library | hardcoded defaults | `agreement_templates` (snapshot on instantiate; later template edits never touch existing agreements) |
| Task links (clause level) | via contract only | `agreement_task_links` (UNIQUE agreement+task, optional section anchor) — reuses `client_tasks`, no parallel task system |
| Client snapshot | live join | `client_snapshot_json` frozen at approval/issue |
| Placeholders | none | `{{token}}` resolved at render/export only |
| Portal visibility + acceptance | none | dashboard.php contracts view: issued agreements, language choice, PDF, electronic acceptance (recorded as acceptance, NOT called a digital signature) |
| Permissions | admin-only | new `agreements` RBAC module (view/create/edit/review/approve/issue/manage/export), enforced server-side |
| Word export | none | HTML-based `.doc` (safe with current stack) |

## 6. Files to modify / create

Modify: `app/access_control.php`, `app/accounting_module_repair.php`,
`public_html/admin/service-agreements.php`, `public_html/admin/export-agreement.php`,
`public_html/admin/workspace.php` (task list agreement chip), `public_html/dashboard.php`.
Create: `database/migrations/066_agreement_builder.sql`, `app/agreement_builder.php`
(engine), `public_html/admin/agreement-builder.php` (3-panel workspace),
`database/test_agreement_builder.php`.

## 7. Migrations required

066: 5 new tables (`agreement_sections`, `agreement_versions`,
`agreement_task_links`, `agreement_templates`, `agreement_comments`) +
workflow/language/snapshot columns on `service_agreements`. Purely additive —
no column drops, no data rewrites.

## 8. Risks and mitigations

- **Existing agreements** keep `structure_mode='classic'` and render exactly as
  today; only new agreements use the builder. Legacy `status` stays in sync
  with `workflow_status` so every existing list keeps working.
- **Existing contract/task data** untouched; task links are a new additive table.
- **Client portal** additions are read-only plus two guarded POST actions, all
  scoped to the logged-in client's own `client_id`.
- Migration is additive and mirrored by an idempotent repair step, so cPanel
  deploys self-heal exactly like every migration since 021.
