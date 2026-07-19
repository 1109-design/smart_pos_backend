<?php

namespace App\Models\Zimra;

use Illuminate\Database\Eloquent\Model;

class ZimraConfiguration extends Model
{
    protected $fillable = [
        'environment',
        'public_api_url',
        'device_api_url',
        'is_enabled',
        'timeout_seconds',
        'max_retries',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public static function getCurrent(): ?self
    {
        return self::where('environment', config('zimra.environment', 'production'))->first();
    }

    public function isTest(): bool
    {
        return $this->environment === 'test';
    }

    /**
     * Seconds allowed to establish the TCP/TLS connection to ZIMRA. Falls back
     * to the request timeout so connections are never capped at Guzzle's 10s
     * default (which surfaces as cURL error 28 preflight failures).
     */
    public function connectTimeoutSeconds(): int
    {
        return (int) ($this->settings['connect_timeout_seconds'] ?? $this->timeout_seconds ?? 30);
    }
}
