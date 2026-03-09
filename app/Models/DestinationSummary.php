<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $destination_id
 * @property string $reference
 * @property int $record_count
 * @property string $total_value
 */
class DestinationSummary extends Model
{
    protected $casts = [
        'record_count' => 'integer',
        'total_value'  => 'decimal:4',
    ];
}
