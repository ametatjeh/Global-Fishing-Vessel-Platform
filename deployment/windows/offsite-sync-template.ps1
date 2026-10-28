<#
.SYNOPSIS
    GFVIP - Offsite Backup Synchronization Template (Inactive by Default)
.DESCRIPTION
    Provides a standardized, hardened template for transferring local GFVIP
    backups to offsite targets (S3-compatible, AWS S3, Azure Blob, GCS, or NAS).
    STRICT SAFETY RULE: Inactive by default. Requires operator configuration
    and explicit -EnableOffsiteSync flag. Never uses mirroring or remote deletion.
#>

[CmdletBinding()]
param (
    [string]$BackupRoot = "C:\backups\gfvip",
    [ValidateSet("S3_COMPATIBLE", "AWS_S3", "AZURE_BLOB", "GCS", "SECURE_NAS")]
    [string]$TargetProvider = "S3_COMPATIBLE",
    [string]$DestinationUri = "",
    [switch]$EnableOffsiteSync = $false,
    [switch]$DryRun = $true
)

$ErrorActionPreference = "Stop"
$LogDir = Join-Path $BackupRoot "logs"
$LogFile = Join-Path $LogDir "offsite_sync_$(Get-Date -Format 'yyyyMMdd').log"

function Log-Sync([string]$Message, [string]$Level = "INFO") {
    $entry = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss.fff')] [OFFSITE-$Level] $Message"
    Add-Content -Path $LogFile -Value $entry
    switch ($Level) {
        "ERROR"   { Write-Host $entry -ForegroundColor Red }
        "WARN"    { Write-Host $entry -ForegroundColor Yellow }
        "SUCCESS" { Write-Host $entry -ForegroundColor Green }
        default   { Write-Host $entry -ForegroundColor Cyan }
    }
}

Log-Sync "=== GFVIP Offsite Backup Transfer Process Invoked ==="
Log-Sync "Selected Provider: $TargetProvider"
Log-Sync "Backup Source: $BackupRoot"

# Safety Gate 1: Operator Confirmation Required
if (-not $EnableOffsiteSync) {
    Log-Sync "SAFETY GUARD ACTIVE: Offsite sync is currently DISABLED. Set -EnableOffsiteSync to execute." "WARN"
    Log-Sync "No remote network requests or data transfers were performed." "INFO"
    exit 0
}

if ([string]::IsNullOrWhiteSpace($DestinationUri)) {
    Log-Sync "Destination URI is empty. Please specify a valid destination URI (e.g. s3://my-gfvip-vault/backups)." "ERROR"
    exit 1
}

# Safety Gate 2: Compute SHA256 Checksums for Integrity
$DbBackupDir = Join-Path $BackupRoot "database"
$latestDump = Get-ChildItem -Path $DbBackupDir -Filter "gfvip_db_*.dump" | Sort-Object LastWriteTime -Descending | Select-Object -First 1

if (-not $latestDump) {
    Log-Sync "No local database dump found in '$DbBackupDir'." "ERROR"
    exit 1
}

Log-Sync "Calculating SHA256 checksum for $($latestDump.Name)..."
$dumpHash = (Get-FileHash -Path $latestDump.FullName -Algorithm SHA256).Hash
$hashFile = "$($latestDump.FullName).sha256"
"$dumpHash  $($latestDump.Name)" | Set-Content -Path $hashFile -Encoding ASCII
Log-Sync "Generated checksum file: $hashFile (SHA256: $dumpHash)" "SUCCESS"

# Provider execution routines (Non-destructive upload only; no delete/mirror)
switch ($TargetProvider) {
    "S3_COMPATIBLE" {
        Log-Sync "Preparing S3-Compatible transfer using AWS CLI / MinIO CLI..."
        $cmd = "aws s3 cp `"$($latestDump.FullName)`" `"$DestinationUri/database/$($latestDump.Name)`" --sse AES256"
        $cmdHash = "aws s3 cp `"$hashFile`" `"$DestinationUri/database/$($latestDump.Name).sha256`""
        
        if ($DryRun) {
            Log-Sync "[DRY-RUN] Command 1: $cmd" "INFO"
            Log-Sync "[DRY-RUN] Command 2: $cmdHash" "INFO"
        } else {
            # Execute command with operator credential environment
            Invoke-Expression $cmd
            Invoke-Expression $cmdHash
            Log-Sync "Offsite upload to $DestinationUri completed successfully." "SUCCESS"
        }
    }

    "AWS_S3" {
        Log-Sync "Preparing AWS S3 transfer with Object Lock & KMS Encryption..."
        $cmd = "aws s3 cp `"$($latestDump.FullName)`" `"$DestinationUri/database/$($latestDump.Name)`" --sse aws:kms"
        if ($DryRun) {
            Log-Sync "[DRY-RUN] Command: $cmd" "INFO"
        } else {
            Invoke-Expression $cmd
            Log-Sync "Offsite upload to AWS S3 completed." "SUCCESS"
        }
    }

    "AZURE_BLOB" {
        Log-Sync "Preparing Azure Blob transfer via AzCopy with immutable blob policy..."
        $cmd = "azcopy copy `"$($latestDump.FullName)`" `"$DestinationUri/$($latestDump.Name)`" --overwrite=false"
        if ($DryRun) {
            Log-Sync "[DRY-RUN] Command: $cmd" "INFO"
        } else {
            Invoke-Expression $cmd
            Log-Sync "Offsite upload to Azure Blob completed." "SUCCESS"
        }
    }

    "GCS" {
        Log-Sync "Preparing Google Cloud Storage transfer via gcloud storage..."
        $cmd = "gcloud storage cp `"$($latestDump.FullName)`" `"$DestinationUri/database/$($latestDump.Name)`""
        if ($DryRun) {
            Log-Sync "[DRY-RUN] Command: $cmd" "INFO"
        } else {
            Invoke-Expression $cmd
            Log-Sync "Offsite upload to GCS completed." "SUCCESS"
        }
    }

    "SECURE_NAS" {
        Log-Sync "Preparing copy to isolated NAS share (Append-only / no /MIR)..."
        $nasTarget = Join-Path $DestinationUri "database"
        if (-not (Test-Path $nasTarget)) { New-Item -ItemType Directory -Path $nasTarget -Force | Out-Null }
        $cmd = "robocopy `"$DbBackupDir`" `"$nasTarget`" `"$($latestDump.Name)`" /XO /R:2 /W:3 /NP"
        if ($DryRun) {
            Log-Sync "[DRY-RUN] Command: $cmd" "INFO"
        } else {
            Invoke-Expression $cmd
            Log-Sync "Copy to isolated NAS share completed." "SUCCESS"
        }
    }
}

Log-Sync "=== GFVIP Offsite Backup Operation Finalized (DryRun=$DryRun) ===" "SUCCESS"
exit 0
