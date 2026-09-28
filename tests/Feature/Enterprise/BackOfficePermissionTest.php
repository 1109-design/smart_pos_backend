<?php

namespace Tests\Feature\Enterprise;

use App\Support\BackOfficePermission;
use Tests\TestCase;

class BackOfficePermissionTest extends TestCase
{
    public function test_all_returns_complete_permission_list()
    {
        $all = BackOfficePermission::all();

        $this->assertIsArray($all);
        $this->assertGreaterThanOrEqual(80, count($all));

        // Assert all strings
        foreach ($all as $permission) {
            $this->assertIsString($permission);
        }

        // Assert unique
        $this->assertEquals(count($all), count(array_unique($all)));
    }

    public function test_label_returns_human_readable_string()
    {
        $this->assertEquals('View sales', BackOfficePermission::label('sales.view'));
        $this->assertEquals('unknown.perm', BackOfficePermission::label('unknown.perm'));
    }

    public function test_defaults_for_cashier_has_expected_permissions()
    {
        $cashierPerms = BackOfficePermission::defaultsFor('cashier');

        $this->assertContains('sales.view', $cashierPerms);
        $this->assertContains('sales.create', $cashierPerms);
        $this->assertContains('stock.view', $cashierPerms);

        $this->assertNotContains('settings.users', $cashierPerms);
        $this->assertNotContains('approvals.decide', $cashierPerms);
    }

    public function test_defaults_for_branch_manager_is_broad()
    {
        $managerPerms = BackOfficePermission::defaultsFor('branch_manager');

        $this->assertContains('sales.view', $managerPerms);
        $this->assertContains('procurement.po.approve', $managerPerms);

        $this->assertNotContains('approvals.configure', $managerPerms);
        $this->assertNotContains('settings.roles', $managerPerms);
    }

    public function test_defaults_for_auditor_is_read_only()
    {
        $auditorPerms = BackOfficePermission::defaultsFor('auditor');

        $this->assertContains('reports.audit', $auditorPerms);
        $this->assertContains('sales.view', $auditorPerms);
        $this->assertContains('stock.view', $auditorPerms);

        $this->assertNotContains('sales.void', $auditorPerms);
        $this->assertNotContains('procurement.po.approve', $auditorPerms);

        foreach ($auditorPerms as $perm) {
            if ($perm !== 'reports.audit') {
                $this->assertStringEndsWith('.view', $perm);
            }
        }
    }

    public function test_defaults_for_business_owner_has_all()
    {
        $ownerPerms = BackOfficePermission::defaultsFor('business_owner');
        $allPerms = BackOfficePermission::all();

        $this->assertEquals(sort($allPerms), sort($ownerPerms));
    }

    public function test_defaults_for_unknown_role_returns_empty()
    {
        $unknownPerms = BackOfficePermission::defaultsFor('unknown_custom_role');
        $this->assertEquals([], $unknownPerms);
    }
}
