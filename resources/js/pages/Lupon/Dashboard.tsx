import { Head, Link } from '@inertiajs/react';
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
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">
                            Lupon Portal
                        </h1>

                        <p className="mt-1 text-sm text-gray-500">
                            {userName ? `Signed in as ${userName}` : 'Lupon staff portal'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/lupon/complaints"
                            className="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                        >
                            📋 Complaints Queue
                        </Link>

                        <Link
                            href="/lupon/cases"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#315b30]"
                        >
                            📂 Lupon Cases
                        </Link>
                        <Link
                            href="/lupon/hearings"
                            className="inline-flex items-center gap-1.5 rounded-xl border border-[#3f6f3d]/30 bg-[#e8f1e4] px-4 py-2 text-sm font-semibold text-[#315b30] shadow-sm hover:bg-[#dcebd6]"
                        >
                            🗓️ Hearings
                        </Link>
                    </div>
                </div>

                <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-2xl">
                            ⚖️
                        </div>
                        <div>
                            <h2 className="text-base font-semibold text-gray-800">Lupon work queues</h2>
                            <p className="mt-1 max-w-2xl text-sm text-gray-500">
                                Review resident complaints, manage cases, and check scheduled hearings for your barangay.
                            </p>
                        </div>
                    </div>
                    <div className="mt-5 grid gap-3 sm:grid-cols-3">
                        {[
                            { title: 'Complaints', description: 'Review submitted complaints and accepted cases.', href: '/lupon/complaints', icon: '📋' },
                            { title: 'Cases', description: 'Open case records, parties, proceedings, and settlements.', href: '/lupon/cases', icon: '📂' },
                            { title: 'Hearings', description: 'View hearing schedules and recorded outcomes.', href: '/lupon/hearings', icon: '🗓️' },
                        ].map((item) => (
                            <Link key={item.href} href={item.href} className="rounded-xl border border-gray-200 bg-[#fafaf7] p-4 transition hover:border-[#3f6f3d]/50 hover:bg-[#f4f7ef]">
                                <span className="text-xl" aria-hidden="true">{item.icon}</span>
                                <h3 className="mt-2 text-sm font-semibold text-gray-800">{item.title}</h3>
                                <p className="mt-1 text-xs leading-5 text-gray-500">{item.description}</p>
                                <span className="mt-3 inline-block text-xs font-semibold text-[#3f6f3d]">Open {item.title} →</span>
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </LuponLayout>
    );
}
