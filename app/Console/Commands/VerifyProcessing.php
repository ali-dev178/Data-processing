<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyProcessing extends Command
{
    protected $signature = 'verify:processing {expected} {batch}';

    protected $description = 'Poll for expected records and report throughput';

    public function handle(): int
    {
        $expected = (int) $this->argument('expected');
        $batch = $this->argument('batch');

        $this->info("Batch: {$batch} | Waiting for {$expected} records...\n");

        $lastProcessed = 0;
        $staleRounds = 0;

        while (true) {
            sleep(2);

            $results = DB::table('records')
                ->select('source_id', DB::raw('COUNT(*) as cnt'))
                ->where('record_id', 'like', "%-{$batch}-%")
                ->groupBy('source_id')
                ->orderBy('source_id')
                ->get();

            $processed = $results->sum('cnt');
            $parts = $results->map(fn ($r) => "{$r->source_id}={$r->cnt}")->implode(', ');
            $this->line(sprintf("  [%d/%d] %s", $processed, $expected, $parts));

            if ($processed >= $expected) {
                break;
            }

            if ($processed === $lastProcessed) {
                $staleRounds++;
                if ($staleRounds >= 15) {
                    $this->warn("No progress for 30s — stopping.");
                    break;
                }
            } else {
                $staleRounds = 0;
            }
            $lastProcessed = $processed;
        }

        $this->printReport($batch, $expected);

        return Command::SUCCESS;
    }

    private function printReport(string $batch, int $expected): void
    {
        $batchFilter = "%-{$batch}-%";

        $total = DB::table('records')->where('record_id', 'like', $batchFilter)->count();

        $times = DB::table('records')
            ->select(
                DB::raw('MIN(created_at) as first_at'),
                DB::raw('MAX(created_at) as last_at')
            )
            ->where('record_id', 'like', $batchFilter)
            ->first();

        $firstAt = \Carbon\Carbon::parse($times->first_at);
        $lastAt = \Carbon\Carbon::parse($times->last_at);
        $spanSec = max(abs($lastAt->diffInSeconds($firstAt)), 1);

        $this->newLine();
        $this->info('=== Processing Report ===');
        $this->info("Batch ID:         {$batch}");
        $this->info("Total processed:  {$total}/{$expected}");
        $this->info(sprintf("First processed:  %s", $firstAt->format('H:i:s')));
        $this->info(sprintf("Last processed:   %s", $lastAt->format('H:i:s')));
        $this->info(sprintf("Processing span:  %ds", $spanSec));
        $this->info(sprintf("Throughput:       %.1f records/sec", $total / $spanSec));

        $this->newLine();
        $this->info('Per-source breakdown:');

        $sources = DB::table('records')
            ->select(
                'source_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('MIN(created_at) as first_at'),
                DB::raw('MAX(created_at) as last_at')
            )
            ->where('record_id', 'like', $batchFilter)
            ->groupBy('source_id')
            ->orderBy('source_id')
            ->get();

        $this->table(
            ['Source', 'Count', 'First Record', 'Last Record'],
            $sources->map(fn ($r) => [
                $r->source_id,
                $r->cnt,
                \Carbon\Carbon::parse($r->first_at)->format('H:i:s'),
                \Carbon\Carbon::parse($r->last_at)->format('H:i:s'),
            ])
        );
    }
}
