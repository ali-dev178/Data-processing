<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AggregationService
{
    public function query(array $params): array
    {
        [$where, $bindings] = $this->buildFilters($params);

        $summaries = DB::select("
            SELECT
                destination_id,
                COUNT(*) AS record_count,
                SUM(value) AS total_value
            FROM records
            {$where}
            GROUP BY destination_id
            ORDER BY destination_id
        ", $bindings);

        $records = DB::select("
            SELECT
                record_id, time, source_id, destination_id,
                type, value, unit, reference
            FROM records
            {$where}
            ORDER BY destination_id, time
        ", $bindings);

        $recordsByDestination = [];

        foreach ($records as $record) {
            $recordsByDestination[$record->destination_id][] = $record;
        }

        $groups = [];

        foreach ($summaries as $summary) {
            $groups[] = [
                'destination_id' => $summary->destination_id,
                'record_count'   => (int) $summary->record_count,
                'total_value'    => (string) $summary->total_value,
                'records'        => $recordsByDestination[$summary->destination_id] ?? [],
            ];
        }

        return ['groups' => $groups];
    }

    private function buildFilters(array $params): array
    {
        $conditions = [];
        $bindings = [];

        if (!empty($params['start_time'])) {
            $conditions[] = 'time >= ?';
            $bindings[] = $params['start_time'];
        }

        if (!empty($params['end_time'])) {
            $conditions[] = 'time <= ?';
            $bindings[] = $params['end_time'];
        }

        if (!empty($params['type'])) {
            $conditions[] = 'type = ?';
            $bindings[] = $params['type'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $bindings];
    }
}
