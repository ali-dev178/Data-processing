<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EmitNotificationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        private readonly array $record,
        private readonly array $destinationSummary,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $message = [
            'record'              => $this->record,
            'destination_summary' => $this->destinationSummary,
            'emitted_at'          => date('c'),
        ];

        // SIMULATION — in production:
        // KafkaProducer::produce('notifications', $this->record['destinationId'], $message);
        Log::info('[NOTIFICATION] Message emitted', $message);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[NOTIFICATION] Failed', [
            'record_id' => $this->record['recordId'],
            'error'     => $e->getMessage(),
        ]);
    }
}
