<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillApCreditNoteApprovePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_09_29_130000_backfill_ap_credit_note_approve_permission.php'
        );
        $migration->up();
    }

    private function permissionsOf(string $role): array
    {
        $raw = DB::table('role_permissions')
            ->where('business_id', 'biz-1')->where('role', $role)
            ->value('permissions_json');
        $value = $raw;
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }

        return $value;
    }

    public function test_grants_to_roles_that_can_approve_supplier_invoices(): void
    {
        DB::table('role_permissions')->insert([
            ['business_id' => 'biz-1', 'role' => 'manager', 'permissions_json' => json_encode(['apInvoiceView', 'apInvoiceApprove'])],
            // Double-encoded, as a device-synced row can be.
            ['business_id' => 'biz-1', 'role' => 'finance_manager', 'permissions_json' => json_encode(json_encode(['apInvoiceApprove']))],
            ['business_id' => 'biz-1', 'role' => 'cashier', 'permissions_json' => json_encode(['makeSale'])],
        ]);

        $this->runBackfill();

        $this->assertSame(['apInvoiceView', 'apInvoiceApprove', 'apCreditNoteApprove'], $this->permissionsOf('manager'));
        $this->assertSame(['apInvoiceApprove', 'apCreditNoteApprove'], $this->permissionsOf('finance_manager'));
        $this->assertSame(['makeSale'], $this->permissionsOf('cashier'));
    }

    public function test_is_idempotent(): void
    {
        DB::table('role_permissions')->insert([
            'business_id' => 'biz-1', 'role' => 'manager',
            'permissions_json' => json_encode(['apInvoiceApprove', 'apCreditNoteApprove']),
        ]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(['apInvoiceApprove', 'apCreditNoteApprove'], $this->permissionsOf('manager'));
    }
}
