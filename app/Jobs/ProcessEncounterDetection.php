<?php

namespace App\Jobs;

use App\Services\EncounterDetectionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEncounterDetection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $hours = 24,
        public float $maxDistanceMeters = 500.0,
        public int $minDurationMinutes = 30
    ) {
    }

    public function handle(): void
    {
        Log::info("Starting queued encounter detection for past {$this->hours} hours...");

        $service = new EncounterDetectionService($this->maxDistanceMeters, $this->minDurationMinutes);
        $startTime = Carbon::now()->subHours($this->hours);
        $endTime = Carbon::now();

        $result = $service->detectEncounters($startTime, $endTime);

        Log::info("Queued encounter detection completed: {$result['detected']} detected, {$result['skipped']} skipped.");
    }
}
