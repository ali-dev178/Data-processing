<?php

namespace App\Models;

use App\Enums\RecordType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $record_id
 * @property \Carbon\Carbon $time
 * @property string $source_id
 * @property string $destination_id
 * @property RecordType $type
 * @property string $value
 * @property string $unit
 * @property string $reference
 */
class Record extends Model
{
    protected $casts = [
        'type'  => RecordType::class,
        'time'  => 'datetime',
        'value' => 'decimal:4',
    ];
}
