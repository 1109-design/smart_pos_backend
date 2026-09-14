<?php

namespace Database\Seeders;

use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultApprovalRulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Not meant to run globally without a business ID
    }

    /**
     * Seed default approval rules for a specific business.
     */
    public static function seedForBusiness(string $businessId): void
    {
        // 1. purchase_order
        $poSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'purchase_order',
            'name' => 'Purchase Order Approvals',
            'description' => 'PO over threshold requires approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $poSet->id,
            'level' => 1,
            'condition_type' => 'amount_gt',
            'condition_value' => 0,
            'required_role' => 'branch_manager',
            'sla_hours' => 4,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $poSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 10000,
            'required_role' => 'finance_manager',
            'sla_hours' => 8,
        ]);

        // 2. void_transaction
        $voidSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'void_transaction',
            'name' => 'Void Transaction Approvals',
            'description' => 'Always requires supervisor',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $voidSet->id,
            'level' => 1,
            'condition_type' => 'always',
            'required_role' => 'sales_supervisor',
            'sla_hours' => 1,
        ]);

        // 3. refund
        $refundSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'refund',
            'name' => 'Refund Approvals',
            'description' => 'Over $500 requires supervisor',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $refundSet->id,
            'level' => 1,
            'condition_type' => 'amount_gt',
            'condition_value' => 500,
            'required_role' => 'sales_supervisor',
            'sla_hours' => 1,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $refundSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 2000,
            'required_role' => 'branch_manager',
            'sla_hours' => 4,
        ]);

        // 4. discount_override
        $discountSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'discount_override',
            'name' => 'Discount Override Approvals',
            'description' => 'Discount over threshold requires supervisor',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $discountSet->id,
            'level' => 1,
            'condition_type' => 'percentage_gt',
            'condition_value' => 10,
            'required_role' => 'sales_supervisor',
            // Using 1 hour since sla_hours is integer
            'sla_hours' => 1,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $discountSet->id,
            'level' => 2,
            'condition_type' => 'percentage_gt',
            'condition_value' => 20,
            'required_role' => 'branch_manager',
            'sla_hours' => 2,
        ]);

        // 5. stock_adjustment
        $adjustmentSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'stock_adjustment',
            'name' => 'Stock Adjustment Approvals',
            'description' => 'Large adjustments need approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $adjustmentSet->id,
            'level' => 1,
            'condition_type' => 'quantity_gt',
            'condition_value' => 100,
            'required_role' => 'warehouse_supervisor',
            'sla_hours' => 4,
        ]);

        // 6. stock_write_off
        $writeOffSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'stock_write_off',
            'name' => 'Stock Write-off Approvals',
            'description' => 'Write-offs need approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $writeOffSet->id,
            'level' => 1,
            'condition_type' => 'amount_gt',
            'condition_value' => 500,
            'required_role' => 'stock_controller',
            'sla_hours' => 4,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $writeOffSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 2000,
            'required_role' => 'branch_manager',
            'sla_hours' => 8,
        ]);

        // 7. journal_entry
        $journalSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'journal_entry',
            'name' => 'Journal Entry Approvals',
            'description' => 'Journals need approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $journalSet->id,
            'level' => 1,
            'condition_type' => 'amount_gt',
            'condition_value' => 5000,
            'required_role' => 'accountant',
            'sla_hours' => 8,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $journalSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 50000,
            'required_role' => 'finance_manager',
            'sla_hours' => 24,
        ]);

        // 8. payment
        $paymentSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'payment',
            'name' => 'Payment Approvals',
            'description' => 'Large payments need approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $paymentSet->id,
            'level' => 1,
            'condition_type' => 'amount_gt',
            'condition_value' => 5000,
            'required_role' => 'finance_officer',
            'sla_hours' => 4,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $paymentSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 20000,
            'required_role' => 'finance_manager',
            'sla_hours' => 8,
        ]);

        // 9. exchange_rate
        $exchangeRateSet = ApprovalRuleSet::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'exchange_rate',
            'name' => 'Exchange Rate Approvals',
            'description' => 'Rate changes need approval',
            'is_enabled' => true,
        ]);

        ApprovalRule::create([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $exchangeRateSet->id,
            'level' => 1,
            'condition_type' => 'always',
            'required_role' => 'finance_manager',
            'sla_hours' => 2,
        ]);
    }
}
