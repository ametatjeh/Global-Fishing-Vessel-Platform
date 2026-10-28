<#
.SYNOPSIS
    GFVIP - Register Windows Scheduled Task for Automated Backup
.DESCRIPTION
    Creates or updates a Windows Task Scheduler job (GFVIP_Automated_Backup)
    that runs backup-gfvip.ps1 daily at 02:00 AM local time.
#>

[CmdletBinding()]
param (
    [string]$TaskName = "GFVIP_Automated_Backup",
    [string]$DailyTime = "02:00",
    [switch]$Uninstall,
    [switch]$RunNow
)

$ScriptPath = Split-Path -Parent $MyInvocation.MyCommand.Definition
$BackupScript = (Resolve-Path "$ScriptPath\backup-gfvip.ps1").Path

if ($Uninstall) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "[SUCCESS] Windows Scheduled Task '$TaskName' has been unregistered." -ForegroundColor Green
    } else {
        Write-Host "[INFO] Task '$TaskName' does not exist." -ForegroundColor Yellow
    }
    exit 0
}

if ($RunNow) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Start-ScheduledTask -TaskName $TaskName
        Write-Host "[SUCCESS] Scheduled Task '$TaskName' triggered." -ForegroundColor Green
    } else {
        Write-Host "[WARN] Task '$TaskName' not found. Running backup script directly..." -ForegroundColor Yellow
        & powershell.exe -ExecutionPolicy Bypass -File $BackupScript
    }
    exit 0
}

Write-Host "Registering Windows Scheduled Task: $TaskName" -ForegroundColor Cyan
Write-Host "Target Script: $BackupScript" -ForegroundColor Gray
Write-Host "Schedule: Daily at $DailyTime" -ForegroundColor Gray

$Action = New-ScheduledTaskAction -Execute "powershell.exe" `
    -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$BackupScript`""

$Trigger = New-ScheduledTaskTrigger -Daily -At $DailyTime

$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2)

try {
    Register-ScheduledTask -TaskName $TaskName `
        -Action $Action `
        -Trigger $Trigger `
        -Settings $Settings `
        -Description "GFVIP Platform Daily Automated Backup for PostgreSQL and SAR Storage" `
        -Force | Out-Null

    Write-Host "[SUCCESS] Scheduled Task '$TaskName' registered successfully." -ForegroundColor Green
    Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName, State, @{Name="Triggers";Expression={$_.Triggers.StartBoundary}}
} catch {
    Write-Host "[ERROR] Failed to register Scheduled Task: $_" -ForegroundColor Red
    exit 1
}
