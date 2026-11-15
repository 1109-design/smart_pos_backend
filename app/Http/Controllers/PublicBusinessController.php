<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public page a customer reaches by scanning the QR on a non-fiscalised
 * receipt or invoice. No auth, so it only ever exposes what a business would
 * print on a shop sign: never the TIN, pairing code, owner email or any sale.
 */
class PublicBusinessController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $business = Business::query()->where('public_slug', $slug)->first();
        $tenant = $business ? Tenant::find($business->id) : null;

        if (! $business || ($tenant && ! $tenant->is_active)) {
            return Inertia::render('Business/NotFound')->toResponse($request)->setStatusCode(404);
        }

        $branches = Location::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('type', '!=', 'warehouse')
            ->orderBy('name')
            ->get(['name', 'address', 'phone'])
            ->map(fn (Location $location) => [
                'name' => $location->name,
                'address' => $location->address,
                'phone' => $location->phone,
            ])
            ->values();

        return Inertia::render('Business/Show', [
            'business' => [
                'name' => $business->name,
                'tagline' => $business->tagline,
                'logoUrl' => $business->logoUrl(),
                'primaryColor' => $business->primary_color,
                'address' => $business->address,
                'phone' => $business->phone,
                'email' => $business->email,
                'website' => $business->website,
                'whatsapp' => $business->whatsapp,
                'facebookUrl' => $business->facebook_url,
                'instagramUrl' => $business->instagram_url,
                'about' => $business->about,
                'openingHours' => $business->opening_hours,
                'latitude' => $business->latitude,
                'longitude' => $business->longitude,
                'timezone' => $business->timezone ?: config('app.timezone'),
            ],
            'branches' => $branches->count() > 1 ? $branches : [],
            'pageUrl' => $business->publicPageUrl(),
        ])->toResponse($request);
    }
}
