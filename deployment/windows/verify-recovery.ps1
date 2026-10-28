<#
.SYNOPSIS
    GFVIP - Isolated Disaster Recovery & Backup Integrity Verification
.DESCRIPTION
    Restores the latest PostgreSQL backup dump into a strictly isolated,
    temporary test database (gfvip_isolated_recovery_test) without touching
    the operational database. Verifies spatial tables, detections, scenes,
    vessels, and audit logs integrity before safely tearing down.
#>

[CmdletBinding()]
param (
    [string]$BackupRoot = "C:\backups\gfvip",
    [string]$TestDbName = "gfvip_isolated_recovery_test",
    [string]$PgRestorePath = "C:\Program Files\PostgreSQL\18\bin\pg_restore.exe",
    [string]$PsqlPath = "C:\Program Files\PostgreSQL\18\bin\psql.exe"
)

$ErrorActionPreference = "Stop"

$ScriptPath = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectPath = (Resolve-Path "$ScriptPath\..\..").Path
$LogDir = Join-Path $BackupRoot "logs"
$LogFile = Join-Path $LogDir "recovery_test_$(Get-Date -Format 'yyyyMMdd').log"

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

Log-Message "=== Starting GFVIP Isolated Disaster Recovery Test ==="

# 1. Locate latest dump
$DbBackupDir = Join-Path $BackupRoot "database"
$latestDump = Get-ChildItem -Path $DbBackupDir -Filter "gfvip_db_*.dump" | Sort-Object LastWriteTime -Descending | Select-Object -First 1

if (-not $latestDump) {
    Log-Message "No PostgreSQL dump file found in '$DbBackupDir'." "ERROR"
    exit 1
}

Log-Message "Selected latest dump for recovery test: $($latestDump.Name) ($($latestDump.Length) bytes)"

# Read database credentials via PHP to avoid hardcoding secrets
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

$dbConfig = (& php -r $EnvReader) | ConvertFrom-Json
$DbHost = $dbConfig.host
$DbPort = $dbConfig.port
$DbUser = $dbConfig.username
$DbOperationalName = $dbConfig.database

# Safety check 1: Verify test database isolation
if ($TestDbName -eq $DbOperationalName) {
    Log-Message "FATAL SAFETY VIOLATION: Test database name '$TestDbName' matches operational database '$DbOperationalName'!" "ERROR"
    exit 1
}

if ($TestDbName -notlike "*test*" -and $TestDbName -notlike "*recovery*") {
    Log-Message "FATAL SAFETY VIOLATION: Test database name must contain 'test' or 'recovery' for safety verification." "ERROR"
    exit 1
}

# Safety check 2: Check manifest snapshot records if present
$manifestFile = Join-Path $BackupRoot "latest_backup_manifest.json"
$expectedRecords = $null
if (Test-Path $manifestFile) {
    try {
        $manifestObj = (Get-Content $manifestFile -Raw -Encoding UTF8) | ConvertFrom-Json
        if ($manifestObj.snapshot_records) {
            $expectedRecords = $manifestObj.snapshot_records
            Log-Message "Loaded snapshot expectation from manifest: Detections=$($expectedRecords.satellite_detections), Scenes=$($expectedRecords.satellite_scenes), Vessels=$($expectedRecords.vessels), Positions=$($expectedRecords.vessel_positions), AuditLogs=$($expectedRecords.audit_logs)"
        }
    } catch {
        Log-Message "Could not parse manifest for record comparisons: $_" "WARN"
    }
}

$oldPgPassword = $env:PGPASSWORD
$env:PGPASSWORD = $dbConfig.password

