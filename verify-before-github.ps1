# Local-only acceptance checks for Windows PowerShell 5.1 or later.
# Requires Docker Desktop to be running. Never uses your production data.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-Docker {
    param([string[]] $DockerArgs)
    Write-Host ("> docker " + ($DockerArgs -join ' ')) -ForegroundColor Cyan
    & docker @DockerArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed (exit code $LASTEXITCODE). Do not publish the project yet."
    }
}

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $projectRoot
try {
    Write-Host '1/7: Building the app and starting MySQL...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', 'up', '--build', '-d', '--wait')

    Write-Host '2/7: Checking PHP syntax and running unit checks...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', 'exec', '-T', 'app', 'sh', '-lc', "find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l")
    Invoke-Docker -DockerArgs @('compose', 'exec', '-T', 'app', 'php', 'tests/run.php')

    Write-Host '3/7: Creating a separate disposable test database...' -ForegroundColor Yellow
    $sql = "CREATE DATABASE IF NOT EXISTS resource_request_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON resource_request_hub_test.* TO 'resource_app'@'%';"
    Invoke-Docker -DockerArgs @('compose', 'exec', '-T', 'db', 'mysql', '-uroot', '-plocal_root_only', '-e', $sql)

    Write-Host '4/7: Installing the test database tables...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', 'exec', '-T', '-e', 'DB_NAME=resource_request_hub_test', 'app', 'php', 'scripts/install_schema.php')

    Write-Host '5/7: Running real MySQL integration checks...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', 'exec', '-T', '-e', 'DB_NAME=resource_request_hub_test', 'app', 'php', 'tests/integration.php')

    Write-Host '6/7: Launching a separate test web server...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', '-f', 'compose.yaml', '-f', 'compose.verify.yaml', 'up', '--build', '-d', 'test-app')
    Start-Sleep -Seconds 3

    Write-Host '7/7: Testing login, permissions, CSRF, API and approval via HTTP...' -ForegroundColor Yellow
    Invoke-Docker -DockerArgs @('compose', '-f', 'compose.yaml', '-f', 'compose.verify.yaml', 'run', '--rm', 'smoke')

    Write-Host ''
    Write-Host 'AUTOMATED CHECKS PASSED. Now complete the manual checklist in docs/test-plan.md.' -ForegroundColor Green
    Write-Host 'Please do not publish until you have also tested the browser workflow manually.' -ForegroundColor Green
} finally {
    Pop-Location
}
