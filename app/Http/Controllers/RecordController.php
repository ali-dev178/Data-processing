<?php

namespace App\Http\Controllers;

use App\Http\Requests\QueryRequest;
use App\Services\AggregationService;
use Illuminate\Http\JsonResponse;

class RecordController extends Controller
{
    public function __construct(
        private readonly AggregationService $aggregationService,
    ) {}

    public function query(QueryRequest $request): JsonResponse
    {
        return response()->json(
            $this->aggregationService->query($request->validated())
        );
    }
}
