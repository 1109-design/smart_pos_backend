<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Where CI reports each newly published app installer (or, with [upload],
 * hands the installer itself over to be hosted here), and where devices
 * read the newest one. Devices normally get it for free on /sync/pull
 * (see AppRelease::latestByPlatform); [index] is for an explicit check.
 */
class AppReleaseController extends Controller
{
    /** Only installers from the public releases repo are ever relayed. */
    private const DOWNLOAD_PREFIX = 'https://github.com/h200137j/smart_pos_releases/releases/download/';

    /** Hosted installers live on the public disk under releases/<platform>/. */
    private const UPLOAD_DIR = 'releases';

    private const EXTENSIONS = ['windows' => 'exe', 'linux' => 'deb', 'android' => 'apk'];

    private const VERSION_RULE = ['required', 'string', 'max:40', 'regex:/^v?\d+(\.\d+){1,3}$/'];

    /**
     * Called by CI (smart_pos_ci's ci.yml) after a platform's installer is
     * published. Authenticated by a shared secret in X-Release-Token, not a
     * device token; with APP_RELEASE_TOKEN unset everything is refused.
     * An older version than the one on record is ignored, so a re-run of an
     * old tag can't roll devices back.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'platform' => 'required|in:'.implode(',', AppRelease::PLATFORMS),
            'version' => self::VERSION_RULE,
            'asset_name' => 'required|string|max:200',
            'download_url' => ['required', 'string', 'max:500', 'starts_with:'.self::DOWNLOAD_PREFIX],
        ]);

        if ($ignored = $this->olderThanCurrent($data['platform'], $data['version'])) {
            return $ignored;
        }

        AppRelease::updateOrCreate(['platform' => $data['platform']], $data);
        AppRelease::forgetCache();

        return response()->json(['updated' => true]);
    }

    /**
     * Called by the release build machine (no CI): takes the installer
     * itself, hosts it on the public disk, and relays it like [store].
     * Same shared secret, same no-rollback rule. Only the newest installer
     * per platform is kept — the platform's previous upload is deleted, so
     * a device still downloading it just picks up this one on its next pull.
     */
    public function upload(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'platform' => 'required|in:'.implode(',', AppRelease::PLATFORMS),
            'version' => self::VERSION_RULE,
            // KB; the APK is the big one (~250 MB).
            'file' => 'required|file|max:512000',
        ]);
        $platform = $data['platform'];
        $file = $request->file('file');

        // The client saves the download under asset_name, so keep it a plain file name.
        $name = $file->getClientOriginalName();
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,199}$/', $name)
            || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== self::EXTENSIONS[$platform]) {
            return response()->json([
                'message' => "A {$platform} release must be a .".self::EXTENSIONS[$platform].' file with a plain name.',
            ], 422);
        }

        if ($ignored = $this->olderThanCurrent($platform, $data['version'])) {
            return $ignored;
        }

        $disk = Storage::disk('public');
        $dir = self::UPLOAD_DIR.'/'.$platform;
        $disk->putFileAs($dir, $file, $name);

        AppRelease::updateOrCreate(['platform' => $platform], [
            'version' => $data['version'],
            'asset_name' => $name,
            'download_url' => $disk->url($dir.'/'.rawurlencode($name)),
        ]);
        AppRelease::forgetCache();

        foreach ($disk->files($dir) as $old) {
            if ($old !== $dir.'/'.$name) {
                $disk->delete($old);
            }
        }

        return response()->json(['updated' => true, 'download_url' => AppRelease::find($platform)->download_url]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['app_releases' => (object) AppRelease::latestByPlatform()]);
    }

    private function authorized(Request $request): bool
    {
        // With APP_RELEASE_TOKEN unset everything is refused.
        $expected = (string) config('services.app_releases.token');

        return $expected !== '' && hash_equals($expected, (string) $request->header('X-Release-Token', ''));
    }

    /**
     * An older version than the one on record is ignored, so re-releasing
     * an old tag can't roll devices back. Null when [version] may go ahead.
     */
    private function olderThanCurrent(string $platform, string $version): ?JsonResponse
    {
        $current = AppRelease::find($platform);
        if (! $current || ! version_compare(ltrim($version, 'v'), ltrim($current->version, 'v'), '<')) {
            return null;
        }

        return response()->json([
            'updated' => false,
            'message' => "Ignored: {$current->version} is newer.",
        ]);
    }
}
