<?php

namespace App\Console\Commands;

use App\Services\Providers\AisStreamAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AisStreamListenCommand extends Command
{
    protected $signature = 'ais:listen 
                            {--daemon : Run continuously as a background stream daemon with auto-reconnect}
                            {--limit=0 : Number of messages to consume (0 for continuous)}
                            {--timeout=15 : Socket read timeout in seconds}
                            {--max-reconnects=10 : Max consecutive reconnect attempts before halting}';

    protected $description = 'Ingest real-time AIS telemetry from aisstream.io WebSocket stream';

    protected bool $keepRunning = true;

    public function handle(AisStreamAdapter $adapter): int
    {
        $daemon = (bool) $this->option('daemon');
        $limit = (int) $this->option('limit');
        $timeout = (int) $this->option('timeout');
        $maxReconnects = (int) $this->option('max-reconnects');

        if (!$adapter->isConfigured()) {
            $this->error('AIS Stream API key is not configured in .env (AIS_STREAM_API_KEY).');
            return self::FAILURE;
        }

        $this->info('=== GFVIP AIS LIVE STREAM CONSUMER ===');
        $this->line('Host: wss://stream.aisstream.io/v0/stream');
        $this->line('Mode: ' . ($daemon ? 'Continuous Daemon' : ($limit > 0 ? "Batch (Limit: {$limit})" : 'Single Session')));

        // Register signal handlers if pcntl is supported
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                $this->warn("\n[SIGINT] Menerima sinyal terminasi. Menghentikan worker AIS secara anggun...");
                $this->keepRunning = false;
            });
            pcntl_signal(SIGTERM, function () {
                $this->warn("\n[SIGTERM] Menerima sinyal terminasi. Menghentikan worker AIS...");
                $this->keepRunning = false;
            });
        }

        $attempt = 0;
        $totalIngested = 0;

        while ($this->keepRunning) {
            $currentLimit = ($limit > 0) ? max(1, $limit - $totalIngested) : 0;
            
            $this->line(sprintf('[%s] Membuka koneksi WebSocket stream...', now()->toDateTimeString()));
            $result = $adapter->consumeStream($currentLimit, $timeout);

            $status = $result['status'] ?? 'unknown';
            $fetched = $result['fetched'] ?? 0;
            $inserted = $result['inserted'] ?? 0;
            $totalIngested += $inserted;

            $this->info(sprintf(
                '[%s] Sesi selesai - Status: %s | Diterima: %d | Disimpan: %d | Akumulasi: %d',
                now()->toDateTimeString(),
                $status,
                $fetched,
                $inserted,
                $totalIngested
            ));

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $err) {
                    $this->error("Error: {$err}");
                }
            }

            // If limit specified and reached, exit cleanly
            if ($limit > 0 && $totalIngested >= $limit) {
                $this->info("Batas limit {$limit} pesan tercapai. Worker berhenti dengan sukses.");
                break;
            }

            // If not daemon mode and finished a batch, exit cleanly
            if (!$daemon) {
                break;
            }

            // If busy (another worker holds lock), wait with jitter and retry
            if ($status === 'busy') {
                $waitSec = 15 + random_int(1, 5);
                $this->warn("Worker lain sedang aktif memegang lock. Menunggu {$waitSec} detik sebelum mencoba kembali...");
                sleep($waitSec);
                continue;
            }

            // If error, apply exponential backoff with random jitter
            if ($status === 'error' || $status === 'failed' || $status === 'partially_failed' && $fetched === 0) {
                $attempt++;
                if ($attempt >= $maxReconnects) {
                    $this->error("Telah mencapai batas maksimum reconnect ({$maxReconnects}). Menghentikan daemon.");
                    return self::FAILURE;
                }
                $baseBackoff = min(60, (int) pow(2, min($attempt, 6)));
                $jitter = random_int(1, 5);
                $backoffSeconds = $baseBackoff + $jitter;
                $this->warn("Koneksi terputus. Mencoba reconnect ke-{$attempt} dalam {$backoffSeconds} detik (backoff {$baseBackoff}s + jitter {$jitter}s)...");
                sleep($backoffSeconds);
            } else {
                // Successful session, reset attempt counter
                $attempt = 0;
                sleep(2);
            }
        }

        return self::SUCCESS;
    }
}
