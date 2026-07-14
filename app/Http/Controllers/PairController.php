<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

class PairController extends Controller
{
    public function show(string $tenant): Response
    {
        $business = Tenant::findOrFail($tenant);

        $deepLink = 'smartpos://activate?'.http_build_query([
            'business_code' => $business->pairing_code,
            'email' => $business->owner_email,
        ]);

        return Inertia::render('Pair/Show', [
            'businessName' => $business->business_name,
            'isActive' => $business->is_active,
            'pairingCode' => $business->pairing_code,
            'ownerEmail' => $business->owner_email,
            'deepLink' => $deepLink,
            'downloadUrl' => route('download.show'),
        ]);
    }
}
