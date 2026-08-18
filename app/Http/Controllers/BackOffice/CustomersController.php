<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomersController extends Controller
{
    /**
     * Customers and their loyalty ledger are populated entirely by the till
     * via sync — this page is read plus a light edit (credit limit,
     * active/inactive), no new stock/ledger logic of its own.
     */
    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $search = $request->string('search')->trim();

        $customers = Customer::where('business_id', $this->tenantId())
            ->when($search, fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('BackOffice/Customers', [
            'customers' => $customers,
            'filters' => ['search' => $search->toString()],
        ]);
    }

    public function show(string $customer): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $record = Customer::where('business_id', $tenantId)->findOrFail($customer);

        $loyaltyHistory = LoyaltyTransaction::where('customer_id', $record->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        $purchaseHistory = Transaction::where('business_id', $tenantId)
            ->where('customer_id', $record->id)
            ->latest('created_at')
            ->limit(25)
            ->get(['id', 'sale_number', 'total', 'status', 'created_at']);

        return Inertia::render('BackOffice/CustomerShow', [
            'customer' => $record,
            'loyalty_history' => $loyaltyHistory,
            'purchase_history' => $purchaseHistory,
        ]);
    }

    public function update(Request $request, string $customer, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Customer::where('business_id', $this->tenantId())->findOrFail($customer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_tax_exempt' => ['boolean'],
            'group' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = [
            'business_id' => $existing->business_id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'loyalty_points' => $existing->loyalty_points,
            'credit_balance' => $existing->credit_balance,
            'credit_limit' => $data['credit_limit'] ?? $existing->credit_limit,
            'is_tax_exempt' => $data['is_tax_exempt'] ?? $existing->is_tax_exempt,
            'group' => $data['group'] ?? $existing->group,
        ];

        $processor->process('customers', $existing->id, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $existing->business_id,
            'table_name' => 'customers',
            'record_uuid' => $existing->id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        return back()->with('success', 'Customer updated.');
    }

    private function authorizeManager(): void
    {
        abort_if(
            ! in_array(session('backoffice.role'), ['business_owner', 'manager']),
            403,
            'Access denied.'
        );
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
