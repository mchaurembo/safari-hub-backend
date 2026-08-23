<?php

use App\Http\Controllers\Api\V1\BusinessCatalogController;
use App\Http\Controllers\Api\V1\BusinessContextController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessMembershipController;
use App\Http\Controllers\Api\V1\BusinessNavigationController;
use App\Http\Controllers\Api\V1\BusinessOrderController;
use App\Http\Controllers\Api\V1\BusinessProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me/memberships', [BusinessContextController::class, 'memberships']);
    Route::get('/me/context', [BusinessContextController::class, 'show']);
    Route::post('/me/context', [BusinessContextController::class, 'store']);
    Route::delete('/me/context', [BusinessContextController::class, 'destroy']);

    Route::get('/business-categories', [BusinessCatalogController::class, 'categories']);
    Route::get('/business-types', [BusinessCatalogController::class, 'types']);
    Route::get('/positions', [BusinessCatalogController::class, 'positions']);
    Route::get('/membership-roles', [BusinessCatalogController::class, 'membershipRoles']);

    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);

    Route::middleware('business.context')->group(function () {
        Route::get('/businesses/{business}', [BusinessController::class, 'show']);
        Route::get('/businesses/{business}/navigation', [BusinessNavigationController::class, 'show']);

        Route::get('/businesses/{business}/members', [BusinessMembershipController::class, 'index']);
        Route::post('/businesses/{business}/members', [BusinessMembershipController::class, 'store']);
        Route::put('/businesses/{business}/members/{membership}', [BusinessMembershipController::class, 'update']);

        Route::get('/businesses/{business}/product-categories', [BusinessProductController::class, 'indexCategories']);
        Route::post('/businesses/{business}/product-categories', [BusinessProductController::class, 'storeCategory']);
        Route::get('/businesses/{business}/products', [BusinessProductController::class, 'index']);
        Route::post('/businesses/{business}/products', [BusinessProductController::class, 'store']);
        Route::put('/businesses/{business}/products/{product}', [BusinessProductController::class, 'update']);
        Route::delete('/businesses/{business}/products/{product}', [BusinessProductController::class, 'destroy']);

        Route::get('/businesses/{business}/orders', [BusinessOrderController::class, 'index']);
        Route::post('/businesses/{business}/orders', [BusinessOrderController::class, 'store']);
        Route::get('/businesses/{business}/orders/{order}', [BusinessOrderController::class, 'show']);
        Route::patch('/businesses/{business}/orders/{order}/status', [BusinessOrderController::class, 'updateStatus']);
    });
});
