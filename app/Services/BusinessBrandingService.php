<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Http\UploadedFile;

/**
 * Per-tenant white-label branding (logo + primary color), shared by every
 * place it can be set from — BackOffice Settings
 * (App\Http\Controllers\BackOffice\SettingsController) and a device's own
 * business profile screen (App\Http\Controllers\Api\BusinessBrandingController).
 * Both fields are delivered to devices exclusively via
 * Business::publishBrandingSyncRecord()'s narrow 'business_branding' sync
 * record — never via the generic 'businesses' sync case, which would wipe
 * whichever of these two fields a payload omits. See [[smartpos-project-map]].
 */
class BusinessBrandingService
{
    /** Stores the file under a business-scoped path so tenants can never collide/leak into each other's logos. */
    public function uploadLogo(Business $business, UploadedFile $file): Business
    {
        $path = $file->store("logos/{$business->id}", 'public');

        $business->update(['logo_path' => $path]);
        $business->publishBrandingSyncRecord();

        return $business;
    }

    public function updateColor(Business $business, ?string $color): Business
    {
        $business->update(['primary_color' => $color]);
        $business->publishBrandingSyncRecord();

        return $business;
    }
}
