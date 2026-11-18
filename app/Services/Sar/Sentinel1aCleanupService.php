<?php

namespace App\Services\Sar;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\SatelliteDetection;
use App\Models\SatelliteScene;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class Sentinel1aCleanupService
{
    /**
     * Storage base paths inspected for SAR assets.
     */
    protected function getStorageBases(): array
    {
        return [
            'sar_products' => config('maritime.providers.copernicus_sar.pipeline.storage_dir', storage_path('app/sar_products')),
            'sar_evidence' => storage_path('app/sar_evidence'),
            'public_evidence' => public_path('evidence'),
        ];
    }

    /**
     * Conduct comprehensive inventory of Sentinel-1A assets and verify non-S1A protection.
     */
    public function conductInventory(): array
    {
        $allScenes = SatelliteScene::all();

        $s1aScenes = [];
        $protectedScenes = [];

        foreach ($allScenes as $scene) {
            if ($this->isSentinel1A($scene)) {
                $s1aScenes[] = $scene;
            } else {
                $protectedScenes[] = $scene;
            }
        }

        $s1aSceneIds = array_map(fn($s) => $s->id, $s1aScenes);
        $s1aSceneCodeIdentifiers = array_filter(array_map(fn($s) => $s->scene_id, $s1aScenes));

        // Detections belonging to S1A scenes
        $s1aDetections = collect();
        if (!empty($s1aSceneIds)) {
            $s1aDetections = SatelliteDetection::whereIn('satellite_scene_id', $s1aSceneIds)->get();
        }

        // Check for any orphan detections that explicitly have S1A identifiers
        $orphanS1aDetections = SatelliteDetection::whereNotIn('id', $s1aDetections->pluck('id')->all())
            ->where(function ($q) {
                $q->where('detection_identifier', 'ILIKE', 'S1A_%')
                  ->orWhere('detection_identifier', 'ILIKE', 's1a-%');
            })
            ->get();

        $allS1aDetections = $s1aDetections->concat($orphanS1aDetections);
        $s1aDetectionIds = $allS1aDetections->pluck('id')->all();
        $s1aDetectionIdentifiers = $allS1aDetections->pluck('detection_identifier')->filter()->all();

        // Protected detections (S1B, S1C, and others)
        $protectedDetectionsCount = SatelliteDetection::whereNotIn('id', $s1aDetectionIds)->count();

        // Check affected investigations
        $affectedInvestigations = collect();
        if (!empty($s1aDetectionIds)) {
            $affectedInvestigations = Investigation::whereIn('related_detection_id', $s1aDetectionIds)->get();
        }

        // Scan files
        $fileInventory = $this->inventoryFiles($s1aSceneCodeIdentifiers, $s1aDetectionIdentifiers);

        // Check cache files
        $cacheInventory = $this->inventoryCacheFiles($s1aSceneCodeIdentifiers, $s1aDetectionIdentifiers);

        return [
            's1a_scenes' => $s1aScenes,
            's1a_scenes_count' => count($s1aScenes),
            's1a_scene_ids' => $s1aSceneIds,
            's1a_scene_code_identifiers' => $s1aSceneCodeIdentifiers,
            's1a_detections' => $allS1aDetections,
            's1a_detections_count' => count($allS1aDetections),
            's1a_detection_ids' => $s1aDetectionIds,
            'affected_investigations' => $affectedInvestigations,
            'affected_investigations_count' => count($affectedInvestigations),
            'protected_scenes' => $protectedScenes,
            'protected_scenes_count' => count($protectedScenes),
            'protected_detections_count' => $protectedDetectionsCount,
            'files_to_delete' => $fileInventory['files'],
            'dirs_to_delete' => $fileInventory['dirs'],
            'total_files_count' => count($fileInventory['files']),
            'total_file_bytes' => $fileInventory['total_bytes'],
            'protected_files_count' => $fileInventory['protected_count'],
            'cache_entries' => $cacheInventory,
        ];
    }

    /**
     * Strictly verify if a SatelliteScene is Sentinel-1A.
     * Guaranteed never to match Sentinel-1B or Sentinel-1C.
     */
    public function isSentinel1A(SatelliteScene $scene): bool
    {
        $platform = strtolower(trim((string) $scene->platform_name));
        $sceneId = trim((string) $scene->scene_id);
        $metadata = (array) ($scene->metadata ?? []);
        $serial = strtoupper(trim((string) ($metadata['platform_serial_identifier'] ?? '')));
        $prodName = trim((string) ($metadata['product_name'] ?? ''));

        // 1. Strict Negative Guard (Sentinel-1B or Sentinel-1C protection)
        if (
            str_contains($platform, '1b') ||
            str_contains($platform, '1c') ||
            str_starts_with(strtoupper($sceneId), 'S1B_') ||
            str_starts_with(strtoupper($sceneId), 'S1C_') ||
            $serial === 'B' ||
            $serial === 'C' ||
            str_starts_with(strtoupper($prodName), 'S1B_') ||
            str_starts_with(strtoupper($prodName), 'S1C_')
        ) {
            return false;
        }

        // 2. Positive Sentinel-1A Verification
        if (
            $platform === 'sentinel-1a' ||
            str_starts_with(strtoupper($sceneId), 'S1A_') ||
            $serial === 'A' ||
            str_starts_with(strtoupper($prodName), 'S1A_')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Scan disk directories for verified Sentinel-1A files and directory structures.
     */
    protected function inventoryFiles(array $s1aSceneCodes, array $s1aDetectionIdentifiers): array
    {
        $filesToDelete = [];
        $dirsToDelete = [];
        $totalBytes = 0;
        $protectedCount = 0;

        $targetPatterns = [];
        foreach ($s1aSceneCodes as $code) {
            $targetPatterns[] = strtolower($code);
        }
        foreach ($s1aDetectionIdentifiers as $ident) {
            $targetPatterns[] = strtolower($ident);
        }

        $bases = $this->getStorageBases();

        foreach ($bases as $baseKey => $baseDir) {
            if (!file_exists($baseDir)) {
                continue;
            }

            $realBase = realpath($baseDir);
            if (!$realBase) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realBase, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getRealPath();
                if (!$path) {
                    continue;
                }

                // Canonical path traversal prevention
                if (!str_starts_with($path, $realBase) || $path === $realBase) {
                    continue;
                }

                $filename = $item->getFilename();
                $lowerName = strtolower($filename);

                // Protected assets (Sentinel-1B, Sentinel-1C, git, etc.)
                if (
                    str_contains($lowerName, 's1b') ||
                    str_contains($lowerName, 's1c') ||
                    $lowerName === '.gitignore' ||
                    $lowerName === '.gitkeep'
                ) {
                    $protectedCount++;
                    continue;
                }

                $isTarget = false;

                // Check S1A specific prefixes
                if (
                    str_starts_with($lowerName, 's1a_') ||
                    str_starts_with($lowerName, 's1a-')
                ) {
                    $isTarget = true;
                }

                // Check matched scene codes or detection identifiers
                if (!$isTarget) {
                    foreach ($targetPatterns as $pattern) {
                        if ($pattern !== '' && str_contains($lowerName, $pattern)) {
                            $isTarget = true;
                            break;
                        }
                    }
                }

                // Check parent directory name for evidence folders
                if (!$isTarget && $baseKey === 'sar_evidence') {
                    $parentDirName = strtolower(basename(dirname($path)));
                    foreach ($targetPatterns as $pattern) {
                        if ($pattern !== '' && ($parentDirName === $pattern || str_starts_with($parentDirName, 's1a'))) {
                            $isTarget = true;
                            break;
                        }
                    }
                }

                if ($isTarget) {
                    if ($item->isDir()) {
                        $dirsToDelete[] = $path;
                    } else {
                        $size = $item->getSize();
                        $filesToDelete[] = [
                            'path' => $path,
                            'relative' => str_replace('\\', '/', substr($path, strlen(base_path()) + 1)),
                            'size' => $size,
                        ];
                        $totalBytes += $size;
                    }
                }
            }
        }

        // Deduplicate dirs and sort deepest first
        $dirsToDelete = array_unique($dirsToDelete);
        usort($dirsToDelete, fn($a, $b) => strlen($b) <=> strlen($a));

        return [
            'files' => $filesToDelete,
            'dirs' => $dirsToDelete,
            'total_bytes' => $totalBytes,
            'protected_count' => $protectedCount,
        ];
    }

    /**
     * Inspect cache JSON files for S1A candidates.
     */
    protected function inventoryCacheFiles(array $s1aSceneCodes, array $s1aDetectionIdentifiers): array
    {
        $cacheFiles = [
            'sar_candidates_cache' => storage_path('app/sar_candidates_cache.json'),
            'candidates_for_evidence' => storage_path('app/candidates_for_evidence.json'),
        ];

        $results = [];

        foreach ($cacheFiles as $key => $filePath) {
            if (!file_exists($filePath)) {
                $results[$key] = ['exists' => false, 's1a_count' => 0, 'total_count' => 0];
                continue;
            }

            $content = json_decode(file_get_contents($filePath), true);
            if (!is_array($content)) {
                $results[$key] = ['exists' => true, 's1a_count' => 0, 'total_count' => 0];
                continue;
            }

            $total = count($content);
            $s1aCount = 0;

            foreach ($content as $entry) {
                $entryStr = strtolower(json_encode($entry));
                if (str_contains($entryStr, 's1a_') || str_contains($entryStr, 's1a-')) {
                    $s1aCount++;
                    continue;
                }
                foreach ($s1aSceneCodes as $code) {
                    if (str_contains($entryStr, strtolower($code))) {
                        $s1aCount++;
                        break;
                    }
                }
            }

            $results[$key] = [
                'exists' => true,
                'path' => $filePath,
                's1a_count' => $s1aCount,
                'total_count' => $total,
            ];
        }

        return $results;
    }

    /**
     * Resolve and validate target backup directory.
     */
    public function resolveBackupDirectory(?string $customPath = null): string
    {
        if (!empty($customPath)) {
            return rtrim($customPath, '/\\');
        }

        if (is_dir('/www/backup/gfvip') || is_writable('/www/backup')) {
            $base = '/www/backup/gfvip/sentinel-1a-cleanup';
        } else {
            $base = storage_path('app/backups/sentinel-1a-cleanup');
        }

        $timestamp = date('Ymd_His');
        return $base . DIRECTORY_SEPARATOR . $timestamp;
    }

    /**
     * Create pre-cleanup snapshot backup of all targeted database records and files.
     */
    public function createBackup(array $inventory, string $backupDir): array
    {
        $dbBackupDir = $backupDir . DIRECTORY_SEPARATOR . 'db';
        $filesBackupDir = $backupDir . DIRECTORY_SEPARATOR . 'files';

        File::ensureDirectoryExists($dbBackupDir);
        File::ensureDirectoryExists($filesBackupDir);

        if (!is_writable($backupDir)) {
            throw new \RuntimeException("Direktori backup tidak dapat ditulis: {$backupDir}");
        }

        // 1. Save Scenes JSON
        $scenesData = array_map(function ($s) {
            return is_array($s) ? $s : $s->toArray();
        }, $inventory['s1a_scenes']);
        file_put_contents(
            $dbBackupDir . DIRECTORY_SEPARATOR . 'satellite_scenes_s1a.json',
            json_encode($scenesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 2. Save Detections JSON
        $detectionsData = $inventory['s1a_detections']->map(function ($d) {
            return is_array($d) ? $d : $d->toArray();
        })->all();
        file_put_contents(
            $dbBackupDir . DIRECTORY_SEPARATOR . 'satellite_detections_s1a.json',
            json_encode($detectionsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 3. Save Affected Investigations JSON
        $invData = array_map(function ($inv) {
            return is_array($inv) ? $inv : $inv->toArray();
        }, $inventory['affected_investigations']->all());
        file_put_contents(
            $dbBackupDir . DIRECTORY_SEPARATOR . 'affected_investigations.json',
            json_encode($invData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 4. Save Comprehensive Inventory Manifest
        $manifest = [
            'created_at' => now()->toIso8601String(),
            'platform' => 'Sentinel-1A',
            'summary' => [
                's1a_scenes_count' => $inventory['s1a_scenes_count'],
                's1a_detections_count' => $inventory['s1a_detections_count'],
                'affected_investigations_count' => $inventory['affected_investigations_count'],
                'total_files_count' => $inventory['total_files_count'],
                'total_file_bytes' => $inventory['total_file_bytes'],
                'protected_scenes_count' => $inventory['protected_scenes_count'],
                'protected_detections_count' => $inventory['protected_detections_count'],
            ],
            'scenes' => array_map(fn($s) => [
                'id' => $s['id'] ?? $s->id,
                'scene_id' => $s['scene_id'] ?? $s->scene_id,
                'platform_name' => $s['platform_name'] ?? $s->platform_name,
            ], $inventory['s1a_scenes']),
            'files' => array_column($inventory['files_to_delete'], 'relative'),
        ];
        file_put_contents(
            $backupDir . DIRECTORY_SEPARATOR . 'inventory.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 5. Physical Files Backup Copy
        $copiedFiles = 0;
        foreach ($inventory['files_to_delete'] as $fileInfo) {
            $src = $fileInfo['path'];
            if (!file_exists($src)) {
                continue;
            }

            $dest = $filesBackupDir . DIRECTORY_SEPARATOR . $fileInfo['relative'];
            File::ensureDirectoryExists(dirname($dest));

            if (!copy($src, $dest)) {
                throw new \RuntimeException("Gagal menyalin file backup dari {$src} ke {$dest}");
            }

            if (filesize($dest) !== filesize($src)) {
                throw new \RuntimeException("Integritas ukuran file backup tidak cocok untuk {$dest}");
            }

            $copiedFiles++;
        }

        return [
            'backup_dir' => $backupDir,
            'scenes_backed_up' => count($scenesData),
            'detections_backed_up' => count($detectionsData),
            'investigations_noted' => count($invData),
            'files_copied' => $copiedFiles,
        ];
    }

    /**
     * Execute full cleanup of Sentinel-1A assets.
     */
    public function executeCleanup(array $inventory, bool $dryRun = true, ?string $customBackupPath = null): array
    {
        $backupDir = $this->resolveBackupDirectory($customBackupPath);

        if ($dryRun) {
            return [
                'status' => 'dry_run_completed',
                'dry_run' => true,
                'planned_scenes_deleted' => $inventory['s1a_scenes_count'],
                'planned_detections_deleted' => $inventory['s1a_detections_count'],
                'planned_investigations_decoupled' => $inventory['affected_investigations_count'],
                'planned_files_deleted' => $inventory['total_files_count'],
                'planned_file_bytes' => $inventory['total_file_bytes'],
                'protected_scenes_count' => $inventory['protected_scenes_count'],
                'protected_detections_count' => $inventory['protected_detections_count'],
                'backup_destination' => $backupDir,
                'message' => 'Simulasi dry-run selesai. Tidak ada data atau file yang diubah.',
            ];
        }

        // 1. Mandatory Pre-cleanup Backup
        $backupResult = $this->createBackup($inventory, $backupDir);

        // 2. Transactional Database Cleanup
        $deletedDetectionsCount = 0;
        $deletedScenesCount = 0;
        $decoupledInvestigationsCount = 0;

        DB::transaction(function () use (
            $inventory,
            $backupDir,
            &$deletedDetectionsCount,
            &$deletedScenesCount,
            &$decoupledInvestigationsCount
        ) {
            // Decouple affected investigations safely preserving audit trail
            foreach ($inventory['affected_investigations'] as $inv) {
                $detId = $inv->related_detection_id;
                $inv->related_detection_id = null;
                $auditNote = sprintf(
                    "\n[Audit: Referensi deteksi SAR Sentinel-1A (%s) didekomisioning dan dihapus pada %s]",
                    $detId,
                    now()->toIso8601String()
                );
                $inv->findings = ($inv->findings ? $inv->findings . $auditNote : trim($auditNote));
                $inv->save();
                $decoupledInvestigationsCount++;
            }

            // Delete S1A detections
            if (!empty($inventory['s1a_detection_ids'])) {
                $deletedDetectionsCount = SatelliteDetection::whereIn('id', $inventory['s1a_detection_ids'])->delete();
            }

            // Delete S1A scenes
            if (!empty($inventory['s1a_scene_ids'])) {
                $deletedScenesCount = SatelliteScene::whereIn('id', $inventory['s1a_scene_ids'])->delete();
            }

            // Record execution audit log
            AuditLog::create([
                'user_id' => auth()->id() ?? null,
                'action' => 'cleanup',
                'entity_type' => 'Sentinel-1A',
                'entity_id' => null,
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => 'CLI / Artisan sentinel:cleanup-s1a',
                'details' => [
                    'scenes_deleted' => $deletedScenesCount,
                    'detections_deleted' => $deletedDetectionsCount,
                    'investigations_decoupled' => $decoupledInvestigationsCount,
                    'backup_directory' => $backupDir,
                    'executed_at' => now()->toIso8601String(),
                ],
                'created_at' => now(),
            ]);
        });

        // 3. Physical File Deletion (Executed only after DB transaction commits)
        $deletedFilesCount = 0;
        $deletedDirsCount = 0;

        foreach ($inventory['files_to_delete'] as $fileInfo) {
            $filePath = $fileInfo['path'];
            if (file_exists($filePath)) {
                if (@unlink($filePath)) {
                    $deletedFilesCount++;
                }
            }
        }

        // Clean up empty directories
        foreach ($inventory['dirs_to_delete'] as $dirPath) {
            if (is_dir($dirPath)) {
                $files = array_diff(scandir($dirPath), ['.', '..']);
                if (empty($files)) {
                    @rmdir($dirPath);
                    $deletedDirsCount++;
                }
            }
        }

        // 4. Cache Pruning
        $this->pruneCacheFiles($inventory['s1a_scene_code_identifiers']);

        foreach ($inventory['s1a_scene_code_identifiers'] as $code) {
            Cache::forget('sar_scene_' . $code);
        }
        Cache::forget('sar_catalog_summary');
        Cache::forget('map_sar_scenes');
        Cache::forget('map_sar_candidates');

        return [
            'status' => 'completed',
            'dry_run' => false,
            'backup_directory' => $backupDir,
            'deleted_scenes_count' => $deletedScenesCount,
            'deleted_detections_count' => $deletedDetectionsCount,
            'decoupled_investigations_count' => $decoupledInvestigationsCount,
            'deleted_files_count' => $deletedFilesCount,
            'deleted_dirs_count' => $deletedDirsCount,
            'protected_scenes_count' => $inventory['protected_scenes_count'],
            'protected_detections_count' => $inventory['protected_detections_count'],
            'message' => 'Pembersihan data dan aset Sentinel-1A berhasil diselesaikan secara aman.',
        ];
    }

    /**
     * Prune S1A records from JSON cache files.
     */
    protected function pruneCacheFiles(array $s1aSceneCodes): void
    {
        $cacheFiles = [
            storage_path('app/sar_candidates_cache.json'),
            storage_path('app/candidates_for_evidence.json'),
        ];

        foreach ($cacheFiles as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $content = json_decode(file_get_contents($filePath), true);
            if (!is_array($content)) {
                continue;
            }

            $pruned = [];
            foreach ($content as $item) {
                $itemStr = strtolower(json_encode($item));
                $isS1a = str_contains($itemStr, 's1a_') || str_contains($itemStr, 's1a-');
                if (!$isS1a) {
                    foreach ($s1aSceneCodes as $code) {
                        if (str_contains($itemStr, strtolower($code))) {
                            $isS1a = true;
                            break;
                        }
                    }
                }

                if (!$isS1a) {
                    $pruned[] = $item;
                }
            }

            file_put_contents($filePath, json_encode($pruned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
