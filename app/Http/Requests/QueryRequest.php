<?php

namespace App\Http\Requests;

use App\Enums\RecordType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_time' => ['nullable', 'date'],
            'end_time'   => ['nullable', 'date', 'after_or_equal:start_time'],
            'type'       => ['nullable', Rule::enum(RecordType::class)],
        ];
    }
}
