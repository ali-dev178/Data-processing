<?php

namespace Tests;

use App\Services\RecordProcessingService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RecordApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function ingest(array $overrides = []): array
    {
        return app(RecordProcessingService::class)->processRecord($this->makeRecord($overrides));
    }

    public function test_processes_valid_record(): void
    {
        $result = $this->ingest();
        $this->assertEquals('processed', $result['status']);
        $this->assertEquals('rec-001', $result['record_id']);
    }

    public function test_returns_duplicate_status(): void
    {
        $this->ingest();
        $result = $this->ingest();
        $this->assertEquals('duplicate', $result['status']);
    }

    public function test_groups_by_destination(): void
    {
        $this->ingest(['recordId' => 'r1', 'destinationId' => 'A', 'value' => '100']);
        $this->ingest(['recordId' => 'r2', 'destinationId' => 'A', 'value' => '200']);
        $this->ingest(['recordId' => 'r3', 'destinationId' => 'B', 'value' => '300']);

        $groups = $this->json('GET', '/api/query')->assertOk()->json('groups');

        $this->assertCount(2, $groups);
        $this->assertEquals(2, collect($groups)->firstWhere('destination_id', 'A')['record_count']);
        $this->assertEquals(1, collect($groups)->firstWhere('destination_id', 'B')['record_count']);
    }

    public function test_filters_by_type(): void
    {
        $this->ingest(['recordId' => 'r1', 'type' => 'positive']);
        $this->ingest(['recordId' => 'r2', 'type' => 'negative']);

        $groups = $this->json('GET', '/api/query?type=positive')->json('groups');

        $this->assertCount(1, $groups);
    }

    public function test_filters_by_time_range(): void
    {
        $this->ingest(['recordId' => 'r1', 'time' => '2024-01-15 10:00:00']);
        $this->ingest(['recordId' => 'r2', 'time' => '2024-06-15 10:00:00']);
        $this->ingest(['recordId' => 'r3', 'time' => '2024-12-15 10:00:00']);

        $groups = $this->json('GET', '/api/query?start_time=2024-05-01 00:00:00&end_time=2024-07-01 00:00:00')->json('groups');

        $this->assertCount(1, $groups);
    }

    public function test_returns_empty_when_no_match(): void
    {
        $this->json('GET', '/api/query?type=negative')->assertOk()->assertJson(['groups' => []]);
    }
}
