<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SourceProduce extends Command
{
    protected $signature = 'source:produce {source} {count=100} {batch?}';

    protected $description = 'Produce records to Kafka for a given source';

    public function handle(): int
    {
        $source = $this->argument('source');
        $count  = (int) $this->argument('count');
        $batch  = $this->argument('batch') ?? uniqid();
        $topic  = config('processing.kafka.input_topic');

        $conf = new \RdKafka\Conf();
        $conf->set('bootstrap.servers', config('processing.kafka.bootstrap_servers'));
        $producer = new \RdKafka\Producer($conf);
        $kafkaTopic = $producer->newTopic($topic);

        $this->info("Producing {$count} {$source} records (batch={$batch})...");

        foreach (range(1, $count) as $i) {
            $kafkaTopic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode([
                'recordId'      => "{$source}-{$batch}-{$i}",
                'time'          => date('c'),
                'sourceId'      => $source,
                'destinationId' => 'dest-' . chr(65 + ($i % 10)),
                'type'          => $i % 3 === 0 ? 'negative' : 'positive',
                'value'         => (string) rand(1, 2000),
                'unit'          => 'EUR',
                'reference'     => 'ref-' . ($i % 50),
            ]));
        }

        $producer->flush(5000);
        $this->info("Done. Produced {$count} {$source} records.");

        return Command::SUCCESS;
    }
}
