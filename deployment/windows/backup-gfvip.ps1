<#
.SYNOPSIS
    GFVIP - Production Automated Backup for PostgreSQL and SAR Storage
.DESCRIPTION
    Creates timestamped custom-format PostgreSQL dumps (pg_dump -F c)
    and non-destructive incremental copies of Sentinel-1 SAFE archives
    and SAR evidence crops with retention enforcement and audit logging.
#>

[CmdletBinding()]
param (
    [string]$BackupRoot = "C:\backups\gfvip",
    [int]$RetentionDays = 14,
    [string]$PgDumpPath = "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe"
)

$ErrorActionPreference = "Stop"

$ScriptPath = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectPath = (Resolve-Path "$ScriptPath\..\..").Path

# Target subdirectories
$DbBackupDir = Join-Path $BackupRoot "database"
$SarBackupDir = Join-Path $BackupRoot "sar_storage"
$LogDir = Join-Path $BackupRoot "logs"

foreach ($dir in @($DbBackupDir, $SarBackupDir, $LogDir)) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$LogFile = Join-Path $LogDir "backup_$(Get-Date -Format 'yyyyMMdd').log"

function Log-Message([string]$Message, [string]$Level = "INFO") {
    $entry = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss.fff')] [$Level] $Message"
    Add-Content -Path $LogFile -Value $entry
    switch ($Level) {
        "ERROR"   { Write-Host $entry -ForegroundColor Red }
        "WARN"    { Write-Host $entry -ForegroundColor Yellow }
        "SUCCESS" { Write-Host $entry -ForegroundColor Green }
        default   { Write-Host $entry -ForegroundColor Cyan }
    }
}

Log-Message "=== Starting GFVIP Automated Backup Operation ==="
Log-Message "Project Directory: $ProjectPath"
Log-Message "Backup Root: $BackupRoot"

# Validate pg_dump executable
if (-not (Test-Path $PgDumpPath)) {
    # Fallback to searching PATH or default location
    $cmd = Get-Command "pg_dump" -ErrorAction SilentlyContinue
    if ($cmd) {
        $PgDumpPath = $cmd.Source
    } else {
        Log-Message "pg_dump.exe not found at '$PgDumpPath' or in PATH." "ERROR"
        exit 1
    }
}
Log-Message "Using pg_dump binary: $PgDumpPath"

# Read database parameters from .env via PHP to prevent hardcoding
$EnvReader = @"
require 'vendor/autoload.php';
`$app = require_once 'bootstrap/app.php';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode([
    'host' => config('database.connections.pgsql.host', '127.0.0.1'),
    'port' => config('database.connections.pgsql.port', '5432'),
    'database' => config('database.connections.pgsql.database', 'global_fishing_vessel_intelligence'),
    'username' => config('database.connections.pgsql.username', 'postgres'),
    'password' => config('database.connections.pgsql.password', '')
]);
"@

try {
    $dbConfigJson = & php -r $EnvReader
    $dbConfig = $dbConfigJson | ConvertFrom-Json
} catch {
    Log-Message "Failed to load database configuration from Laravel application: $_" "ERROR"
    exit 1
}

$DbHost = $dbConfig.host
$DbPort = $dbConfig.port
$DbName = $dbConfig.database
$DbUser = $dbConfig.username

# 1. Execute PostgreSQL Backup
$DumpFileName = "gfvip_db_${Timestamp}.dump"
$DumpFilePath = Join-Path $DbBackupDir $DumpFileName

Log-Message "Dumping database '$DbName' on ${DbHost}:${DbPort} to $DumpFileName..."

$oldPgPassword = $env:PGPASSWORD
$env:PGPASSWORD = $dbConfig.password

$startTime = Get-Date

try {
    $pgProcess = Start-Process -FilePath $PgDumpPath `
        -ArgumentList @("-h", $DbHost, "-p", $DbPort, "-U", $DbUser, "-F", "c", "-b", "-v", "-f", "`"$DumpFilePath`"", $DbName) `
        -NoNewWindow -PassThru -Wait

    if ($pgProcess.ExitCode -ne 0) {
        throw "pg_dump exited with error code $($pgProcess.ExitCode)"
    }

    if (-not (Test-Path $DumpFilePath) -or (Get-Item $DumpFilePath).Length -eq 0) {
        throw "pg_dump file was not generated or is 0 bytes."
    }

    $dumpSize = (Get-Item $DumpFilePath).Length
    $dumpDuration = [math]::Round(((Get-Date) - $startTime).TotalSeconds, 2)
    Log-Message "Database dump completed successfully in $dumpDuration s. Size: $([math]::Round($dumpSize / 1MB, 2)) MB ($dumpSize bytes)." "SUCCESS"
} catch {
    Log-Message "Database dump failed: $_" "ERROR"
    $env:PGPASSWORD = $oldPgPassword
    exit 1
} finally {
    $env:PGPASSWORD = $oldPgPassword
}

# 2. Non-Destructive SAR Storage Backup
Log-Message "Backing up Sentinel-1 SAR evidence and product archives (non-destructive)..."

$SarProductsSrc = Join-Path $ProjectPath "storage\app\sar_products"
$SarProductsDst = Join-Path $SarBackupDir "sar_products"
$SarEvidenceSrc = Join-Path $ProjectPath "storage\app\sar_evidence"
$SarEvidenceDst = Join-Path $SarBackupDir "sar_evidence"

