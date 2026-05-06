<?php

namespace App\Http\Controllers;

use App\Models\ActivationCode;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
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
            'codes'    => $codes,
        ]);
    }

    public function store(string $business): RedirectResponse
    {
        $tenant = Tenant::findOrFail($business);

        // Ensure the code is unique
        do {
            $code = strtoupper(Str::random(16));
        } while (ActivationCode::where('code', $code)->exists());

        ActivationCode::create([
            'tenant_id'  => $tenant->id,
            'code'       => $code,
            'tier'       => $tenant->tier,
            'expires_at' => $tenant->subscription_valid_until,
            'status'     => 'pending',
            'metadata'   => [
                'generated_by' => 'manual_admin',
            ],
        ]);

        return redirect()
            ->route('businesses.activation-codes.index', $business)
            ->with('success', 'Activation code generated. Share it with the business to unlock their device.');
    }
}
