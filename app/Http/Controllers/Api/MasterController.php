<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Master;
use App\Services\Orders\MasterStatsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ТЗ п.88: GET /api/v1/masters, GET /api/v1/masters/{id}/stats.
 */
class MasterController extends Controller
{
    public function index(): JsonResponse
    {
        $masters = Master::query()
            ->with(['applianceTypes', 'brands', 'geoZones'])
            ->get()
            ->map(fn (Master $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'status' => $m->status->value,
                'is_active' => $m->is_active,
                'appliance_types' => $m->applianceTypes->pluck('name'),
                'brands' => $m->brands->pluck('name'),
                'geo_zones' => $m->geoZones->pluck('name'),
            ]);

        return response()->json(['data' => $masters]);
    }

    public function stats(Request $request, Master $master, MasterStatsCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $stats = $calculator->calculate(
            $master,
            Carbon::parse($data['from'])->startOfDay(),
            Carbon::parse($data['to'])->endOfDay(),
        );

        return response()->json([
            'master_id' => $master->id,
            'from' => $data['from'],
            'to' => $data['to'],
            ...$stats,
        ]);
    }
}
