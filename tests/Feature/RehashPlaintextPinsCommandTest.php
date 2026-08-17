<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RehashPlaintextPinsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tenantId, string $pinHash): User
    {
        Tenant::firstOrCreate(
            ['id' => $tenantId],
            ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']
        );

        return User::factory()->create([
            'business_id' => $tenantId,
            'pin_hash' => $pinHash,
        ]);
    }

    public function test_rehashes_plaintext_four_digit_pins(): void
    {
        $user = $this->makeUser('tenant-rehash-1', '1234');

        $this->artisan('app:rehash-plaintext-pins')->assertSuccessful();

        $stored = $user->fresh()->pin_hash;
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check('1234', $stored));
    }

    public function test_leaves_already_hashed_pins_untouched(): void
    {
        $existingHash = Hash::make('5678');
        $user = $this->makeUser('tenant-rehash-2', $existingHash);

        $this->artisan('app:rehash-plaintext-pins')->assertSuccessful();

        $this->assertSame($existingHash, $user->fresh()->pin_hash);
    }

    public function test_leaves_ambiguous_non_pin_values_untouched_and_reports_them(): void
    {
        // Not a 4-digit PIN and not a bcrypt hash — e.g. a legacy Dart
        // hashCode string. The original PIN can't be recovered from this,
        // so it must be reported, never guessed at.
        $user = $this->makeUser('tenant-rehash-3', 'a1b2c3d4e5');

        $this->artisan('app:rehash-plaintext-pins')
            ->expectsOutputToContain('1 user(s) have an unhashed pin_hash')
            ->assertSuccessful();

        $this->assertSame('a1b2c3d4e5', $user->fresh()->pin_hash);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $user = $this->makeUser('tenant-rehash-4', '9999');

        $this->artisan('app:rehash-plaintext-pins --dry-run')
            ->expectsOutputToContain('[dry run] Rehashed 1 plaintext PIN(s).')
            ->assertSuccessful();

        $this->assertSame('9999', $user->fresh()->pin_hash);
    }
}
