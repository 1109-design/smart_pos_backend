<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\Tenant;
use App\Models\Tier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ActivationCodeController extends Controller
{
    public function index(string $business): Response
    {
        $tenant = Tenant::findOrFail($business);

        $codes = ActivationCode::where('tenant_id', $tenant->id)
            ->with('device')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('ActivationCodes/Index', [
            'business' => $tenant,
            'codes' => $codes,
        ]);
    }

    public function store(Request $request, string $business): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        $validTiers = Tier::pluck('key')->implode(',');

        $data = $request->validate([
            'tier' => "nullable|in:{$validTiers}",
            'days' => 'nullable|integer|min:1|max:3660',
        ]);

        // A code grants a paid tier for a fixed number of days. Default to the
        // business's current tier (or Pro if it is on the free Starter plan)
        // for 30 days when the admin just clicks "generate".
        $tier = $data['tier'] ?? ($tenant->tier === 'starter' ? 'pro' : $tenant->tier);
        $days = $data['days'] ?? 30;

        // Grant from the later of "now" or the existing paid-through date, so a
        // redeemed code always lands in the future — extending an active
        // subscription rather than clobbering it, and reviving an expired one.
        // This is the fix for codes that previously snapshotted a past/null
        // expiry and redeemed straight back into a locked state.
        $base = $tenant->subscription_valid_until?->isFuture()
            ? $tenant->subscription_valid_until
            : now();
        $expiresAt = $base->copy()->addDays($days);

        // Ensure the code is unique
        do {
            $code = strtoupper(Str::random(16));
        } while (ActivationCode::where('code', $code)->exists());

        ActivationCode::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'tier' => $tier,
            'expires_at' => $expiresAt,
            'status' => 'pending',
            'metadata' => [
                'generated_by' => 'manual_admin',
                'granted_days' => $days,
            ],
        ]);

        return redirect()
            ->route('businesses.activation-codes.index', $business)
            ->with('success', "Activation code generated for {$tier} ({$days} days). Share it with the business to unlock their device.");
    }
}
