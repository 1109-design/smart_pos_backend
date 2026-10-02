<?php

namespace App\Services;

use App\Models\SyncRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Till passwords (user_credentials) and the owner's password policy
 * (password_policies). Every server-side write goes out as a broadcast
 * SyncRecord so each till picks it up on its next pull, the same way a
 * till's own change reaches the others.
 */
class TillCredentials
{
    /** Same defaults as the till's PasswordPolicy.defaults. */
    public const DEFAULT_POLICY = [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_digit' => true,
        'require_symbol' => false,
        'expiry_days' => 90,
        'history_count' => 5,
        'max_attempts' => 5,
        'lockout_minutes' => 15,
        'idle_lock_minutes' => 15,
    ];

    /** @return array<string, int|bool> */
    public function policy(string $businessId): array
    {
        $row = DB::table('password_policies')->where('business_id', $businessId)->first();
        if (! $row) {
            return self::DEFAULT_POLICY;
        }

        $policy = [];
        foreach (self::DEFAULT_POLICY as $key => $default) {
            $policy[$key] = is_bool($default) ? (bool) $row->{$key} : (int) $row->{$key};
        }

        return $policy;
    }

    /**
     * What's wrong with [$password] under the business's policy — empty
     * when it's acceptable. Messages match the till's wording.
     *
     * @return list<string>
     */
    public function violations(string $businessId, string $password, ?string $userName = null): array
    {
        $policy = $this->policy($businessId);
        $problems = [];

        if (mb_strlen($password) < $policy['min_length']) {
            $problems[] = "At least {$policy['min_length']} characters";
        }
        if ($policy['require_uppercase'] && ! preg_match('/\p{Lu}/u', $password)) {
            $problems[] = 'An uppercase letter';
        }
        if ($policy['require_lowercase'] && ! preg_match('/\p{Ll}/u', $password)) {
            $problems[] = 'A lowercase letter';
        }
        if ($policy['require_digit'] && ! preg_match('/\d/', $password)) {
            $problems[] = 'A number';
        }
        if ($policy['require_symbol'] && ! preg_match('/[^\p{L}\d\s]/u', $password)) {
            $problems[] = 'A symbol';
        }
        if ($userName !== null) {
            foreach (preg_split('/\s+/', mb_strtolower(trim($userName))) ?: [] as $part) {
                if (mb_strlen($part) >= 3 && str_contains(mb_strtolower($password), $part)) {
                    $problems[] = 'Must not contain your name';
                    break;
                }
            }
        }

        return $problems;
    }

    /** Whether [$password] is [$user]'s current till password. */
    public function check(User $user, string $password): bool
    {
        $hash = DB::table('user_credentials')->where('user_id', $user->id)->value('password_hash');

        return $hash !== null && $hash !== '' && Hash::check($password, $hash);
    }

    public function hasPassword(User $user): bool
    {
        return DB::table('user_credentials')
            ->where('user_id', $user->id)
            ->whereNotNull('password_hash')
            ->exists();
    }

    /**
     * Sets [$user]'s till password. [$mustChange] makes them choose their
     * own at next sign-in — used whenever someone else sets it (owner or
     * Back Office reset, new business from the admin panel).
     */
    public function setPassword(User $user, string $password, bool $mustChange): void
    {
        $existing = DB::table('user_credentials')->where('user_id', $user->id)->first();
        $history = json_decode($existing->history_json ?? '[]', true) ?: [];
        if ($existing?->password_hash) {
            array_unshift($history, $existing->password_hash);
        }
        $history = array_slice($history, 0, 24);

        $row = [
            'business_id' => (string) $user->business_id,
            'password_hash' => Hash::make($password),
            'must_change' => $mustChange,
            'password_changed_at' => now(),
            'history_json' => json_encode($history),
            'updated_at' => now(),
        ];
        DB::table('user_credentials')->updateOrInsert(['user_id' => $user->id], $row);

        SyncRecord::create([
            'business_id' => $user->business_id,
            'table_name' => 'user_credentials',
            'record_uuid' => $user->id,
            'operation' => 'upsert',
            'payload' => [
                'user_id' => $user->id,
                'business_id' => (string) $user->business_id,
                'password_hash' => $row['password_hash'],
                'must_change' => $mustChange,
                'password_changed_at' => $row['password_changed_at']->toIso8601String(),
                'history_json' => $row['history_json'],
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
            'device_id' => null,
        ]);
    }

    /**
     * Stores a till's pushed credential row. The till hashes the password
     * itself; anything that isn't a hash is refused rather than stored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyCredentialPayload(string $userId, array $payload): void
    {
        $hash = $payload['password_hash'] ?? null;
        if ($hash !== null && $hash !== '' && ! Hash::isHashed($hash)) {
            throw new \RuntimeException('user_credentials: password_hash must be a hash, never a plain password.');
        }

        DB::table('user_credentials')->updateOrInsert(
            ['user_id' => $userId],
            [
                'business_id' => (string) ($payload['business_id'] ?? ''),
                'password_hash' => $hash ?: null,
                'must_change' => (bool) ($payload['must_change'] ?? false),
                'password_changed_at' => isset($payload['password_changed_at'])
                    ? \Illuminate\Support\Carbon::parse($payload['password_changed_at'])
                    : null,
                'history_json' => is_string($payload['history_json'] ?? null) ? $payload['history_json'] : null,
                'updated_at' => now(),
            ]
        );
    }

    /** @param  array<string, mixed>  $payload */
    public function applyPolicyPayload(string $businessId, array $payload): void
    {
        $row = ['updated_at' => now()];
        foreach (self::DEFAULT_POLICY as $key => $default) {
            $value = $payload[$key] ?? $default;
            $row[$key] = is_bool($default) ? (bool) $value : max(0, (int) $value);
        }
        // Never let a synced policy drop below something sane.
        $row['min_length'] = max(6, min(64, $row['min_length']));
        $row['max_attempts'] = max(1, $row['max_attempts']);
        $row['lockout_minutes'] = max(1, $row['lockout_minutes']);

        DB::table('password_policies')->updateOrInsert(['business_id' => $businessId], $row);
    }
}
