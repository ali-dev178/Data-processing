<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EmitAlertJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        private readonly array  $record,
        private readonly string $threshold,
        private readonly string $exceededBy,
    ) {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $message = [
            'record'      => $this->record,
            'threshold'   => $this->threshold,
            'exceeded_by' => $this->exceededBy,
            'emitted_at'  => date('c'),
        ];

        // SIMULATION — in production:
        // KafkaProducer::produce('alerts', $this->record['recordId'], $message);
        Log::warning('[ALERT] High value record', $message);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[ALERT] Failed', [
            'record_id' => $this->record['recordId'],
            'error'     => $e->getMessage(),
        ]);
    }
}