try {
    # 2. Re-create isolated recovery test database
    Log-Message "Preparing isolated test database '${TestDbName}' on ${DbHost}:${DbPort}..."

    # Drop old test database if exists (strictly target $TestDbName only)
    $dropCmd = "DROP DATABASE IF EXISTS `"$TestDbName`";"
    & $PsqlPath -h $DbHost -p $DbPort -U $DbUser -d postgres -c $dropCmd | Out-Null

    # Create new isolated test database
    $createCmd = "CREATE DATABASE `"$TestDbName`";"
    & $PsqlPath -h $DbHost -p $DbPort -U $DbUser -d postgres -c $createCmd | Out-Null
    Log-Message "Isolated database '${TestDbName}' created successfully."

    # 3. Restore dump into isolated test database
    Log-Message "Restoring dump into '${TestDbName}' using pg_restore..."
    $restoreProcess = Start-Process -FilePath $PgRestorePath `
        -ArgumentList @("-h", $DbHost, "-p", $DbPort, "-U", $DbUser, "-d", $TestDbName, "--no-owner", "--no-privileges", "`"$($latestDump.FullName)`"") `
        -NoNewWindow -PassThru -Wait

    # pg_restore returns 0 (success) or 1 (warnings, e.g. already existing types in template/extension)
    if ($restoreProcess.ExitCode -gt 1) {
        throw "pg_restore failed with exit code $($restoreProcess.ExitCode)"
    }
    Log-Message "pg_restore execution completed (Exit code: $($restoreProcess.ExitCode))."

    # 4. Verify data integrity in isolated database
    Log-Message "Executing data integrity checks on restored database..."

    $verificationScript = @"
`$pdo = new PDO('pgsql:host=$DbHost;port=$DbPort;dbname=$TestDbName', '$DbUser', '$($dbConfig.password)');
`$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

`$detections = `$pdo->query('SELECT count(*) FROM satellite_detections')->fetchColumn();
`$scenes = `$pdo->query('SELECT count(*) FROM satellite_scenes')->fetchColumn();
`$vessels = `$pdo->query('SELECT count(*) FROM vessels')->fetchColumn();
`$positions = `$pdo->query('SELECT count(*) FROM vessel_positions')->fetchColumn();
`$auditLogs = `$pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn();

// Check specific compound candidate
`$stmt = `$pdo->prepare('SELECT count(*) FROM satellite_detections WHERE detection_identifier = ?');
`$stmt->execute(['542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054']);
`$candidate0054Exists = `$stmt->fetchColumn();

echo json_encode([
    'detections_count' => (int)`$detections,
    'scenes_count' => (int)`$scenes,
    'vessels_count' => (int)`$vessels,
    'positions_count' => (int)`$positions,
    'audit_logs_count' => (int)`$auditLogs,
    'candidate_0054_exists' => (int)`$candidate0054Exists > 0
]);
"@

    $verifyJson = & php -r $verificationScript
    $verifyData = $verifyJson | ConvertFrom-Json

    Log-Message "Restored Entities: Detections: $($verifyData.detections_count), Scenes: $($verifyData.scenes_count), Vessels: $($verifyData.vessels_count), Positions: $($verifyData.positions_count), Audit Logs: $($verifyData.audit_logs_count)"
    
    $expectedDetections = if ($expectedRecords -and $expectedRecords.satellite_detections) { [int]$expectedRecords.satellite_detections } else { 54 }
    $expectedScenes = if ($expectedRecords -and $expectedRecords.satellite_scenes) { [int]$expectedRecords.satellite_scenes } else { 2 }

    if ($verifyData.detections_count -ne $expectedDetections) {
        throw "Verification mismatch: expected $expectedDetections detections, found $($verifyData.detections_count)"
    }
    if ($verifyData.scenes_count -ne $expectedScenes) {
        throw "Verification mismatch: expected $expectedScenes scenes, found $($verifyData.scenes_count)"
    }
    if ($verifyData.audit_logs_count -lt 22) {
        throw "Verification mismatch: expected at least 22 audit logs, found $($verifyData.audit_logs_count)"
    }
    if (-not $verifyData.candidate_0054_exists) {
        throw "Candidate 542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054 not found in restored database."
    }

    Log-Message "Database integrity verification: 100% PASSED!" "SUCCESS"

    # 5. Verify SAR Storage Files in backup
    Log-Message "Verifying SAR Storage integrity in '$BackupRoot\sar_storage'..."
    $sarEvidenceBackupDir = Join-Path $BackupRoot "sar_storage\sar_evidence\542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7"
    $candidate0054Dir = Join-Path $sarEvidenceBackupDir "542e73f3-35ea-4ff6-bc6a-fa71d5d1fee7-0054"

    $requiredFiles = @("raw.png", "sigma0-linear.png", "sigma0-db.png", "metadata.json")
    foreach ($file in $requiredFiles) {
        $filePath = Join-Path $candidate0054Dir $file
        if (-not (Test-Path $filePath)) {
            throw "SAR Evidence file missing in backup: $file"
        }
    }
    Log-Message "SAR Evidence crops verification: 100% PASSED (all raster crops and metadata.json confirmed)!" "SUCCESS"

    # 6. Generate Machine-Readable Recovery Test Report
    $RecoveryReport = [ordered]@{
        status = "PASS"
        tested_at_utc = (Get-Date).ToUniversalTime().ToString("o")
        dump_tested = $latestDump.Name
        dump_size_bytes = $latestDump.Length
        isolated_database = $TestDbName
        verification = @{
            satellite_detections = $verifyData.detections_count
            satellite_scenes = $verifyData.scenes_count
            vessels = $verifyData.vessels_count
            vessel_positions = $verifyData.positions_count
            audit_logs = $verifyData.audit_logs_count
            sar_evidence_verified = $true
            candidate_0054_verified = $true
        }
        manifest_comparison = if ($expectedRecords) {
            @{
                matched_detections = ($verifyData.detections_count -eq $expectedDetections)
                matched_scenes = ($verifyData.scenes_count -eq $expectedScenes)
                snapshot_vessels = $expectedRecords.vessels
                restored_vessels = $verifyData.vessels_count
                snapshot_positions = $expectedRecords.vessel_positions
                restored_positions = $verifyData.positions_count
            }
        } else { $null }
        conclusion = "Disaster Recovery Test completed successfully on isolated database without modifying operational data."
    }

    $ReportFile = Join-Path $BackupRoot "latest_recovery_test_report.json"
    $RecoveryReport | ConvertTo-Json -Depth 5 | Set-Content -Path $ReportFile -Encoding UTF8

    Log-Message "=== Isolated Disaster Recovery Test Result: FULL PASS ===" "SUCCESS"

} catch {
    Log-Message "Disaster recovery test failed: $_" "ERROR"
    $FailureReport = [ordered]@{
        status = "FAIL"
        tested_at_utc = (Get-Date).ToUniversalTime().ToString("o")
        dump_tested = if ($latestDump) { $latestDump.Name } else { "unknown" }
        error = "$_"
        conclusion = "Disaster Recovery Test failed. See logs for details."
    }
    $ReportFile = Join-Path $BackupRoot "latest_recovery_test_report.json"
    $FailureReport | ConvertTo-Json -Depth 5 | Set-Content -Path $ReportFile -Encoding UTF8
    exit 1
} finally {
    # 7. Clean up isolated test database safely
    Log-Message "Tearing down isolated test database '${TestDbName}'..."
    try {
        if ($TestDbName -ne $DbOperationalName -and ($TestDbName -like "*test*" -or $TestDbName -like "*recovery*")) {
            $dropCmd = "DROP DATABASE IF EXISTS `"$TestDbName`";"
            & $PsqlPath -h $DbHost -p $DbPort -U $DbUser -d postgres -c $dropCmd | Out-Null
            Log-Message "Isolated test database dropped safely."
        } else {
            Log-Message "Skipping teardown drop due to safety validation failure on database name '${TestDbName}'." "WARN"
        }
    } catch {
        Log-Message "Warning during teardown: $_" "WARN"
    }

    $env:PGPASSWORD = $oldPgPassword
}

exit 0
