[CmdletBinding()]
param(
    [string]$RepoPath = ".",
    [switch]$RunTests
)

$ErrorActionPreference = "Stop"
$patchPath = Join-Path $PSScriptRoot "jewellery-traceability-overhaul.patch"
$resolvedRepo = (Resolve-Path $RepoPath).Path

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "Git is required to apply this controlled update."
}
if (-not (Test-Path $patchPath)) {
    throw "Patch file not found beside INSTALL.ps1. Extract the complete ZIP first."
}

Push-Location $resolvedRepo
try {
    if (-not (Test-Path ".git")) {
        throw "RepoPath must point to the MBCA Git checkout."
    }

    Write-Host "Checking whether the overhaul applies cleanly..."
    & git apply --check --whitespace=error-all $patchPath
    if ($LASTEXITCODE -ne 0) {
        throw "The patch does not apply cleanly. Preserve local changes and use the compatible MBCA checkout. No files were changed."
    }

    & git apply --whitespace=nowarn $patchPath
    if ($LASTEXITCODE -ne 0) {
        throw "Git could not apply the overhaul."
    }

    & git diff --check
    if ($LASTEXITCODE -ne 0) {
        throw "The applied files failed Git's whitespace validation."
    }

    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $phpFiles = @(
        "app/accounting_module_repair.php",
        "app/export_engine.php",
        "app/jewellery_assign.php",
        "app/jewellery_stock.php",
        "app/jewellery_trace.php",
        "app/jewellery_trade.php",
        "app/jewellery_workshop.php",
        "app/opening_stock_import.php",
        "app/views/partials/admin_header.php",
        "app/views/partials/jewellery_line_grid.php",
        "public_html/admin/jewellery-assign.php",
        "public_html/admin/jewellery-trace.php",
        "public_html/admin/jewellery-trade.php",
        "public_html/admin/jewellery-workshop.php",
        "public_html/admin/jewellery.php"
    )
    if ($phpCommand) {
        Write-Host "Linting changed PHP files..."
        foreach ($file in $phpFiles) {
            & $phpCommand.Source -l $file
            if ($LASTEXITCODE -ne 0) {
                throw "PHP lint failed for $file."
            }
        }
    } else {
        Write-Warning "PHP CLI was not found; PHP lint was skipped."
    }

    if ($RunTests) {
        if (-not $phpCommand) {
            throw "-RunTests requires PHP CLI."
        }
        $tests = @(
            "database/test_jewellery_fresh_schema.php",
            "database/test_opening_stock_import.php",
            "database/test_jewellery_stock.php",
            "database/test_jewellery_workshop.php",
            "database/test_jewellery_order_from_stock.php",
            "database/test_jewellery_trading.php",
            "database/test_jewellery_reversal.php"
        )
        foreach ($test in $tests) {
            Write-Host "Running $test..."
            & $phpCommand.Source $test
            if ($LASTEXITCODE -ne 0) {
                throw "Regression test failed: $test"
            }
        }
    }

    Write-Host "Overhaul applied successfully. Open a Jewellery page as an administrator to run the database repair/migration."
}
finally {
    Pop-Location
}
