param(
    [ValidateSet("start", "test", "push", "all", "status")]
    [string]$Mode = "start",

    [string]$Message = "Complete development update"
)

$ErrorActionPreference = "Stop"

$ProjectRoot = "C:\M.Bista New"
$XamppRoot = "C:\xampp"
$Php = "$XamppRoot\php\php.exe"
$Mysql = "$XamppRoot\mysql\bin\mysql.exe"
$MysqlAdmin = "$XamppRoot\mysql\bin\mysqladmin.exe"
$XamppStart = "$XamppRoot\xampp_start.exe"
$AppUrl = "https://127.0.0.1:8095"
$MainDatabase = "mbista_altiora_complete_hosting"
$TestDatabase = "mbista_schema_smoke_test"

Set-Location $ProjectRoot

function Write-Section {
    param([string]$Title)

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host $Title -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan
}

function Assert-CommandSuccess {
    param([string]$Name)

    if ($LASTEXITCODE -ne 0) {
        throw "$Name failed with exit code $LASTEXITCODE."
    }

    Write-Host "[PASS] $Name" -ForegroundColor Green
}

function Initialize-DevelopmentEnvironment {
    Write-Section "DEVELOPMENT ENVIRONMENT"

    if (-not (Test-Path $Php)) {
        throw "XAMPP PHP was not found at $Php"
    }

    if (-not (Test-Path $Mysql)) {
        throw "XAMPP MySQL was not found at $Mysql"
    }

    $env:Path = "$XamppRoot\php;$XamppRoot\mysql\bin;$env:Path"
    Set-Alias -Name php -Value $Php -Scope Script

    Write-Host "Project: $ProjectRoot"
    Write-Host "PHP:     $Php"
    Write-Host "Website: $AppUrl"

    & $Php -v
    Assert-CommandSuccess "PHP availability"

    $drivers = & $Php -r "echo implode(',', PDO::getAvailableDrivers());"

    if ($drivers -notmatch "(^|,)mysql(,|$)") {
        throw "PDO MySQL is not available in XAMPP PHP."
    }

    Write-Host "[PASS] PDO MySQL driver available" -ForegroundColor Green

    $mysqlRunning = $false

    try {
        $ping = & $MysqlAdmin -u root ping 2>&1

        if ($ping -match "mysqld is alive") {
            $mysqlRunning = $true
        }
    } catch {
        $mysqlRunning = $false
    }

    $apacheRunning = Get-NetTCPConnection `
        -LocalPort 8095 `
        -State Listen `
        -ErrorAction SilentlyContinue

    if (-not $mysqlRunning -or -not $apacheRunning) {
        if (Test-Path $XamppStart) {
            Write-Host "Starting XAMPP services..." -ForegroundColor Yellow
            Start-Process -FilePath $XamppStart
            Start-Sleep -Seconds 6
        }
    }

    $ping = & $MysqlAdmin -u root ping 2>&1

    if ($ping -notmatch "mysqld is alive") {
        throw "XAMPP MySQL is not running."
    }

    Write-Host "[PASS] MySQL is running" -ForegroundColor Green

    $apacheRunning = Get-NetTCPConnection `
        -LocalPort 8095 `
        -State Listen `
        -ErrorAction SilentlyContinue

    if (-not $apacheRunning) {
        throw "Apache is not listening on port 8095."
    }

    Write-Host "[PASS] Apache HTTPS is running on port 8095" -ForegroundColor Green
}

function Test-ApplicationUrl {
    param(
        [string]$Url,
        [string[]]$ExpectedCodes
    )

    $statusCode = (
        & curl.exe -k -s -o NUL -w "%{http_code}" $Url
    ).Trim()

    if ($ExpectedCodes -notcontains $statusCode) {
        throw "HTTP test failed for $Url. Received $statusCode."
    }

    Write-Host "[PASS] $Url returned $statusCode" -ForegroundColor Green
}

function Start-Development {
    Initialize-DevelopmentEnvironment

    Write-Section "APPLICATION START"

    Test-ApplicationUrl "$AppUrl/" @("200")
    Test-ApplicationUrl "$AppUrl/login.php" @("200")
    # Retired second login page: must now 301 to /login.php, never render a form.
    Test-ApplicationUrl "$AppUrl/admin/login.php" @("301")
    Test-ApplicationUrl "$AppUrl/admin/accounting-inventory.php" @("200", "302")
    Test-ApplicationUrl "$AppUrl/admin/invoice.php" @("200", "302")

    Write-Host ""
    Write-Host "Application is ready:" -ForegroundColor Green
    Write-Host $AppUrl -ForegroundColor Cyan

    Start-Process $AppUrl
}

function Run-PhpLint {
    Write-Section "PHP LINT"

    $phpFiles = Get-ChildItem `
        -Path "app", "database", "public_html" `
        -Recurse `
        -Filter "*.php" `
        -File

    $failures = @()

    foreach ($file in $phpFiles) {
        & $Php -l $file.FullName | Out-Host

        if ($LASTEXITCODE -ne 0) {
            $failures += $file.FullName
        }
    }

    if ($failures.Count -gt 0) {
        Write-Host "Files with syntax errors:" -ForegroundColor Red
        $failures | ForEach-Object { Write-Host $_ -ForegroundColor Red }
        throw "PHP lint failed."
    }

    Write-Host "[PASS] All $($phpFiles.Count) PHP files passed lint" -ForegroundColor Green
}