$sarProductsCount = 0
$sarEvidenceCount = 0

if (Test-Path $SarProductsSrc) {
    if (-not (Test-Path $SarProductsDst)) { New-Item -ItemType Directory -Path $SarProductsDst -Force | Out-Null }
    # Use robocopy without /MIR to strictly avoid destructive deletion
    & robocopy $SarProductsSrc $SarProductsDst /E /XO /R:2 /W:3 /NP /NDL /NFL | Out-Null
    $rcCode = $LASTEXITCODE
    if ($rcCode -ge 8) {
        throw "robocopy for SAR products failed with error exit code $rcCode."
    }
    $sarProductsCount = (Get-ChildItem -Path $SarProductsDst -Recurse -File).Count
    Log-Message "SAR products backup completed (robocopy exit code: $rcCode). Total files in target: $sarProductsCount"
}

if (Test-Path $SarEvidenceSrc) {
    if (-not (Test-Path $SarEvidenceDst)) { New-Item -ItemType Directory -Path $SarEvidenceDst -Force | Out-Null }
    & robocopy $SarEvidenceSrc $SarEvidenceDst /E /XO /R:2 /W:3 /NP /NDL /NFL | Out-Null
    $rcCode = $LASTEXITCODE
    if ($rcCode -ge 8) {
        throw "robocopy for SAR evidence failed with error exit code $rcCode."
    }
    $sarEvidenceCount = (Get-ChildItem -Path $SarEvidenceDst -Recurse -File).Count
    Log-Message "SAR evidence backup completed (robocopy exit code: $rcCode). Total files in target: $sarEvidenceCount"
}

# 3. Retention Enforcement (Prune old database dumps safely)
Log-Message "Enforcing retention policy: keeping database dumps newer than $RetentionDays days..."
$cutoffDate = (Get-Date).AddDays(-$RetentionDays)
$allDumps = Get-ChildItem -Path $DbBackupDir -Filter "gfvip_db_*.dump" | Sort-Object LastWriteTime -Descending

# Safety: Never prune if it is the only dump, and always keep at least the newest backup
if ($allDumps.Count -gt 1) {
    $candidatesToPrune = $allDumps | Select-Object -Skip 1 | Where-Object { $_.LastWriteTime -lt $cutoffDate }
    foreach ($oldDump in $candidatesToPrune) {
        try {
            Remove-Item -Path $oldDump.FullName -Force
            Log-Message "Pruned expired dump: $($oldDump.Name) (Created: $($oldDump.LastWriteTime))" "INFO"
        } catch {
            Log-Message "Failed to prune dump '$($oldDump.Name)': $_" "WARN"
        }
    }
} else {
    Log-Message "Only 1 dump exists or no dumps exceed retention window. Retaining newest backup safely." "INFO"
}

# 4. Capture Live Snapshot Metadata for DR Validation
$snapshotCounts = @{}
try {
    $countsPhp = @"
`$pdo = new PDO('pgsql:host=$DbHost;port=$DbPort;dbname=$DbName', '$DbUser', '$($dbConfig.password)');
echo json_encode([
    'satellite_detections' => (int)`$pdo->query('SELECT count(*) FROM satellite_detections')->fetchColumn(),
    'satellite_scenes' => (int)`$pdo->query('SELECT count(*) FROM satellite_scenes')->fetchColumn(),
    'vessels' => (int)`$pdo->query('SELECT count(*) FROM vessels')->fetchColumn(),
    'vessel_positions' => (int)`$pdo->query('SELECT count(*) FROM vessel_positions')->fetchColumn(),
    'audit_logs' => (int)`$pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn()
]);
"@
    $snapshotJson = & php -r $countsPhp
    $snapshotCounts = $snapshotJson | ConvertFrom-Json
    Log-Message "Captured snapshot metadata: $($snapshotCounts.satellite_detections) detections, $($snapshotCounts.satellite_scenes) scenes, $($snapshotCounts.vessels) vessels, $($snapshotCounts.vessel_positions) positions, $($snapshotCounts.audit_logs) audit logs."
} catch {
    Log-Message "Could not query snapshot record counts: $_" "WARN"
}

# 5. Generate Machine-Readable Manifest
$TotalDuration = [math]::Round(((Get-Date) - $startTime).TotalSeconds, 2)
$Manifest = [ordered]@{
    status = "SUCCESS"
    timestamp_utc = (Get-Date).ToUniversalTime().ToString("o")
    database = @{
        database_name = $DbName
        dump_file = $DumpFileName
        dump_path = $DumpFilePath
        size_bytes = $dumpSize
        size_mb = [math]::Round($dumpSize / 1MB, 2)
    }
    snapshot_records = $snapshotCounts
    sar_storage = @{
        products_file_count = $sarProductsCount
        evidence_file_count = $sarEvidenceCount
    }
    retention_days = $RetentionDays
    duration_seconds = $TotalDuration
    gfvip_version = "2.0-GFVIP"
}

$ManifestFile = Join-Path $BackupRoot "latest_backup_manifest.json"
$Manifest | ConvertTo-Json -Depth 5 | Set-Content -Path $ManifestFile -Encoding UTF8

Log-Message "=== GFVIP Automated Backup Operation Completed Successfully in $TotalDuration s ===" "SUCCESS"
exit 0
