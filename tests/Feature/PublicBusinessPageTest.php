<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Location;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The page opened by the QR printed on every non-fiscalised sale document.
 */
class PublicBusinessPageTest extends TestCase
{
    use RefreshDatabase;

    private function deviceToken(string $tenantId): string
    {
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-device@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_new_business_gets_a_slug_and_tills_are_told_the_page_url(): void
    {
        config(['app.public_url' => 'https://pos.example.com/']);
        $business = Business::create(['id' => 'tenant-qr-1', 'name' => "Jonny's Hardware & Paint"]);

        $this->assertSame('jonnys-hardware-paint', $business->public_slug);

        $record = SyncRecord::query()
            ->where('business_id', 'tenant-qr-1')
            ->where('table_name', 'business_branding')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('https://pos.example.com/b/jonnys-hardware-paint', $record->payload['public_page_url']);
    }

    public function test_slug_collisions_get_a_numeric_suffix(): void
    {
        Business::create(['id' => 'tenant-qr-a', 'name' => 'Corner Shop']);
        $second = Business::create(['id' => 'tenant-qr-b', 'name' => 'Corner Shop']);
        $third = Business::create(['id' => 'tenant-qr-c', 'name' => 'Corner Shop']);

        $this->assertSame('corner-shop-2', $second->public_slug);
        $this->assertSame('corner-shop-3', $third->public_slug);
    }

    public function test_the_slug_survives_a_rename_so_printed_qrs_keep_working(): void
    {
        $business = Business::create(['id' => 'tenant-qr-2', 'name' => 'Old Name']);
        $brandingRecords = SyncRecord::where('table_name', 'business_branding')->count();

        $business->update(['name' => 'Brand New Name']);

        $this->assertSame('old-name', $business->fresh()->public_slug);
        $this->assertSame($brandingRecords, SyncRecord::where('table_name', 'business_branding')->count());
    }

    public function test_the_page_shows_public_details_and_never_private_ones(): void
    {
        Tenant::create(['id' => 'tenant-qr-3', 'business_name' => 'Sunrise Bakery', 'owner_email' => 'owner@private.test', 'pairing_code' => 'SECRET1']);
        Business::create([
            'id' => 'tenant-qr-3',
            'name' => 'Sunrise Bakery',
            'tagline' => 'Baked fresh every morning',
            'tin' => '2000123456',
            'phone' => '+263 77 123 4567',
            'whatsapp' => '+263771234567',
            'about' => 'Cakes made to order.',
            'opening_hours' => ['mon' => [['open' => '08:00', 'close' => '17:00']], 'sun' => []],
            'latitude' => -17.8292,
            'longitude' => 31.0522,
        ]);
        Location::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-qr-3', 'name' => 'Avondale', 'address' => '1 King George Rd']);
        Location::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-qr-3', 'name' => 'CBD', 'address' => '5 First St']);
        Location::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-qr-3', 'name' => 'Main Store', 'type' => 'warehouse']);

        $response = $this->get('/b/sunrise-bakery');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Business/Show')
            ->where('business.name', 'Sunrise Bakery')
            ->where('business.tagline', 'Baked fresh every morning')
            ->where('business.whatsapp', '+263771234567')
            ->where('business.openingHours.mon.0.open', '08:00')
            ->has('branches', 2)
            ->missing('business.tin')
            ->missing('business.pairingCode')
            ->missing('business.ownerEmail'));

        $html = $response->getContent();
        $this->assertStringNotContainsString('2000123456', $html);
        $this->assertStringNotContainsString('SECRET1', $html);
        $this->assertStringNotContainsString('owner@private.test', $html);
    }

    public function test_unknown_slug_is_a_friendly_404(): void
    {
        $this->get('/b/no-such-shop')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Business/NotFound'));
    }

    public function test_inactive_business_is_hidden(): void
    {
        Tenant::create(['id' => 'tenant-qr-4', 'business_name' => 'Closed Co', 'owner_email' => 'c@example.com', 'is_active' => false]);
        Business::create(['id' => 'tenant-qr-4', 'name' => 'Closed Co']);

        $this->get('/b/closed-co')->assertNotFound();
    }

    public function test_device_push_stores_the_page_fields_and_cannot_change_the_slug(): void
    {
        $token = $this->deviceToken('tenant-qr-5');
        Business::create(['id' => 'tenant-qr-5', 'name' => 'Pushy Pharmacy', 'about' => 'Kept']);

        $push = fn (array $payload) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => 'tenant-qr-5',
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $push([
            'name' => 'Pushy Pharmacy',
            'tagline' => 'Your health first',
            'timezone' => 'Africa/Harare',
            'opening_hours' => json_encode(['mon' => [['open' => '09:00', 'close' => '18:00']]]),
            'latitude' => -17.8,
            'longitude' => 31.0,
            'whatsapp' => '+263770000000',
            'public_slug' => 'hijacked',
        ]);

        $business = Business::find('tenant-qr-5');
        $this->assertSame('pushy-pharmacy', $business->public_slug);
        $this->assertSame('Your health first', $business->tagline);
        $this->assertSame('Africa/Harare', $business->timezone);
        $this->assertSame('09:00', $business->opening_hours['mon'][0]['open']);
        $this->assertSame('+263770000000', $business->whatsapp);
        // Not in the payload (e.g. an older till), so preserved.
        $this->assertSame('Kept', $business->about);
    }

    public function test_the_backfill_gives_every_existing_business_a_slug(): void
    {
        // Rows from before the migration: inserted raw, so no model hooks ran.
        foreach (range(1, 5) as $n) {
            DB::table('businesses')->insert(['id' => "tenant-old-$n", 'name' => 'Old Shop', 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require database_path('migrations/2026_11_15_110000_add_public_business_page_fields.php');
        $migration->up();

        $slugs = Business::where('id', 'like', 'tenant-old-%')->orderBy('id')->pluck('public_slug')->all();
        $this->assertSame(['old-shop', 'old-shop-2', 'old-shop-3', 'old-shop-4', 'old-shop-5'], $slugs);
        $this->assertSame(5, SyncRecord::where('table_name', 'business_branding')->where('business_id', 'like', 'tenant-old-%')->count());
    }
}
