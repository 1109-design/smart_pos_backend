<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\JournalHeader;
use App\Services\Accounting\JournalService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Phase 11f — manual journal entries, for adjustments nothing else in the
 * system posts on its own: opening balances, corrections, accruals. Every
 * entry is built and posted as one atomic action (draft, add lines, post,
 * all inside JournalService), never left sitting as an editable draft —
 * there's no workflow here that benefits from a saved-for-later state, and
 * skipping it avoids a whole class of "who left this half-built journal
 * open" confusion.
 *
 * Deliberately no party_type/party_id or foreign-currency fields on this
 * form — tagging a manual entry to a customer/supplier or posting it in a
 * foreign currency are both real needs, but nothing today exercises them,
 * and PartyLedgerService/the sale-posting path already cover the actual
 * debtor/creditor and multi-currency flows. Add those fields when a real
 * use case shows up rather than guessing at the shape now.
 *
 * Control accounts (Accounts Receivable/Payable, Inventory — anything with
 * a control_type) are excluded from the account picker and rejected in
 * store(). Their balances only mean something when every posting to them
 * carries a party_type/party_id (PartyLedgerService's aging sums depend on
 * it) or a stock movement — an untagged manual line would silently break
 * that reconciliation while leaving the trial balance looking fine.
 */
class JournalEntriesController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function index(Request $request): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();

        $journals = JournalHeader::where('business_id', $tenantId)
            ->whereIn('source_type', ['manual', 'reversal'])
            ->with(['lines.account:id,code,name'])
            ->latest('trans_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/JournalEntries', [
            'journals' => $journals,
            'accounts' => $this->accountOptions($tenantId),
        ]);
    }

    public function store(Request $request, JournalService $journals): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'trans_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.gl_account_id' => ['required', 'string', 'exists:gl_accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $postableAccountIds = collect($this->accountOptions($this->tenantId()))->pluck('id');
        foreach ($data['lines'] as $line) {
            if (! $postableAccountIds->contains($line['gl_account_id'])) {
                return back()->withErrors(['journal' => 'One of the selected accounts cannot be posted to directly from a manual entry.'])->withInput();
            }
        }

        try {
            $header = $journals->createDraft($this->tenantId(), $data['trans_date'], 'manual', null, $data['description']);

            foreach ($data['lines'] as $line) {
                $journals->addLine($header, [
                    'gl_account_id' => $line['gl_account_id'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                ]);
            }

            $journals->post($header, $this->userId());
        } catch (RuntimeException $e) {
            return back()->withErrors(['journal' => $e->getMessage()])->withInput();
        }

        return redirect()->route('office.journal-entries.index')->with('success', "{$header->journal_number} posted.");
    }

    public function reverse(Request $request, string $journalEntry, JournalService $journals): RedirectResponse
    {
        $this->authorize();

        $header = JournalHeader::where('business_id', $this->tenantId())
            ->where('source_type', 'manual')
            ->findOrFail($journalEntry);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $journals->reverse($header, $this->userId(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['journal' => $e->getMessage()]);
        }

        return back()->with('success', "{$header->journal_number} reversed.");
    }

    /**
     * Accounts excluded here by code rather than control_type: gl_accounts.control_type
     * is a DB enum limited to receivable/payable/inventory, so Fixed Assets
     * (1500) and Accumulated Depreciation (1510) — which need the exact same
     * party-tagged-only protection, see AssetPostingService — can't be
     * flagged the same way without widening a shared enum for one feature.
     *
     * @return array<int, array{id: string, code: string, name: string, category: string|null}>
     */
    private function accountOptions(string $tenantId): array
    {
        return AccountCategory::where('business_id', $tenantId)
            ->with(['accounts' => fn ($q) => $q->where('status', 'active')
                ->where('allow_direct_posting', true)
                ->whereNull('control_type')
                ->whereNotIn('code', ['1500', '1510'])
                ->orderBy('code')])
            ->orderBy('reporting_order')
            ->get()
            ->flatMap(fn ($category) => $category->accounts->map(fn ($account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'category' => $category->name,
            ]))
            ->values()
            ->all();
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_JOURNAL_ENTRIES),
            403,
            'Access denied.'
        );
    }
}
