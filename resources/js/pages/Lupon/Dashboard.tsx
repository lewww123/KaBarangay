import { Head } from '@inertiajs/react';
import LuponLayout from '@/layouts/LuponLayout';

interface Props {
    auth: { user: { name?: string; staff_id?: string; department?: string } };
    userName?: string;
    barangayName?: string | null;
}

export default function LuponDashboard({ auth, userName, barangayName = null }: Props) {
    return (
        <LuponLayout active="dashboard" user={auth.user} barangayName={barangayName}>
            <Head title="Lupon Portal" />

            <div className="mx-auto max-w-7xl space-y-6 font-sans p-2 sm:p-4">
                {/* WELCOME BANNER */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Lupon Portal
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        {userName ? `Signed in as ${userName}` : 'Lupon staff portal'}
                    </p>
                </div>

                {/* EMPTY STATE */}
                <div className="rounded-2xl border border-dashed border-gray-300 bg-[#fafaf7] p-10 text-center">
                    <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#e8f1e4] text-2xl">
                        ⚖️
                    </div>

                    <h2 className="mt-4 text-base font-semibold text-gray-800">
                        Lupon portal is ready for setup. Case management is not yet available.
                    </h2>

                    <p className="mx-auto mt-2 max-w-xl text-sm text-gray-500">
                        Case records, hearings, and settlements are not yet connected to this
                        portal. No case data is shown on this screen.
                    </p>
                </div>
            </div>
        </LuponLayout>
    );
}
