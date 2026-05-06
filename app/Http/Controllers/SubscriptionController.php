<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\Tier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function show(string $business): Response
    {
        $tenant = Tenant::findOrFail($business);

        return Inertia::render('Subscriptions/Show', [
            'business' => $tenant,
            'tiers'    => Tier::orderBy('sort_order')->get()->keyBy('key'),
            'history'  => $tenant->subscriptionHistory()->limit(50)->get(),
        ]);
    }

    public function update(Request $request, string $business): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        $validTiers = Tier::pluck('key')->implode(',');

        $data = $request->validate([
            'tier'                    => "required|in:{$validTiers}",
            'subscription_valid_until' => 'nullable|date',
        ]);

        $previousTier = $tenant->tier;
        $tenant->update($data);

        // Log manual override to subscription history
        SubscriptionHistory::create([
            'tenant_id' => $tenant->id,
            'tier' => $data['tier'],
            'event_type' => 'MANUAL_OVERRIDE',
            'previous_tier' => $previousTier !== $data['tier'] ? $previousTier : null,
            'subscription_valid_until' => $data['subscription_valid_until'] ? now()->parse($data['subscription_valid_until']) : null,
            'metadata' => [
                'manual_override' => true,
            ],
        ]);

        // Generate an activation code so the device can unlock immediately
        if ($data['tier'] !== 'starter') {
            do {
                $code = strtoupper(Str::random(16));
            } while (ActivationCode::where('code', $code)->exists());

            ActivationCode::create([
                'tenant_id'  => $tenant->id,
                'code'       => $code,
                'tier'       => $data['tier'],
                'expires_at' => $data['subscription_valid_until'] ? now()->parse($data['subscription_valid_until']) : null,
                'status'     => 'pending',
                'metadata'   => [
                    'generated_by' => 'subscription_override',
                ],
            ]);
        }

        return redirect()->route('businesses.activation-codes.index', $business)
            ->with('success', 'Subscription updated. A new activation code has been generated — share it with the business to unlock their device.');
    }
}
