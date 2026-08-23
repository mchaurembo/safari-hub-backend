<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\BusinessNavigationService;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessNavigationController extends Controller
{
    public function __construct(
        private readonly BusinessNavigationService $navigation,
    ) {}

    public function show(Request $request, Business $business): JsonResponse
    {
        /** @var BusinessContext $context */
        $context = $request->attributes->get('business_context');

        if ((int) $context->business->id !== (int) $business->id) {
            return response()->json(['message' => 'Business context mismatch'], 422);
        }

        $modules = $this->navigation->modules($context);

        $grouped = collect($modules)->groupBy('group')->map(fn ($items, $group) => [
            'group' => $group,
            'label' => match ($group) {
                'business' => 'Business',
                'operations' => 'Operations',
                'commerce' => 'Commerce',
                'finance' => 'Finance',
                default => ucfirst($group),
            },
            'modules' => $items->values(),
        ])->values();

        return response()->json([
            'data' => [
                'business_id' => $business->id,
                'modules' => $modules,
                'groups' => $grouped,
            ],
        ]);
    }
}
