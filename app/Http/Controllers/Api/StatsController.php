<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderStatsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ТЗ п.88: GET /api/v1/stats — общая сводка (см. OrderStatsCalculator),
 * то же, что видит ADMIN под кнопкой "Статистика" в боте (StatsFlow).
 */
class StatsController extends Controller
{
    public function __invoke(Request $request, OrderStatsCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $stats = $calculator->calculate(
            Carbon::parse($data['from'])->startOfDay(),
            Carbon::parse($data['to'])->endOfDay(),
        );

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            ...$stats,
        ]);
    }
}
