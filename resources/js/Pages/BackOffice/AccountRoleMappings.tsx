import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface GlAccountRow {
    id: string;
    code: string;
    name: string;
}

interface RoleRow {
    role: string;
    account: GlAccountRow;
}

interface Props {
    roles: RoleRow[];
    accounts: GlAccountRow[];
}

const roleLabels: Record<string, string> = {
    default_cash: 'Default Cash Account',
    default_bank: 'Default Bank Account',
    default_mobile_money: 'Default Mobile Money Account',
    accounts_payable: 'Accounts Payable',
    salary_expense: 'Salary Expense',
    fixed_assets: 'Fixed Assets',
    disposal_gain_loss: 'Gain/Loss on Asset Disposal',
};

export default function AccountRoleMappings({ roles, accounts }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const reassign = (role: string, glAccountId: string) => {
        router.post('/office/account-mappings', { role, gl_account_id: glAccountId }, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Account Mappings" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Account Mappings</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Which GL account each posting role uses — nothing here is fixed; change any of them to match
                    your own chart of accounts.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th className="px-5 py-2.5">Role</th>
                            <th className="px-5 py-2.5">Account</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {roles.map((r) => (
                            <tr key={r.role}>
                                <td className="px-5 py-3 text-slate-700">{roleLabels[r.role] ?? r.role}</td>
                                <td className="px-5 py-3">
                                    <select
                                        value={r.account.id}
                                        onChange={(e) => reassign(r.role, e.target.value)}
                                        className="text-sm rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    >
                                        {accounts.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.code} — {a.name}
                                            </option>
                                        ))}
                                    </select>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </BackOfficeLayout>
    );
}
