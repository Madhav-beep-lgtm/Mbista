# Shared UI rules

These are the user's standing preferences for changes across this app:

- Keep View, Accounts/search, Filter, Clear, Upload, Export and Create actions in a compact horizontal toolbar. Use narrow fields, aligned control heights and responsive wrapping on small screens.
- Use an upload-icon dropdown containing Upload and Download template. Upload selects a file and opens a preview; preserve validation, permissions, CSRF protection and explicit import confirmation.
- Presentation exports should be formatted Excel reports, not plain CSV: company name, company address, active fiscal year, report heading, Status where applicable, full table borders, bold group rows, readable column widths and repeating print headings. Preserve CSV separately where needed for data interchange.
- Avoid large repeated page headings or company banners when navigation already supplies that context. Keep useful form/section labels and browser titles. The Chart of Accounts and Chart Ledgers pages opt out of the page hero.
- When the user requests removal of a repeated UI element, check equivalent occurrences across admin, staff and client surfaces and apply the removal consistently. Preserve functionality and security controls unless explicitly asked to change them.
- View selectors switch content in place rather than redirecting to another page.
- Use one creation-only Create account flow for groups, subgroups, ledgers and subledgers. Masters remain system-defined. Subledgers belong to ledgers; groups/subgroups belong to masters/groups. Keep account listings and filters in Chart of Accounts, not on the creation form. Type is the section for Fixed Asset, Maintain bill by bill and Cost centre settings; Nature is the accounting classification.
- Keep platform administration separate from individual company books; do not implicitly switch company context.
- App tables use full outer, horizontal and vertical grid borders. Display transaction dates through app_date() so they follow the selected AD/BS mode; keep stored dates and input/filter values in canonical AD format.
- Apply these conventions to subsequent app changes. Do not claim that an app-wide migration has been completed without checking and updating those surfaces.
- Date fields show one AD or BS control without a secondary converted-date hint; changing modes must preserve control size and alignment. Voucher type, number and date use a compact horizontal row.
- BS date controls include a Nepali calendar picker. Voucher filter boxes have visible labels. Cost centre is required per entry line for journal, contra, payment and receipt, including cash/bank lines; do not remove it with header-level fields.
- Voucher numbers open a read-only journal popup. All voucher types share a black-and-white print layout with company name/address, voucher type/date/number, financial year, bordered debit/credit entries, totals and maker/checker signature lines.
- After completing and checking requested changes, commit the task's files and push to GitHub as the user requested. Preserve unrelated working-tree changes.
