import React from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { PageProps } from '@/types';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <AppLayout>
            <Head title="Profile" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Profile</h1>
                <p className="text-sm text-slate-500 mt-1">Manage your account settings</p>
            </div>

            <div className="max-w-2xl space-y-6">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <UpdateProfileInformationForm
                        mustVerifyEmail={mustVerifyEmail}
                        status={status}
                        className="max-w-xl"
                    />
                </div>

                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <UpdatePasswordForm className="max-w-xl" />
                </div>

                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <DeleteUserForm className="max-w-xl" />
                </div>
            </div>
        </AppLayout>
    );
}