function Run-PermanentTests {
    Write-Section "PERMANENT TEST SUITES"

    $tests = @(
        "database\test_inventory_valuation.php",
        "database\test_fixed_assets.php",
        "database\test_manufacturing.php",
        "database\test_lifecycle_and_nrv.php",
        "database\test_acceptance_suite.php"
    )

    foreach ($test in $tests) {
        if (-not (Test-Path $test)) {
            throw "Test file not found: $test"
        }

        Write-Host ""
        Write-Host "Running $test" -ForegroundColor Yellow

        & $Php $test

        if ($LASTEXITCODE -ne 0) {
            throw "Test failed: $test"
        }

        Write-Host "[PASS] $test" -ForegroundColor Green
    }
}

function Run-SchemaSmokeTest {
    Write-Section "FRESH SCHEMA IMPORT"

    & $Mysql -u root -e "
        DROP DATABASE IF EXISTS $TestDatabase;
        CREATE DATABASE $TestDatabase
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
    "
    Assert-CommandSuccess "Create temporary schema database"

    Get-Content "database\schema.sql" -Raw |
        & $Mysql -u root $TestDatabase

    Assert-CommandSuccess "Fresh schema import"

    Get-Content `
        "database\migrations\041_inventory_nrv_allowance_lifecycle.sql" `
        -Raw |
        & $Mysql -u root $TestDatabase

    Assert-CommandSuccess "Migration 041 first execution"

    Get-Content `
        "database\migrations\041_inventory_nrv_allowance_lifecycle.sql" `
        -Raw |
        & $Mysql -u root $TestDatabase

    Assert-CommandSuccess "Migration 041 repeated execution"

    $schemaCheck = & $Mysql `
        -u root `
        -N `
        -B `
        -D $TestDatabase `
        -e "
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '$TestDatabase'
              AND TABLE_NAME = 'inventory_nrv_assessments'
              AND COLUMN_NAME IN (
                  'release_amount',
                  'source_txn_id',
                  'status'
              );
        "

    if ([int]$schemaCheck -ne 3) {
        throw "Fresh schema is missing Migration 041 columns."
    }

    Write-Host "[PASS] Fresh schema contains Migration 041 fields" -ForegroundColor Green
}

function Run-HttpSmokeTests {
    Write-Section "HTTP SMOKE TESTS"

    Test-ApplicationUrl "$AppUrl/" @("200")
    Test-ApplicationUrl "$AppUrl/login.php" @("200")
    # Retired second login page: must now 301 to /login.php, never render a form.
    Test-ApplicationUrl "$AppUrl/admin/login.php" @("301")
    Test-ApplicationUrl "$AppUrl/admin/accounting-inventory.php" @("200", "302")
    Test-ApplicationUrl "$AppUrl/admin/invoice.php" @("200", "302")
    Test-ApplicationUrl "$AppUrl/admin/fixed-assets.php" @("200", "302")
    Test-ApplicationUrl "$AppUrl/admin/accounting.php" @("200", "302")
}

function Run-FullTests {
    Initialize-DevelopmentEnvironment
    Run-PhpLint
    Run-PermanentTests
    Run-SchemaSmokeTest
    Run-HttpSmokeTests

    Write-Section "TEST RESULT"
    Write-Host "All development checks passed." -ForegroundColor Green
}

function Show-DevelopmentStatus {
    Initialize-DevelopmentEnvironment

    Write-Section "GIT STATUS"

    git status
    git branch --show-current
    git remote -v
    git --no-pager log -5 --oneline

    Write-Section "DATABASE STATUS"

    & $Mysql -u root -e "
        SELECT
            TABLE_SCHEMA AS database_name,
            COUNT(*) AS tables_count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA IN (
            '$MainDatabase',
            '$TestDatabase'
        )
        GROUP BY TABLE_SCHEMA;
    "
}

function Push-Development {
    Initialize-DevelopmentEnvironment

    Write-Section "PRE-PUSH TESTS"
    Run-PhpLint
    Run-PermanentTests

    Write-Section "GIT COMMIT AND PUSH"

    $branch = (git branch --show-current).Trim()

    if ([string]::IsNullOrWhiteSpace($branch)) {
        throw "Current Git branch could not be identified."
    }

    git add -A
    Assert-CommandSuccess "Stage project changes"

    git diff --cached --stat

    git diff --cached --quiet

    if ($LASTEXITCODE -eq 0) {
        Write-Host "No new development changes to commit." -ForegroundColor Yellow
    } else {
        git commit -m $Message
        Assert-CommandSuccess "Create Git commit"
    }

    git fetch origin
    Assert-CommandSuccess "Fetch GitHub changes"

    git pull --rebase origin $branch
    Assert-CommandSuccess "Rebase with GitHub branch"

    git push -u origin $branch
    Assert-CommandSuccess "Push development to GitHub"

    Write-Host ""
    git --no-pager log -1 --oneline
    git status
}

switch ($Mode) {
    "start" {
        Start-Development
    }

    "test" {
        Run-FullTests
    }

    "push" {
        Push-Development
    }

    "status" {
        Show-DevelopmentStatus
    }

    "all" {
        Start-Development
        Run-PhpLint
        Run-PermanentTests
        Run-SchemaSmokeTest
        Run-HttpSmokeTests
        Push-Development
    }
}
