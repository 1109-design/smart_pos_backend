<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\BusinessProvisioner;
use App\Services\QrCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim();

        $businesses = Tenant::withCount('devices')
            ->when($search, fn ($q) => $q
                ->where('business_name', 'like', "%{$search}%")
                ->orWhere('owner_email', 'like', "%{$search}%")
            )
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Businesses/Index', [
            'businesses' => $businesses,
            'filters' => ['search' => $search->toString()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Businesses/Form', [
            'tiers' => Tier::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, BusinessProvisioner $provisioner): RedirectResponse
    {
        $validTiers = Tier::pluck('key')->implode(',');

        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'owner_email' => 'required|email|max:255',
            'tier' => "required|in:{$validTiers}",
            'subscription_valid_until' => 'nullable|date|required_unless:tier,starter',
            'country' => 'nullable|string|size:2',
            'currency_code' => 'nullable|string|max:10',
            'admin_name' => 'required|string|max:255',
            'admin_pin' => 'required|digits:4',
        ]);

        $tenant = $provisioner->provision($data);

        if ($data['tier'] !== 'starter') {
            SubscriptionHistory::create([
                'tenant_id' => $tenant->id,
                'tier' => $data['tier'],
                'event_type' => 'INITIAL_PURCHASE',
                'subscription_valid_until' => $tenant->subscription_valid_until,
                'metadata' => ['manual_override' => true, 'source' => 'business_creation'],
            ]);
        }

        return redirect()->route('businesses.show', $tenant->id)
            ->with('success', 'Business created.')
            ->with('setup_credentials', [
                'admin_name' => $data['admin_name'],
                'admin_email' => $data['owner_email'],
                'admin_pin' => $data['admin_pin'],
                'pairing_code' => $tenant->pairing_code,
            ]);
    }

    public function show(string $id): Response
    {
        $business = Tenant::findOrFail($id);

        return Inertia::render('Businesses/Show', [
            'business' => $business,
            'devicesCount' => $business->devices()->count(),
            'setup_credentials' => session('setup_credentials'),
            'pairQrCode' => QrCodeGenerator::pngDataUri(route('pair.show', $business->id)),
        ]);
    }

    public function edit(string $id): Response
    {
        return Inertia::render('Businesses/Form', [
            'business' => Tenant::findOrFail($id),
            'tiers' => Tier::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validTiers = Tier::pluck('key')->implode(',');

        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'owner_email' => 'required|email|max:255',
            'tier' => "required|in:{$validTiers}",
            'country' => 'nullable|string|size:2',
            'currency_code' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ]);

        $tenant->update($data);

        return redirect()->route('businesses.show', $tenant->id)
            ->with('success', 'Business updated.');
    }

    public function destroy(string $id): RedirectResponse
    {
        Tenant::findOrFail($id)->delete();

        return redirect()->route('businesses.index')
            ->with('success', 'Business deleted.');
    }
}
