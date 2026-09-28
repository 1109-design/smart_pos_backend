<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GlAccount;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full chart-of-accounts management — create/rename/reassign/deactivate
 * accounts and categories. Closes the gap `ChartOfAccountsSeeder`'s own
 * doc comment always assumed was covered ("An owner can rename or add
 * accounts afterward") but no screen ever actually implemented. Reuses
 * MANAGE_JOURNAL_ENTRIES, same precedent AccountRoleMappingsController
 * already established for this class of sensitive accounting
 * configuration, rather than adding a new permission constant.
 */
class ChartOfAccountsController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly ChartOfAccountsService $service,
    ) {}

    public function index(): Response
    {
        $this->authorizeManager();

        $categories = AccountCategory::where('business_id', $this->tenantId())
            ->orderBy('reporting_order')
            ->with([
                'subCategories' => fn ($q) => $q->orderBy('reporting_order'),
                'subCategories.accounts' => fn ($q) => $q->orderBy('code'),
                // Accounts directly under a category with no sub-category.
                'accounts' => fn ($q) => $q->whereNull('account_sub_category_id')->orderBy('code'),
            ])
            ->get();

        return Inertia::render('BackOffice/ChartOfAccounts', [
            'categories' => $categories,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_debit_normal' => ['required', 'boolean'],
            'statement_type' => ['required', 'in:balance_sheet,income_statement'],
        ]);

        $this->service->createCategory($this->tenantId(), $data['name'], $data['is_debit_normal'], $data['statement_type']);

        return back()->with('success', 'Category created.');
    }

    public function updateCategory(Request $request, string $category): RedirectResponse
    {
        $this->authorizeManager();

        $category = $this->findOwnedCategory($category);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'reporting_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->service->renameCategory($category, $data['name']);
        $this->service->reorderCategory($category, $data['reporting_order']);

        return back()->with('success', 'Category updated.');
    }

    public function storeSubCategory(Request $request, string $category): RedirectResponse
    {
        $this->authorizeManager();

        $category = $this->findOwnedCategory($category);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $this->service->createSubCategory($this->tenantId(), $category, $data['name']);

        return back()->with('success', 'Sub-category created.');
    }

    public function updateSubCategory(Request $request, string $subCategory): RedirectResponse
    {
        $this->authorizeManager();

        $subCategory = $this->findOwnedSubCategory($subCategory);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'reporting_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->service->renameSubCategory($subCategory, $data['name']);
        $this->service->reorderSubCategory($subCategory, $data['reporting_order']);

        return back()->with('success', 'Sub-category updated.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'account_category_id' => ['required', 'uuid'],
            'account_sub_category_id' => ['nullable', 'uuid'],
            'control_type' => ['nullable', 'in:receivable,payable,inventory'],
            'allow_direct_posting' => ['required', 'boolean'],
            'must_be_positive' => ['required', 'boolean'],
        ]);

        $category = $this->findOwnedCategory($data['account_category_id']);
        $subCategory = $data['account_sub_category_id'] ?? null
            ? $this->findOwnedSubCategory($data['account_sub_category_id'])
            : null;

        $this->service->createAccount(
            $this->tenantId(),
            $category,
            $subCategory,
            $data['name'],
            $data['control_type'] ?? null,
            $data['allow_direct_posting'],
            $data['must_be_positive'],
        );

        return back()->with('success', 'Account created.');
    }

    public function updateAccount(Request $request, string $account): RedirectResponse
    {
        $this->authorizeManager();

        $account = $this->findOwnedAccount($account);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'account_category_id' => ['required', 'uuid'],
            'account_sub_category_id' => ['nullable', 'uuid'],
            'control_type' => ['nullable', 'in:receivable,payable,inventory'],
            'allow_direct_posting' => ['required', 'boolean'],
            'must_be_positive' => ['required', 'boolean'],
        ]);

        $category = $this->findOwnedCategory($data['account_category_id']);
        $subCategory = $data['account_sub_category_id'] ?? null
            ? $this->findOwnedSubCategory($data['account_sub_category_id'])
            : null;

        $this->service->updateAccount(
            $account,
            $data['name'],
            $category,
            $subCategory,
            $data['control_type'] ?? null,
            $data['allow_direct_posting'],
            $data['must_be_positive'],
        );

        return back()->with('success', 'Account updated.');
    }

    public function deactivateAccount(string $account): RedirectResponse
    {
        $this->authorizeManager();

        // findOwnedAccount() must run outside the try — ModelNotFoundException
        // extends RuntimeException in Laravel, so catching \RuntimeException
        // around it would silently turn a genuine 404 (wrong id, or another
        // business's account) into a flash-error redirect instead.
        $account = $this->findOwnedAccount($account);

        try {
            $this->service->deactivateAccount($account);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        return back()->with('success', 'Account deactivated.');
    }

    public function reactivateAccount(string $account): RedirectResponse
    {
        $this->authorizeManager();

        $this->service->reactivateAccount($this->findOwnedAccount($account));

        return back()->with('success', 'Account reactivated.');
    }

    private function findOwnedCategory(string $id): AccountCategory
    {
        return AccountCategory::where('business_id', $this->tenantId())->findOrFail($id);
    }

    private function findOwnedSubCategory(string $id): AccountSubCategory
    {
        return AccountSubCategory::where('business_id', $this->tenantId())->findOrFail($id);
    }

    private function findOwnedAccount(string $id): GlAccount
    {
        return GlAccount::where('business_id', $this->tenantId())->findOrFail($id);
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_JOURNAL_ENTRIES),
            403,
            'Access denied.'
        );
    }
}
