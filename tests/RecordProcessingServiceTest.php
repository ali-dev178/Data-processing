<?php

namespace Tests;

use App\Models\DestinationSummary;
use App\Services\RecordProcessingService;
use App\Jobs\EmitAlertJob;
use App\Jobs\EmitNotificationJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RecordProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecordProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RecordProcessingService();
        Queue::fake();
    }

    private function makeRecord(array $overrides = []): array
    {
        return array_merge([
            'recordId'      => 'rec-001',
            'time'          => '2024-06-15 10:30:00',
            'sourceId'      => 'source-1',
            'destinationId' => 'dest-A',
            'type'          => 'positive',
            'value'         => '100.50',
            'unit'          => 'EUR',
            'reference'     => 'ref-1',
        ], $overrides);
    }

    public function test_processes_new_record(): void
    {
        $result = $this->service->processRecord($this->makeRecord());

        $this->assertEquals('processed', $result['status']);
        $this->assertDatabaseHas('records', ['record_id' => 'rec-001']);
    }

    public function test_skips_duplicate_record(): void
    {
        $r = $this->makeRecord();

        $this->assertEquals('processed', $this->service->processRecord($r)['status']);
        $this->assertEquals('duplicate', $this->service->processRecord($r)['status']);
        $this->assertDatabaseCount('records', 1);
    }

    public function test_processes_different_records(): void
    {
        $this->service->processRecord($this->makeRecord(['recordId' => 'r1']));
        $this->service->processRecord($this->makeRecord(['recordId' => 'r2']));

        $this->assertDatabaseCount('records', 2);
    }

    public function test_dispatches_notification_for_new_record(): void
    {
        $this->service->processRecord($this->makeRecord());

        Queue::assertPushed(EmitNotificationJob::class, 1);
    }

    public function test_no_jobs_for_duplicate(): void
    {
        $r = $this->makeRecord();
        $this->service->processRecord($r);

        Queue::fake();

        $this->service->processRecord($r);

        Queue::assertNotPushed(EmitNotificationJob::class);
        Queue::assertNotPushed(EmitAlertJob::class);
    }

    public function test_creates_summary_on_first_record(): void
    {
        $this->service->processRecord($this->makeRecord());

        $this->assertDatabaseHas('destination_summaries', [
            'destination_id' => 'dest-A',
            'reference'      => 'ref-1',
            'record_count'   => 1,
        ]);
    }

    public function test_increments_summary_on_subsequent_records(): void
    {
        $this->service->processRecord($this->makeRecord(['recordId' => 'r1', 'value' => '100']));
        $this->service->processRecord($this->makeRecord(['recordId' => 'r2', 'value' => '200']));

        $s = DestinationSummary::where('destination_id', 'dest-A')->where('reference', 'ref-1')->first();

        $this->assertEquals(2, $s->record_count);
        $this->assertEquals(300, (float) $s->total_value);
    }

    public function test_no_counter_increment_for_duplicate(): void
    {
        $r = $this->makeRecord(['value' => '100']);
        $this->service->processRecord($r);
        $this->service->processRecord($r);

        $this->assertDatabaseHas('destination_summaries', [
            'destination_id' => 'dest-A',
            'reference'      => 'ref-1',
            'record_count'   => 1,
        ]);
    }

    public function test_separate_summaries_per_dest_ref(): void
    {
        $this->service->processRecord($this->makeRecord(['recordId' => 'r1', 'destinationId' => 'A', 'reference' => 'X']));
        $this->service->processRecord($this->makeRecord(['recordId' => 'r2', 'destinationId' => 'A', 'reference' => 'Y']));
        $this->service->processRecord($this->makeRecord(['recordId' => 'r3', 'destinationId' => 'B', 'reference' => 'X']));

        $this->assertDatabaseCount('destination_summaries', 3);
    }

    public function test_dispatches_alert_when_value_exceeds_threshold(): void
    {
        config(['processing.alert_threshold' => '500.00']);

        $this->service->processRecord($this->makeRecord(['value' => '750.00']));

        Queue::assertPushed(EmitAlertJob::class);
    }

    public function test_no_alert_when_value_below_threshold(): void
    {
        config(['processing.alert_threshold' => '500.00']);

        $this->service->processRecord($this->makeRecord(['value' => '100.00']));

        Queue::assertNotPushed(EmitAlertJob::class);
    }
}
