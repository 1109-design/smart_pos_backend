<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\PaymentMethodConfig;
use RuntimeException;

class PaymentAccountResolver
{
    public function __construct(
        private AccountRoleMappingService $mappings
    ) {}

    /**
     * Resolves the canonical GL account for a payment.
     *
     * Precedence:
     * 1. Explicit $glAccountId if provided
     * 2. BankAccount's gl_account_id if $paymentAccountId is provided
     * 3. PaymentMethodConfig's gl_account_id if configured
     * 4. AccountRoleMapping for the canonical payment method
     *
     * Never uses substring matching. Never silently misclassifies or defaults to Cash.
     */
    public function resolve(
        string $businessId,
        string $method,
        ?string $currencyCode = null,
        ?string $paymentAccountId = null,
        ?string $provider = null,
        ?string $glAccountId = null,
    ): GlAccount {
        // 1. Explicit GL Account ID
        if ($glAccountId) {
            $glAccount = GlAccount::where('business_id', $businessId)->find($glAccountId);
            if ($glAccount) {
                return $glAccount;
            }
        }

        // 2. Bank / Payment Account
        if ($paymentAccountId) {
            $bankAccount = BankAccount::where('business_id', $businessId)->find($paymentAccountId);
            if ($bankAccount && $bankAccount->gl_account_id) {
                $glAccount = GlAccount::where('business_id', $businessId)->find($bankAccount->gl_account_id);
                if ($glAccount) {
                    return $glAccount;
                }
            }
        }

        // 3. PaymentMethodConfig lookup
        $configQuery = PaymentMethodConfig::where('business_id', $businessId)
            ->where('method', $method);
        if ($currencyCode) {
            $configQuery->where('currency_code', $currencyCode);
        }
        if ($paymentAccountId) {
            $configQuery->where('payment_account_id', $paymentAccountId);
        }
        if ($provider) {
            $configQuery->where('provider', $provider);
        }
        $config = $configQuery->first();
        if ($config && $config->gl_account_id) {
            $glAccount = GlAccount::where('business_id', $businessId)->find($config->gl_account_id);
            if ($glAccount) {
                return $glAccount;
            }
        }

        // 4. Role Mapping by Canonical Method
        $normalized = str_replace([' ', '-'], '_', strtolower(trim($method)));
        return match ($normalized) {
            'exchange_credit' => GlAccount::where('business_id', $businessId)->where('code', '2045')->first()
                ?? throw new RuntimeException("Exchange clearing GL account (2045) not found for business {$businessId}"),
            'credit' => $this->mappings->resolve($businessId, 'accounts_receivable'),
            'card', 'swipe' => $this->mappings->resolve($businessId, 'default_bank'),
            'bank_transfer', 'bank', 'eft', 'cheque', 'check' => $this->mappings->resolve($businessId, 'default_bank'),
            'mobile_money', 'ecocash', 'onemoney', 'omari', 'innbucks', 'mpesa' => $this->mappings->resolve($businessId, 'default_mobile_money'),
            'cash' => $this->mappings->resolve($businessId, 'default_cash'),
            'other' => $this->mappings->resolve($businessId, 'default_cash'),
            default => throw new RuntimeException("Unrecognized payment method '{$method}' with no configured GL account"),
        };
    }
}
