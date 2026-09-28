<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Supplier;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A one-time entry for what a supplier was already owed before this
 * business's books started — see OpeningBalanceService. Suppliers carry no
 * local balance field at all (unlike Customer::credit_balance), so this is
 * the only place an AP opening balance can be set; it exists purely as a
 * formal-books GL posting, which is why it requires accounting to be live.
 */
class SupplierOpeningBalancesController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly OpeningBalanceService $openingBalances,
    ) {}

    public function store(Request $request, string $supplier): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $record = Supplier::where('business_id', $tenantId)->findOrFail($supplier);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'as_of_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->openingBalances->recordSupplierOpeningBalance(
                $tenantId,
                $record->id,
                (float) $data['amount'],
                $data['as_of_date'],
                $data['notes'] ?? null,
                $this->userId(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Opening balance recorded.');
    }

    /**
     * Bulk variant for onboarding many suppliers at once: one CSV row per
     * supplier, one approval gate for the whole batch (accepting the file at
     * all IS the manager's sign-off — same reasoning the Flutter-side
     * customer bulk import uses). Rows are matched by supplier_id, so the
     * expected workflow is "download the suppliers list, fill in a column,
     * re-upload" rather than free-typing ids — matched by the sibling
     * Suppliers index export, not built here since suppliers are few enough
     * per business that name-matching is safe.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return back()->withErrors(['file' => 'The file is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $col = array_flip($header);

        if (! isset($col['supplier_id'], $col['opening_balance'])) {
            fclose($handle);

            return back()->withErrors(['file' => '"supplier_id" and "opening_balance" columns are required. Use the downloaded template.']);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($handle, $col, $tenantId, &$imported, &$skipped, &$errors) {
            while (($row = fgetcsv($handle)) !== false) {
                $supplierId = trim((string) ($row[$col['supplier_id']] ?? ''));
                $amountRaw = trim((string) ($row[$col['opening_balance']] ?? ''));
                if ($supplierId === '' || $amountRaw === '') {
                    continue; // blank row or unfilled amount — nothing to import
                }

                $amount = is_numeric($amountRaw) ? (float) $amountRaw : null;
                $notes = isset($col['notes']) ? trim((string) ($row[$col['notes']] ?? '')) : null;
                $asOfDate = isset($col['as_of_date']) ? trim((string) ($row[$col['as_of_date']] ?? '')) : '';
                $asOfDate = $asOfDate !== '' ? $asOfDate : now()->toDateString();

                $supplier = Supplier::where('business_id', $tenantId)->where('id', $supplierId)->first();

                if (! $supplier || $amount === null || $amount <= 0) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->openingBalances->recordSupplierOpeningBalance(
                        $tenantId,
                        $supplier->id,
                        $amount,
                        $asOfDate,
                        $notes ?: null,
                        $this->userId(),
                    );
                    $imported++;
                } catch (RuntimeException $e) {
                    $skipped++;
                    $errors[] = "{$supplier->name}: {$e->getMessage()}";
                }
            }
        });

        fclose($handle);

        $message = "{$imported} opening balance(s) imported".($skipped > 0 ? ", {$skipped} skipped" : '').'.';
        if (! empty($errors)) {
            $message .= ' First issue: '.$errors[0];
        }

        return back()->with('success', $message);
    }

    /**
     * A pre-filled template (like the Flutter-side stock/product imports):
     * every active supplier already listed, id column locked by convention,
     * opening_balance left blank for the manager to fill in.
     */
    public function template(): StreamedResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $suppliers = Supplier::where('business_id', $tenantId)->where('is_active', true)->orderBy('name')->get();

        $filename = 'supplier_opening_balances_'.now()->format('Ymd_Hi').'.csv';

        return response()->streamDownload(function () use ($suppliers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['supplier_id', 'supplier_name', 'opening_balance', 'as_of_date', 'notes']);
            foreach ($suppliers as $supplier) {
                fputcsv($out, [$supplier->id, $supplier->name, '', now()->toDateString(), '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_SUPPLIERS),
            403,
            'Access denied.'
        );
    }
}
