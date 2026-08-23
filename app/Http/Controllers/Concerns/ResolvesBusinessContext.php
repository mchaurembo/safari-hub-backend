<?php

namespace App\Http\Controllers\Concerns;

use App\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ResolvesBusinessContext
{
    protected function activeBusinessId(Request $request): ?int
    {
        $context = $request->attributes->get('business_context');
        if ($context instanceof BusinessContext) {
            return $context->businessId();
        }

        $header = $request->header('X-Business-Id');
        if ($header !== null && $header !== '') {
            return (int) $header;
        }

        return null;
    }

    protected function scopeByBusiness(Builder $query, Request $request): Builder
    {
        $businessId = $this->activeBusinessId($request);
        if ($businessId) {
            $query->where($query->getModel()->getTable().'.business_id', $businessId);
        }

        return $query;
    }
}
