import React, { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface AccountRow {
    id: string;
    code: string;
    name: string;
    account_category_id: string;
    account_sub_category_id: string | null;
    control_type: 'receivable' | 'payable' | 'inventory' | null;
    allow_direct_posting: boolean;
    must_be_positive: boolean;
    status: 'active' | 'inactive';
}

interface SubCategoryRow {
    id: string;
    name: string;
    reporting_order: number;
    accounts: AccountRow[];
}

interface CategoryRow {
    id: string;
    name: string;
    code: number | null;
    is_debit_normal: boolean;
    statement_type: 'balance_sheet' | 'income_statement';
    reporting_order: number;
    is_system: boolean;
    sub_categories: SubCategoryRow[];
    accounts: AccountRow[]; // accounts with no sub-category
}

interface Props {
    categories: CategoryRow[];
}

const controlTypeLabels: Record<string, string> = {
    receivable: 'Receivable',
    payable: 'Payable',
    inventory: 'Inventory',
};

function AccountForm({
    categoryId,
    subCategoryId,
    onDone,
}: {
    categoryId: string;
    subCategoryId: string | null;
    onDone: () => void;
}) {
    const form = useForm({
        name: '',
        account_category_id: categoryId,
        account_sub_category_id: subCategoryId ?? '',
        control_type: '',
        allow_direct_posting: true as boolean,
        must_be_positive: false as boolean,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/chart-of-accounts/accounts', {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 bg-slate-50 rounded-xl p-3 mt-2">
            <div className="flex-1 min-w-[160px]">
                <label className="text-xs font-semibold text-slate-500">Account name</label>
                <input
                    type="text" required autoFocus placeholder="e.g. Equipment Rental"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className="mt-1 w-full text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
                {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Control type</label>
                <select
                    value={form.data.control_type}
                    onChange={(e) => form.setData('control_type', e.target.value)}
                    className="mt-1 text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="">None</option>
                    <option value="receivable">Receivable</option>
                    <option value="payable">Payable</option>
                    <option value="inventory">Inventory</option>
                </select>
            </div>
            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input
                    type="checkbox" checked={form.data.allow_direct_posting}
                    onChange={(e) => form.setData('allow_direct_posting', e.target.checked)}
                />
                Allow direct posting
            </label>
            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input
                    type="checkbox" checked={form.data.must_be_positive}
                    onChange={(e) => form.setData('must_be_positive', e.target.checked)}
                />
                Must stay positive
            </label>
            <button type="submit" disabled={form.processing} className="btn-primary py-1.5 px-4 text-sm disabled:opacity-50">
                {form.processing ? 'Saving…' : 'Add Account'}
            </button>
            <button type="button" onClick={onDone} className="text-sm text-slate-400 hover:text-slate-600">
                Cancel
            </button>
        </form>
    );
}

function AccountEditForm({ account, onDone }: { account: AccountRow; onDone: () => void }) {
    const form = useForm({
        name: account.name,
        account_category_id: account.account_category_id,
        account_sub_category_id: account.account_sub_category_id ?? '',
        control_type: account.control_type ?? '',
        allow_direct_posting: account.allow_direct_posting,
        must_be_positive: account.must_be_positive,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/office/chart-of-accounts/accounts/${account.id}`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 bg-amber-50 rounded-xl p-3 mt-1">
            <div className="flex-1 min-w-[160px]">
                <label className="text-xs font-semibold text-slate-500">Account name</label>
                <input
                    type="text" required autoFocus
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className="mt-1 w-full text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Control type</label>
                <select
                    value={form.data.control_type}
                    onChange={(e) => form.setData('control_type', e.target.value)}
                    className="mt-1 text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="">None</option>
                    <option value="receivable">Receivable</option>
                    <option value="payable">Payable</option>
                    <option value="inventory">Inventory</option>
                </select>
            </div>
            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input
                    type="checkbox" checked={form.data.allow_direct_posting}
                    onChange={(e) => form.setData('allow_direct_posting', e.target.checked)}
                />
                Allow direct posting
            </label>
            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input
                    type="checkbox" checked={form.data.must_be_positive}
                    onChange={(e) => form.setData('must_be_positive', e.target.checked)}
                />
                Must stay positive
            </label>
            <button type="submit" disabled={form.processing} className="btn-primary py-1.5 px-4 text-sm disabled:opacity-50">
                Save
            </button>
            <button type="button" onClick={onDone} className="text-sm text-slate-400 hover:text-slate-600">
                Cancel
            </button>
        </form>
    );
}

function AccountRowView({ account }: { account: AccountRow }) {
    const [editing, setEditing] = useState(false);
    const actionForm = useForm({});

    const toggleActive = () => {
        const action = account.status === 'active' ? 'deactivate' : 'reactivate';
        if (action === 'deactivate' && !confirm(`Deactivate ${account.code} — ${account.name}? Its history stays intact.`)) return;
        actionForm.post(`/office/chart-of-accounts/accounts/${account.id}/${action}`, { preserveScroll: true });
    };

    return (
        <div className="border-b border-slate-50 last:border-0">
            <div className="flex items-center gap-3 px-4 py-2 text-sm">
                <span className="w-16 text-slate-400 font-mono text-xs">{account.code}</span>
                <span className={`flex-1 ${account.status === 'inactive' ? 'text-slate-400 line-through' : 'text-slate-700'}`}>
                    {account.name}
                </span>
                {account.control_type && (
                    <span className="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                        {controlTypeLabels[account.control_type]}
                    </span>
                )}
                {!account.allow_direct_posting && (
                    <span className="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-600">No direct posting</span>
                )}
                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${account.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                    {account.status === 'active' ? 'Active' : 'Inactive'}
                </span>
                <button onClick={() => setEditing((v) => !v)} className="text-xs font-semibold text-slate-500 hover:underline">
                    {editing ? 'Cancel' : 'Edit'}
                </button>
                <button onClick={toggleActive} className={`text-xs font-semibold hover:underline ${account.status === 'active' ? 'text-red-500' : 'text-emerald-600'}`}>
                    {account.status === 'active' ? 'Deactivate' : 'Reactivate'}
                </button>
            </div>
            {editing && <AccountEditForm account={account} onDone={() => setEditing(false)} />}
        </div>
    );
}

function SubCategorySection({ category, subCategory }: { category: CategoryRow; subCategory: SubCategoryRow }) {
    const [addingAccount, setAddingAccount] = useState(false);

    return (
        <div className="ml-4 mt-3">
            <div className="flex items-center justify-between">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{subCategory.name}</h4>
                <button onClick={() => setAddingAccount((v) => !v)} className="text-xs font-semibold text-emerald-600 hover:underline">
                    + Add Account
                </button>
            </div>
            <div className="mt-1 bg-white rounded-xl border border-slate-100">
                {subCategory.accounts.map((a) => <AccountRowView key={a.id} account={a} />)}
                {subCategory.accounts.length === 0 && (
                    <p className="px-4 py-3 text-xs text-slate-400">No accounts yet.</p>
                )}
            </div>
            {addingAccount && (
                <AccountForm
                    categoryId={category.id}
                    subCategoryId={subCategory.id}
                    onDone={() => setAddingAccount(false)}
                />
            )}
        </div>
    );
}

function SubCategoryForm({ categoryId, onDone }: { categoryId: string; onDone: () => void }) {
    const form = useForm({ name: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/office/chart-of-accounts/categories/${categoryId}/sub-categories`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex items-end gap-2 mt-2">
            <input
                type="text" required autoFocus placeholder="Sub-category name"
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                className="text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
            <button type="submit" disabled={form.processing} className="btn-primary py-1.5 px-4 text-sm disabled:opacity-50">
                Add
            </button>
            <button type="button" onClick={onDone} className="text-sm text-slate-400 hover:text-slate-600">
                Cancel
            </button>
        </form>
    );
}

function CategorySection({ category }: { category: CategoryRow }) {
    const [addingSubCategory, setAddingSubCategory] = useState(false);
    const [addingAccount, setAddingAccount] = useState(false);

    return (
        <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="text-base font-bold text-slate-900">{category.name}</h3>
                    <p className="text-xs text-slate-400">
                        {category.statement_type === 'balance_sheet' ? 'Balance Sheet' : 'Income Statement'} ·{' '}
                        {category.is_debit_normal ? 'Debit-normal' : 'Credit-normal'}
                        {category.is_system && ' · System category'}
                    </p>
                </div>
                <div className="flex gap-3">
                    <button onClick={() => setAddingSubCategory((v) => !v)} className="text-xs font-semibold text-emerald-600 hover:underline">
                        + Add Sub-category
                    </button>
                    <button onClick={() => setAddingAccount((v) => !v)} className="text-xs font-semibold text-emerald-600 hover:underline">
                        + Add Account
                    </button>
                </div>
            </div>

            {addingSubCategory && (
                <SubCategoryForm categoryId={category.id} onDone={() => setAddingSubCategory(false)} />
            )}
            {addingAccount && (
                <AccountForm categoryId={category.id} subCategoryId={null} onDone={() => setAddingAccount(false)} />
            )}

            {category.accounts.length > 0 && (
                <div className="ml-4 mt-3 bg-white rounded-xl border border-slate-100">
                    {category.accounts.map((a) => <AccountRowView key={a.id} account={a} />)}
                </div>
            )}

            {category.sub_categories.map((sc) => (
                <SubCategorySection key={sc.id} category={category} subCategory={sc} />
            ))}
        </div>
    );
}

function NewCategoryForm({ onDone }: { onDone: () => void }) {
    const form = useForm({ name: '', is_debit_normal: true as boolean, statement_type: 'balance_sheet' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/chart-of-accounts/categories', {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 flex flex-wrap items-end gap-3">
            <div className="flex-1 min-w-[160px]">
                <label className="text-xs font-semibold text-slate-500">Category name</label>
                <input
                    type="text" required autoFocus placeholder="e.g. Other Income"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className="mt-1 w-full text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Statement</label>
                <select
                    value={form.data.statement_type}
                    onChange={(e) => form.setData('statement_type', e.target.value)}
                    className="mt-1 text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="balance_sheet">Balance Sheet</option>
                    <option value="income_statement">Income Statement</option>
                </select>
            </div>
            <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input
                    type="checkbox" checked={form.data.is_debit_normal}
                    onChange={(e) => form.setData('is_debit_normal', e.target.checked)}
                />
                Debit-normal balance
            </label>
            <button type="submit" disabled={form.processing} className="btn-primary py-1.5 px-4 text-sm disabled:opacity-50">
                Add Category
            </button>
            <button type="button" onClick={onDone} className="text-sm text-slate-400 hover:text-slate-600">
                Cancel
            </button>
        </form>
    );
}

export default function ChartOfAccounts({ categories }: Props) {
    const [addingCategory, setAddingCategory] = useState(false);
    const { flash, errors } = usePage().props as unknown as { flash: { success: string | null }; errors: { account?: string } };

    return (
        <BackOfficeLayout>
            <Head title="Chart of Accounts" />

            <div className="mb-6 flex items-start justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Chart of Accounts</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Every GL account and category your business posts to. Codes are assigned automatically and
                        never change once set — rename, recategorize, or deactivate an account instead.
                    </p>
                </div>
                <button onClick={() => setAddingCategory((v) => !v)} className="btn-primary py-2 px-4">
                    {addingCategory ? 'Cancel' : 'Add Category'}
                </button>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.account && (
                <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {errors.account}
                </div>
            )}

            {addingCategory && (
                <div className="mb-6">
                    <NewCategoryForm onDone={() => setAddingCategory(false)} />
                </div>
            )}

            <div className="space-y-6">
                {categories.map((c) => <CategorySection key={c.id} category={c} />)}
            </div>
        </BackOfficeLayout>
    );
}
