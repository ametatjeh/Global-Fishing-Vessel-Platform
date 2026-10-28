<#
.SYNOPSIS
    GFVIP - Local Windows AIS Stream Daemon Runner
.DESCRIPTION
    Runs php artisan ais:listen --daemon with single-instance mutex protection
    and graceful stop monitoring on local Windows workstations.
#>

$ScriptPath = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectPath = (Resolve-Path "$ScriptPath\..\..").Path

Set-Location $ProjectPath

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " GFVIP - AIS Live Stream Local Windows Worker" -ForegroundColor Cyan
Write-Host " Project: $ProjectPath" -ForegroundColor Gray
Write-Host "==========================================================" -ForegroundColor Cyan

# Check if PHP is available
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Error "PHP executable not found in PATH."
    exit 1
}

# Ensure single instance using Mutex
$MutexName = "Global\GFVIP_AIS_STREAM_LOCAL_WORKER"
$createdNew = $false
$mutex = New-Object System.Threading.Mutex($true, $MutexName, [ref]$createdNew)

if (-not $createdNew) {
    Write-Warning "Proses AIS Live Stream Worker lokal lainnya sudah berjalan."
    Write-Warning "Duplikasi koneksi dicegah oleh Mutex sistem."
    exit 0
}

try {
    Write-Host "[INFO] Menjalankan AIS Stream Daemon (Tekan Ctrl+C untuk berhenti)..." -ForegroundColor Green
    & php artisan ais:listen --daemon --timeout=30 --max-reconnects=20
}
finally {
    $mutex.ReleaseMutex()
    $mutex.Dispose()
    Write-Host "[INFO] AIS Stream Worker lokal telah dihentikan secara aman." -ForegroundColor Yellow
}
