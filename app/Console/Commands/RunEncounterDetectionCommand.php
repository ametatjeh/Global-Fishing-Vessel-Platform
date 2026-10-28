<?php

namespace App\Console\Commands;

use App\Services\EncounterDetectionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunEncounterDetectionCommand extends Command
{
    protected $signature = 'encounters:detect {--days=7 : Number of past days of AIS data to evaluate} {--hours= : Number of past hours of AIS data to evaluate} {--max-distance=500 : Maximum proximity in meters} {--distance= : Alias for max-distance in meters} {--min-duration=30 : Minimum duration in minutes} {--duration= : Alias for min-duration in minutes}';
    protected $description = 'Run native spatio-temporal vessel encounter detection algorithm on AIS tracks';

    public function handle(): int
    {
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;
        $days = (int) $this->option('days');
        $maxDist = (float) ($this->option('distance') ?? $this->option('max-distance'));
        $minDur = (int) ($this->option('duration') ?? $this->option('min-duration'));

        $this->info("=== NATIVE AIS VESSEL ENCOUNTER DETECTION ===");
        if ($hours !== null) {
            $this->line("Window: Past {$hours} hours | Max Distance: {$maxDist}m | Min Duration: {$minDur} mins");
            $startTime = Carbon::now()->subHours($hours);
        } else {
            $this->line("Window: Past {$days} days | Max Distance: {$maxDist}m | Min Duration: {$minDur} mins");
            $startTime = Carbon::now()->subDays($days);
        }
        $endTime = Carbon::now();

        $service = new EncounterDetectionService($maxDist, $minDur);

        $result = $service->detectEncounters($startTime, $endTime);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Window Evaluated', "{$startTime->toDateString()} to {$endTime->toDateString()}"],
                ['New Candidate Encounters Detected', $result['detected']],
                ['Skipped (Deduplicated)', $result['skipped']],
            ]
        );

        if (!empty($result['message'])) {
            $this->comment($result['message']);
        }

        $this->info("✓ Analisis encounter selesai.");
        return self::SUCCESS;
    }
}
