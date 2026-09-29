<?php

namespace Tests\Feature;

use App\Events\SyncTablesChanged;
use App\Models\AccountRoleMapping;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Business;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the push pipeline auto-resolves conflicts that are provably safe
 * (identical content, equivalent unique-key duplicates) instead of parking
 * them for manual review — the two classes that made up 19 of the 20
 * real-world pending conflicts on the production business.
 */
class AutoConflictResolverTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    /**
     * Like actingDeviceToken() above, but issues the device token for an
     * already-created $user (e.g. one assigned 'business_owner') instead of
     * making its own generic one — for tables gated by an owner-only sync
     * guard. Does not create the Tenant/Business; the caller does that.
     */
    private function actingDeviceTokenForUser(string $tenantId, User $user): string
    {
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_identical_version_push_auto_resolves_keep_server(): void
    {
        $tenantId = 'tenant-auto-identical';
        $token = $this->actingDeviceToken($tenantId);
        $uuid = (string) Str::uuid();

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => ['business_id' => $tenantId, 'name' => 'Widget', 'price' => 10],
            'source_updated_at' => now()->addHour(),
            'synced_at' => now()->addHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'products',
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'Widget', 'price' => 10],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('conflicts'));
        $this->assertCount(1, $response->json('auto_resolved'));

        $conflict = SyncConflict::first();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('accept_server_identical', $conflict->resolution_action);
    }

    public function test_differing_version_push_stays_manual(): void
    {
        $tenantId = 'tenant-auto-differ';
        $token = $this->actingDeviceToken($tenantId);
        $uuid = (string) Str::uuid();

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'stock_take_items',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => ['business_id' => $tenantId, 'counted_qty' => 12],
            'source_updated_at' => now()->addHour(),
            'synced_at' => now()->addHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_take_items',
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'counted_qty' => 9],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame([], $response->json('auto_resolved'));
        $this->assertSame('pending', SyncConflict::first()->status);
    }

    public function test_equivalent_unit_duplicate_auto_resolves(): void
    {
        $tenantId = 'tenant-auto-dupe';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => true],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('conflicts'));
        $this->assertSame([], $response->json('errors'));
        $this->assertCount(1, $response->json('auto_resolved'));

        $conflict = SyncConflict::first();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('accept_server_duplicate', $conflict->resolution_action);
        // No second row created.
        $this->assertSame(1, UnitOfMeasure::where('business_id', $tenantId)->count());
    }

    public function test_differing_unit_duplicate_stays_manual(): void
    {
        $tenantId = 'tenant-auto-dupe-differ';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => false],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('auto_resolved'));
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame('pending', SyncConflict::first()->status);
    }

    /**
     * Same class of live bug as SyncTenantScopedParentDeferralTest, other
     * half: `approval_rule_sets` has a real unique constraint on
     * (business_id, process) but wasn't in DUPLICATE_AUTO_RESOLVE_TABLES, so
     * two devices each auto-provisioning a default rule set for the same
     * process (different UUIDs) before either had synced permanently jammed
     * the manual conflicts queue instead of auto-resolving like
     * units_of_measure already does.
     */
    public function test_equivalent_approval_rule_set_duplicate_auto_resolves(): void
    {
        $tenantId = 'tenant-auto-dupe-rule-set';
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceTokenForUser($tenantId, $owner);

        $winnerId = (string) Str::uuid();
        ApprovalRuleSet::create([
            'id' => $winnerId,
            'business_id' => $tenantId,
            'process' => 'purchase_order',
            'name' => 'Purchase Order Approval',
            'is_enabled' => true,
        ]);

        $loserId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'approval_rule_sets',
                'uuid' => $loserId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'process' => 'purchase_order',
                    'name' => 'Purchase Order Approval',
                    'is_enabled' => true,
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('conflicts'));
        $this->assertSame([], $response->json('errors'));
        $this->assertCount(1, $response->json('auto_resolved'));

        $conflict = SyncConflict::first();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('accept_server_duplicate', $conflict->resolution_action);
        $this->assertSame($winnerId, $conflict->server_payload['id']);
        $this->assertSame(1, ApprovalRuleSet::where('business_id', $tenantId)->count(), 'no second row created');
    }

    /**
     * The other half of the fix: a child pushed (in this same request, or a
     * later one) referencing the discarded loser's id must not end up
     * permanently deferred behind a parent that was deliberately never
     * created — remapDuplicateParentReferences() rewrites its FK to the
     * winner's id before it's processed.
     */
    public function test_a_rule_referencing_the_discarded_duplicate_rule_set_is_remapped_to_the_winner(): void
    {
        $tenantId = 'tenant-auto-dupe-remap';
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceTokenForUser($tenantId, $owner);

        $winnerId = (string) Str::uuid();
        ApprovalRuleSet::create([
            'id' => $winnerId,
            'business_id' => $tenantId,
            'process' => 'purchase_order',
            'name' => 'Purchase Order Approval',
            'is_enabled' => true,
        ]);

        // The device's own local rule set was created offline with its own
        // uuid — pushing it now collides and auto-resolves, discarding this
        // uuid in favor of the winner's.
        $loserId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'approval_rule_sets',
                'uuid' => $loserId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'process' => 'purchase_order',
                    'name' => 'Purchase Order Approval',
                    'is_enabled' => true,
                ],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        // The device's own rule underneath its (now-discarded) local rule
        // set arrives in a LATER push — the ordinary case, since it wasn't
        // even part of the same request above.
        $ruleId = (string) Str::uuid();
        $ruleResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'approval_rules',
                'uuid' => $ruleId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'rule_set_id' => $loserId,
                    'level' => 1,
                    'required_role' => 'manager',
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $ruleResponse->assertOk();
        $this->assertCount(1, $ruleResponse->json('accepted'), 'must not be deferred forever behind a parent that will never exist');
        $this->assertSame($winnerId, ApprovalRule::where('id', $ruleId)->value('rule_set_id'), 'must point at the KEPT winner row, not the discarded loser id');
    }

    /**
     * `tills`: every till-list screen auto-creates "Till 1" for a location
     * with no tills yet, purely from the device's own local (possibly
     * empty) list — two devices onboarding around the same time can each
     * mint one for the same location+register_number.
     */
    public function test_equivalent_till_duplicate_auto_resolves(): void
    {
        $tenantId = 'tenant-auto-dupe-till';
        $token = $this->actingDeviceToken($tenantId);
        $locationId = (string) Str::uuid();

        Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'tills',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'location_id' => $locationId,
                    'name' => 'Till 1',
                    'register_number' => 1,
                    'is_active' => true,
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('errors'));
        $this->assertCount(1, $response->json('auto_resolved'));
        $this->assertSame(1, Till::where('business_id', $tenantId)->count());
    }

    /**
     * `account_role_mappings` was audited as a Bug-B candidate (the Flutter
     * client mints a fresh uuid on a local-lookup miss, same shape as
     * `tills` above) but is deliberately NOT in DUPLICATE_AUTO_RESOLVE_TABLES
     * — this proves why that's correct rather than an oversight: the
     * server's own upsert is keyed on (business_id, role), not id, so a
     * second device's "duplicate" mapping updates the one true row in place
     * (gated by its own owner-only authorization check below) and never
     * raises a raw 1062 for Rule B to even consider.
     */
    public function test_a_second_devices_role_mapping_updates_the_existing_row_rather_than_colliding(): void
    {
        $tenantId = 'tenant-auto-dupe-mapping';
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceTokenForUser($tenantId, $owner);

        AccountRoleMapping::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'role' => 'sales_revenue',
            'gl_account_id' => (string) Str::uuid(),
        ]);

        $secondDevicesId = (string) Str::uuid();
        $newGlAccountId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'account_role_mappings',
                'uuid' => $secondDevicesId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'role' => 'sales_revenue',
                    'gl_account_id' => $newGlAccountId,
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'), 'a same-role push is a normal update, never a conflict or an error');
        $this->assertSame([], $response->json('errors'));
        $this->assertSame([], $response->json('auto_resolved'), 'nothing to auto-resolve: no duplicate row was ever created');
        $this->assertSame(1, AccountRoleMapping::where('business_id', $tenantId)->where('role', 'sales_revenue')->count());
        $this->assertSame($newGlAccountId, AccountRoleMapping::where('business_id', $tenantId)->where('role', 'sales_revenue')->value('gl_account_id'));
    }

    public function test_accepted_push_dispatches_tables_changed_event(): void
    {
        $tenantId = 'tenant-realtime-dispatch';
        $token = $this->actingDeviceToken($tenantId);

        Event::fake([SyncTablesChanged::class]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'crate', 'is_active' => true],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        Event::assertDispatched(SyncTablesChanged::class, function ($event) use ($tenantId) {
            return $event->businessId === $tenantId && $event->tables === ['units_of_measure'];
        });
    }

    public function test_rejected_only_push_dispatches_no_event(): void
    {
        $tenantId = 'tenant-realtime-rejected';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        Event::fake([SyncTablesChanged::class]);

        // Differing duplicate → manual error, nothing accepted.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => false],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        Event::assertNotDispatched(SyncTablesChanged::class);
    }
}
