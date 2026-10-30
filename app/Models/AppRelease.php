<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Newest published app installer for one platform — see the
 * create_app_releases_table migration and AppReleaseController.
 */
class AppRelease extends Model
{
    public const PLATFORMS = ['windows', 'linux', 'android'];

    private const CACHE_KEY = 'app_releases.latest';

    protected $primaryKey = 'platform';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['platform', 'version', 'asset_name', 'download_url'];

    /**
     * Every platform's newest release, keyed by platform — the shape
     * /sync/pull and GET /app-releases return. Cached briefly: every
     * device's pull reads it, every few seconds.
     *
     * @return array<string, array{version: string, asset_name: string, download_url: string}>
     */
    public static function latestByPlatform(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => static::query()
            ->get()
            ->mapWithKeys(fn (self $r) => [$r->platform => [
                'version' => $r->version,
                'asset_name' => $r->asset_name,
                'download_url' => $r->download_url,
            ]])
            ->all());
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
