<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Http\UploadedFile;

/**
 * Per-tenant white-label branding (logo, primary color, letterhead, footer),
 * shared by every place it can be set from — BackOffice Settings
 * (App\Http\Controllers\BackOffice\SettingsController) and a device's own
 * business profile screen (App\Http\Controllers\Api\BusinessBrandingController).
 * The image fields are delivered to devices exclusively via
 * Business::publishBrandingSyncRecord()'s narrow 'business_branding' sync
 * record — never via the generic 'businesses' sync case, which would wipe
 * whichever of these fields a payload omits. See [[smartpos-project-map]].
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

    /** The client's existing letterhead artwork, printed at the top of A4 documents in place of the generated header — see DocumentType. */
    public function uploadLetterhead(Business $business, UploadedFile $file): Business
    {
        $path = $file->store("letterheads/{$business->id}", 'public');

        $business->update(['letterhead_path' => $path]);
        $business->publishBrandingSyncRecord();

        return $business;
    }

    public function removeLetterhead(Business $business): Business
    {
        $business->update(['letterhead_path' => null]);
        $business->publishBrandingSyncRecord();

        return $business;
    }

    /** An uploaded footer image (e.g. a pre-designed strip with bank/registration details), printed above any footer_text. */
    public function uploadFooterImage(Business $business, UploadedFile $file): Business
    {
        $path = $file->store("footers/{$business->id}", 'public');

        $business->update(['footer_path' => $path]);
        $business->publishBrandingSyncRecord();

        return $business;
    }

    public function removeFooterImage(Business $business): Business
    {
        $business->update(['footer_path' => null]);
        $business->publishBrandingSyncRecord();

        return $business;
    }

    /** Plain-text footer content (terms, thank-you note, bank details) — a normal business column, not part of the pull-only branding record. */
    public function updateFooterText(Business $business, ?string $footerText): Business
    {
        $business->update(['footer_text' => $footerText]);

        return $business;
    }
}
