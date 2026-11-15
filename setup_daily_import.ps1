# Creates (or replaces) a daily scheduled task that refreshes the BTC price
# history, so the chart is never more than a day stale.
#
#   powershell -ExecutionPolicy Bypass -File setup_daily_import.ps1
#
# Remove it again with:
#   Unregister-ScheduledTask -TaskName 'btc-200wma daily import' -Confirm:$false

$ErrorActionPreference = 'Stop'

$taskName = 'btc-200wma daily import'

# Resolve the repo root from this script's own location, so the task works for
# any checkout rather than only the machine it was written on.
$root = $PSScriptRoot
if (-not $root) { $root = Split-Path -Parent $MyInvocation.MyCommand.Path }
$php      = (Get-Command php).Source

if (-not (Test-Path $php)) { throw "php not found at $php" }
if (-not (Test-Path (Join-Path $root 'bin\import.php'))) { throw "import.php not found in $root" }

$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "removed existing task"
}

$action = New-ScheduledTaskAction -Execute $php `
                                  -Argument 'bin\import.php' `
                                  -WorkingDirectory $root

# 03:15 local, matching the cron line in the README.
$trigger = New-ScheduledTaskTrigger -Daily -At 3:15am

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew

# SYSTEM so it runs whether or not the user is signed in, and needs no stored
# password. The import only reads HTTP and writes to the local MySQL.
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $taskName `
                       -Action $action `
                       -Trigger $trigger `
                       -Settings $settings `
                       -Principal $principal `
                       -Description 'Refreshes the bitcoin daily price history in MySQL for the btc-200wma chart.' | Out-Null

$t = Get-ScheduledTask -TaskName $taskName
Write-Host "registered : $($t.TaskName)"
Write-Host "state      : $($t.State)"
Write-Host "action     : $($t.Actions[0].Execute) $($t.Actions[0].Arguments)"
Write-Host "workdir    : $($t.Actions[0].WorkingDirectory)"
Write-Host "trigger    : daily at 03:15 (runs on next boot if missed)"
