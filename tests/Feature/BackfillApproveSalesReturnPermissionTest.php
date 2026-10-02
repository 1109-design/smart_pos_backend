<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillApproveSalesReturnPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_09_29_140200_backfill_approve_sales_return_permission.php'
        );
        $migration->up();
    }

    private function permissionsOf(string $role): array
    {
        $value = DB::table('role_permissions')
            ->where('business_id', 'biz-1')->where('role', $role)
            ->value('permissions_json');
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }

        return $value;
    }

    public function test_grants_to_roles_that_can_process_refunds(): void
    {
        DB::table('role_permissions')->insert([
            ['business_id' => 'biz-1', 'role' => 'manager', 'permissions_json' => json_encode(['makeSale', 'issueRefund'])],
            ['business_id' => 'biz-1', 'role' => 'branch_manager', 'permissions_json' => json_encode(json_encode(['issueRefund']))],
            ['business_id' => 'biz-1', 'role' => 'cashier', 'permissions_json' => json_encode(['makeSale'])],
        ]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(['makeSale', 'issueRefund', 'approveSalesReturn'], $this->permissionsOf('manager'));
        $this->assertSame(['issueRefund', 'approveSalesReturn'], $this->permissionsOf('branch_manager'));
        $this->assertSame(['makeSale'], $this->permissionsOf('cashier'));
    }
}
