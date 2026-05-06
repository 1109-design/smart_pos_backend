<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function revenuecat(Request $request): JsonResponse
    {
        $payload = $request->input('event');

        if (! $payload) {
            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        $appUserId = $payload['app_user_id'] ?? null;
        $type = $payload['type'] ?? null;
        $productId = $payload['product_id'] ?? null;
        $expiresAt = isset($payload['expiration_at_ms'])
            ? Carbon::createFromTimestampMs($payload['expiration_at_ms'])
            : null;

        if (! $appUserId) {
            return response()->json(['ok' => true]);
        }

        $tenant = Tenant::find($appUserId);
        if (! $tenant) {
            return response()->json(['ok' => true]);
        }

        $tier = $this->tierFromProductId($productId);
        $previousTier = $tenant->tier;

        match ($type) {
            'INITIAL_PURCHASE', 'RENEWAL', 'PRODUCT_CHANGE' => $tenant->update([
                'tier' => $tier,
                'subscription_valid_until' => $expiresAt,
            ]),
            'EXPIRATION', 'CANCELLATION' => $tenant->update([
                'tier' => 'starter',
                'subscription_valid_until' => null,
            ]),
            default => null,
        };

        // Log subscription history
        $this->logSubscriptionHistory($tenant, $type, $previousTier, $tier, $expiresAt, $payload);

        // Generate activation code for new subscriptions or renewals
        if (in_array($type, ['INITIAL_PURCHASE', 'RENEWAL', 'PRODUCT_CHANGE']) && $tier !== 'starter') {
            $this->generateActivationCode($tenant, $tier, $expiresAt);
        }

        return response()->json(['ok' => true]);
    }

    private function logSubscriptionHistory(Tenant $tenant, string $eventType, string $previousTier, string $newTier, ?Carbon $expiresAt, array $payload): void
    {
        SubscriptionHistory::create([
            'tenant_id' => $tenant->id,
            'tier' => $newTier,
            'event_type' => $eventType,
            'previous_tier' => $previousTier !== $newTier ? $previousTier : null,
            'subscription_valid_until' => $expiresAt,
            'webhook_event_id' => $payload['event_id'] ?? null,
            'metadata' => [
                'product_id' => $payload['product_id'] ?? null,
                'price_in_pennies' => $payload['price_in_pennies'] ?? null,
                'currency' => $payload['currency'] ?? null,
            ],
        ]);
    }

    private function tierFromProductId(?string $productId): string
    {
        if (! $productId) {
            return 'starter';
        }
        if (str_contains($productId, 'ultimate')) {
            return 'ultimate';
        }
        if (str_contains($productId, 'pro')) {
            return 'pro';
        }
        return 'starter';
    }

    private function generateActivationCode(Tenant $tenant, string $tier, ?Carbon $expiresAt): void
    {
        // Generate a unique 16-character code
        $code = strtoupper(Str::random(16));

        ActivationCode::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'tier' => $tier,
            'expires_at' => $expiresAt,
            'status' => 'pending',
            'metadata' => [
                'generated_by' => 'webhook',
            ],
        ]);
    }
}
