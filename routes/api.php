<?php

use App\Http\Controllers\Api\BackOfficeAccessController;
use App\Http\Controllers\Api\DeviceAuthController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['ok' => true]));
});

// POS device authentication (no tenant context needed yet — tenant resolved by business_code)
Route::prefix('v1')->middleware(['throttle:device-auth'])->group(function () {
    Route::post('auth/register', [DeviceAuthController::class, 'register']);
    Route::post('auth/device', [DeviceAuthController::class, 'login']);
    Route::post('auth/device/activate', [DeviceAuthController::class, 'activate']);
    Route::post('auth/setup', [DeviceAuthController::class, 'setup']);
});

// Sync endpoints — business resolved from authenticated device token
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::post('sync/push', [SyncController::class, 'push']);
    Route::get('sync/pull', [SyncController::class, 'pull']);
    Route::get('sync/status', [SyncController::class, 'status']);
    Route::get('sync/conflicts', [SyncController::class, 'conflicts']);
    Route::post('sync/conflicts/{id}/resolve', [SyncController::class, 'resolveConflict']);

    // Back Office access — portal details + owner/manager password set/reset
    Route::get('backoffice/info', [BackOfficeAccessController::class, 'info']);
    Route::post('backoffice/password', [BackOfficeAccessController::class, 'setPassword']);

    // Subscription entitlement — heartbeat + activation code redemption
    Route::get('subscription/status', [SubscriptionController::class, 'status']);
    Route::post('subscription/redeem', [SubscriptionController::class, 'redeem']);

    // Device self-service rename (shown on the POS screen so cashiers can
    // confirm which till they're on)
    Route::put('device/name', [SubscriptionController::class, 'updateName']);

    // Reports back a location a cashier picked on-device (the fallback
    // prompt shown when no admin assignment exists) so the web portal's
    // Devices page reflects it too, instead of staying silently unaudited.
    Route::put('device/location', [SubscriptionController::class, 'reportSelfPickedLocation']);

    // Locations
    Route::get('locations', [LocationController::class, 'index']);
    Route::post('locations', [LocationController::class, 'store']);
    Route::get('locations/{id}', [LocationController::class, 'show']);
    Route::patch('locations/{id}', [LocationController::class, 'update']);
    Route::get('locations/{id}/stock', [LocationController::class, 'stock']);
    Route::get('products/{productId}/stock-by-location', [LocationController::class, 'productStock']);

    // Stock transfers
    Route::get('transfers', [StockTransferController::class, 'index']);
    Route::post('transfers', [StockTransferController::class, 'store']);
    Route::get('transfers/{id}', [StockTransferController::class, 'show']);
    Route::post('transfers/{id}/approve', [StockTransferController::class, 'approve']);
    Route::post('transfers/{id}/dispatch', [StockTransferController::class, 'dispatch']);
    Route::post('transfers/{id}/receive', [StockTransferController::class, 'receive']);
    Route::post('transfers/{id}/cancel', [StockTransferController::class, 'cancel']);
});

// Webhooks (no auth — verified by payload signature)
Route::prefix('v1/webhooks')->group(function () {
    Route::post('revenuecat', [WebhookController::class, 'revenuecat']);
});
