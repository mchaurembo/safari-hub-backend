<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer marketplace for passenger transport operators (CHAPA businesses).
 */
class CustomerTransportController extends Controller
{
    /** Browse active transport providers with upcoming scheduled trips. */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $typeIds = BusinessType::query()
            ->whereIn('code', ['passenger_transport', 'logistics'])
            ->where('is_active', true)
            ->pluck('id');

        $query = Business::query()
            ->with(['type:id,code,name', 'transportOwner:id,company_name,status'])
            ->where('status', Business::STATUS_ACTIVE)
            ->whereIn('business_type_id', $typeIds)
            ->where(function ($q) {
                $q->whereHas('transportOwner', fn ($t) => $t->where('status', 'approved'))
                    ->orWhereHas('trips', fn ($t) => $t->where('status', 'scheduled'));
            })
            ->withCount([
                'trips as scheduled_trips_count' => fn ($q) => $q
                    ->where('status', 'scheduled')
                    ->where('departure_time', '>=', now()),
            ])
            ->orderBy('trade_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('trade_name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhereHas('transportOwner', fn ($t) => $t->where('company_name', 'like', "%{$search}%"));
            });
        }

        $businesses = $query->limit(50)->get();

        $data = $businesses->map(fn (Business $b) => [
            'id' => $b->id,
            'name' => $b->displayName(),
            'trade_name' => $b->trade_name,
            'type' => $b->type?->code,
            'type_name' => $b->type?->name,
            'operator_name' => $b->transportOwner?->company_name,
            'logo_url' => $b->logo_url,
            'scheduled_trips_count' => (int) ($b->scheduled_trips_count ?? 0),
        ]);

        return response()->json(['data' => $data]);
    }
}
