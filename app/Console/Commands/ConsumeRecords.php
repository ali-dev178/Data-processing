<?php

namespace App\Console\Commands;

use App\Services\RecordProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ConsumeRecords — Kafka consumer (Requirement 1).
 *
 * Processes each message immediately as it arrives.
 * No batching, no blocking, no waiting.
 */
class ConsumeRecords extends Command
{
    protected $signature = 'records:consume';

    protected $description = 'Consume data records from Kafka';

    private bool $running = true;

    public function handle(RecordProcessingService $service): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        $conf = new \RdKafka\Conf();
        $conf->set('group.id', config('processing.kafka.consumer_group'));
        $conf->set('bootstrap.servers', config('processing.kafka.bootstrap_servers'));
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'false');

        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([config('processing.kafka.input_topic')]);

        $this->info("Consuming from: " . config('processing.kafka.input_topic'));

        $processed = 0;

        while ($this->running) {
            $msg = $consumer->consume(100);

            if ($msg->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $msg->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                continue;
            }

            if ($msg->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                Log::error('Kafka error: ' . $msg->errstr());
                continue;
            }

            $data = json_decode($msg->payload, true);
            if (!$data) {
                $consumer->commit();
                continue;
            }

            try {
                $result = $service->processRecord($data);
                if ($result['status'] === 'processed') {
                    $processed++;
                }
                $consumer->commit();
            } catch (\Throwable $e) {
                Log::error('Failed to process record', [
                    'offset' => $msg->offset,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $consumer->close();
        $this->info("Stopped. Total: $processed");

        return Command::SUCCESS;
    }
}
