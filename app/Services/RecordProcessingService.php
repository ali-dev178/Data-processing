<?php

namespace App\Services;

use App\Models\Record;
use App\Jobs\EmitAlertJob;
use App\Jobs\EmitNotificationJob;
use Illuminate\Support\Facades\DB;

class RecordProcessingService
{
    public function processRecord(array $data): array
    {
        $now = now();
        $result = DB::transaction(function () use ($data, $now) {

            $inserted = Record::insertOrIgnore([
                'record_id'      => $data['recordId'],
                'time'           => $data['time'],
                'source_id'      => $data['sourceId'],
                'destination_id' => $data['destinationId'],
                'type'           => $data['type'],
                'value'          => $data['value'],
                'unit'           => $data['unit'],
                'reference'      => $data['reference'],
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            if ($inserted === 0) {
                return ['status' => 'duplicate', 'inserted' => false];
            }

            $previousSummary = $this->upsertAndGetPrevious(
                $data['destinationId'],
                $data['reference'],
                $data['value'],
                $now
            );

            return [
                'status'           => 'processed',
                'inserted'         => true,
                'previous_summary' => $previousSummary,
            ];
        });

        if (!$result['inserted']) {
            return ['status' => 'duplicate', 'record_id' => $data['recordId']];
        }

        dispatch(new EmitNotificationJob($data, $result['previous_summary']));

        $threshold = config('processing.alert_threshold', '1000.00');
        if (bccomp($data['value'], $threshold, 4) > 0) {
            $exceededBy = bcsub($data['value'], $threshold, 4);
            dispatch(new EmitAlertJob($data, $threshold, $exceededBy));
        }
        return ['status' => 'processed', 'record_id' => $data['recordId']];
    }

    private function upsertAndGetPrevious(
        string $destinationId,
        string $reference,
        string $value,
        $now
    ): array {
        $sql = "
            INSERT INTO destination_summaries (
                destination_id,
                reference,
                record_count,
                total_value,
                created_at,
                updated_at
            )
            VALUES (?, ?, 1, ?, ?, ?)
            ON CONFLICT (destination_id, reference)
            DO UPDATE SET
                record_count = destination_summaries.record_count + 1,
                total_value  = destination_summaries.total_value + EXCLUDED.total_value,
                updated_at   = EXCLUDED.updated_at
            RETURNING record_count, total_value
        ";

        $row = DB::selectOne($sql, [
            $destinationId,
            $reference,
            $value,
            $now,
            $now
        ]);

        $currentRecordCount = (int) $row->record_count;
        $currentTotalValue  = (string) $row->total_value;

        return [
            'destination_id'        => $destinationId,
            'reference'             => $reference,
            'previous_record_count' => $currentRecordCount - 1,
            'previous_total_value'  => bcsub($currentTotalValue, $value, 4),
        ];
    }
}
