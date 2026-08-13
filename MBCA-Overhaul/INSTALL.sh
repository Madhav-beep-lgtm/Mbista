#!/usr/bin/env bash
set -euo pipefail

package_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_path="${1:-.}"
run_tests="${2:-}"
patch_path="$package_dir/jewellery-traceability-overhaul.patch"

command -v git >/dev/null 2>&1 || { echo "Git is required." >&2; exit 1; }
[[ -f "$patch_path" ]] || { echo "Patch not found beside INSTALL.sh. Extract the complete ZIP first." >&2; exit 1; }
cd "$repo_path"
[[ -d .git ]] || { echo "Repo path must point to the MBCA Git checkout." >&2; exit 1; }

echo "Checking whether the overhaul applies cleanly..."
git apply --check --whitespace=error-all "$patch_path"
git apply --whitespace=nowarn "$patch_path"
git diff --check

php_files=(
  app/accounting_module_repair.php app/export_engine.php app/jewellery_assign.php
  app/jewellery_stock.php app/jewellery_trace.php app/jewellery_trade.php
  app/jewellery_workshop.php app/opening_stock_import.php
  app/views/partials/admin_header.php app/views/partials/jewellery_line_grid.php
  public_html/admin/jewellery-assign.php public_html/admin/jewellery-trace.php
  public_html/admin/jewellery-trade.php public_html/admin/jewellery-workshop.php
  public_html/admin/jewellery.php
)
if command -v php >/dev/null 2>&1; then
  echo "Linting changed PHP files..."
  for file in "${php_files[@]}"; do php -l "$file"; done
else
  echo "Warning: PHP CLI was not found; PHP lint was skipped." >&2
fi

if [[ "$run_tests" == "--run-tests" ]]; then
  command -v php >/dev/null 2>&1 || { echo "--run-tests requires PHP CLI." >&2; exit 1; }
  tests=(
    database/test_jewellery_fresh_schema.php database/test_opening_stock_import.php
    database/test_jewellery_stock.php database/test_jewellery_workshop.php
    database/test_jewellery_order_from_stock.php database/test_jewellery_trading.php
    database/test_jewellery_reversal.php
  )
  for test in "${tests[@]}"; do echo "Running $test..."; php "$test"; done
fi

echo "Overhaul applied successfully. Open a Jewellery page as an administrator to run the database repair/migration."
