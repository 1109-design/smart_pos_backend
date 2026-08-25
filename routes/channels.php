<?php

use App\Services\DeviceResolver;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Business-wide realtime channel — catalog-level changes (e.g. price/discount
 * updates) that aren't scoped to a single location.
 */
Broadcast::channel('business.{businessId}', function ($user, string $businessId) {
    return (string) $user->business_id === $businessId;
});

/**
 * Location-scoped realtime channel — operational changes (stock, shift, till
 * cash movements) relevant to tills operating out of one location. Location
 * membership lives on the Device row (not the User), so it's resolved the
 * same way SyncController resolves the acting device from the bearer token.
 */
Broadcast::channel('business.{businessId}.location.{locationId}', function ($user, string $businessId, string $locationId) {
    if ((string) $user->business_id !== $businessId) {
        return false;
    }

    $device = app(DeviceResolver::class)->fromBearerToken(request()->bearerToken());

    return $device && (string) $device->location_id === $locationId;
});
