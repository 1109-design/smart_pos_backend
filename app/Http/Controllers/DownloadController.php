<?php

namespace App\Http\Controllers;

use App\Services\QrCodeGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    private const APK_PATH = 'releases/smartpos-latest.apk';

    public function show(): InertiaResponse
    {
        $downloadUrl = route('download.apk');

        return Inertia::render('Download/Show', [
            'available' => Storage::disk('local')->exists(self::APK_PATH),
            'downloadUrl' => $downloadUrl,
            'qrCode' => QrCodeGenerator::pngDataUri($downloadUrl),
        ]);
    }

    public function apk(): StreamedResponse|Response
    {
        if (! Storage::disk('local')->exists(self::APK_PATH)) {
            abort(404, 'The SmartPOS app isn\'t available for download yet.');
        }

        return Storage::disk('local')->download(self::APK_PATH, 'SmartPOS.apk');
    }
}
