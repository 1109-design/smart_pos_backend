<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where CI reports each newly published app installer, and where devices
 * read the newest one. Devices normally get it for free on /sync/pull
 * (see AppRelease::latestByPlatform); [index] is for an explicit check.
 */
class AppReleaseController extends Controller
{
    /** Only installers from the public releases repo are ever relayed. */
    private const DOWNLOAD_PREFIX = 'https://github.com/h200137j/smart_pos_releases/releases/download/';

    /**
     * Called by CI (smart_pos_ci's ci.yml) after a platform's installer is
     * published. Authenticated by a shared secret in X-Release-Token, not a
     * device token; with APP_RELEASE_TOKEN unset everything is refused.
     * An older version than the one on record is ignored, so a re-run of an
     * old tag can't roll devices back.
     */
    public function store(Request $request): JsonResponse
    {
        $expected = (string) config('services.app_releases.token');
        $given = (string) $request->header('X-Release-Token', '');
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'platform' => 'required|in:'.implode(',', AppRelease::PLATFORMS),
            'version' => ['required', 'string', 'max:40', 'regex:/^v?\d+(\.\d+){1,3}$/'],
            'asset_name' => 'required|string|max:200',
            'download_url' => ['required', 'string', 'max:500', 'starts_with:'.self::DOWNLOAD_PREFIX],
        ]);

        $current = AppRelease::find($data['platform']);
        if ($current && version_compare(
            ltrim($data['version'], 'v'),
            ltrim($current->version, 'v'),
            '<',
        )) {
            return response()->json([
                'updated' => false,
                'message' => "Ignored: {$current->version} is newer.",
            ]);
        }

        AppRelease::updateOrCreate(['platform' => $data['platform']], $data);
        AppRelease::forgetCache();

        return response()->json(['updated' => true]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['app_releases' => (object) AppRelease::latestByPlatform()]);
    }
}
