import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';

type CertificateInsight = {
    name: string;
    count: number;
    fee: number | null;
};

const certificateInsights: CertificateInsight[] = [
    {
        name: 'Certificate of Residency',
        count: 1,
        fee: 100,
    },
    {
        name: 'Retail',
        count: 0,
        fee: 300,
    },
    {
        name: 'Tricycle',
        count: 0,
        fee: 300,
    },
    {
        name: 'Sari-Sari Store',
        count: 0,
        fee: 500,
    },
    {
        name: 'Hardware Store',
        count: 0,
        fee: 3000,
    },
    {
        name: 'Pool / Resort',
        count: 0,
        fee: 1500,
    },
    {
        name: 'Certificate of Indigency',
        count: 1,
        fee: null,
    },
];

export default function Reports() {
    return (
        <>
            <Head title="System Reports" />

            <AdminLayout active="reports">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        System Reports
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        High-level system metrics and transaction activity
                    </p>
                </div>

                <section>
                    <h2 className="mb-4 text-base font-semibold">
                        High-Level Metrics
                    </h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Metric title="TOTAL RESIDENTS" value="8" />
                        <Metric title="CERTIFICATES ISSUED" value="2" />
                        <Metric title="PWD MEMBERS" value="4" />
                        <Metric title="LUPON CASES" value="5" />
                    </div>
                </section>

                <div className="mt-7 grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <section>
                        <h2 className="mb-4 text-base font-semibold">
                            Certificate Insights
                        </h2>

                        <div className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {certificateInsights.map((certificate) => (
                                    <div
                                        key={certificate.name}
                                        className="rounded-lg border border-gray-100 bg-[#fafaf7] p-3"
                                    >
                                        <div className="flex justify-between gap-3">
                                            <div>
                                                <p className="text-xs font-semibold">
                                                    {certificate.name}
                                                </p>

                                                <p className="mt-1 text-[11px] text-gray-400">
                                                    {certificate.count} issued
                                                </p>
                                            </div>

                                            <span className="text-xs font-semibold text-[#3f6f3d]">
                                                {certificate.fee !== null
                                                    ? `₱${certificate.fee.toLocaleString()}`
                                                    : 'No Fee'}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section>
                        <h2 className="mb-4 text-base font-semibold">
                            Transaction Summary
                        </h2>

                        <div className="rounded-lg border border-gray-200 bg-white">
                            <Transaction label="Today" value="8" />
                            <Transaction label="This Week" value="51" />
                            <Transaction label="This Month" value="214" />
                            <Transaction label="Completed" value="76" />
                            <Transaction
                                label="Pending"
                                value="18"
                                last
                            />
                        </div>
                    </section>
                </div>

                <section className="mt-7">
                    <h2 className="mb-4 text-base font-semibold">
                        Certificate Fee Reference
                    </h2>

                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <table className="w-full">
                            <thead className="bg-[#fafaf7]">
                                <tr className="border-b">
                                    <th className="px-4 py-3 text-left text-xs text-gray-400">
                                        Certificate
                                    </th>

                                    <th className="px-4 py-3 text-right text-xs text-gray-400">
                                        Fee
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {certificateInsights
                                    .filter(
                                        (certificate) =>
                                            certificate.fee !== null,
                                    )
                                    .map((certificate) => (
                                        <tr
                                            key={certificate.name}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 text-sm">
                                                {certificate.name}
                                            </td>

                                            <td className="px-4 py-3 text-right text-sm font-semibold text-[#3f6f3d]">
                                                ₱
                                                {certificate.fee?.toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </AdminLayout>
        </>
    );
}

function Metric({
    title,
    value,
}: {
    title: string;
    value: string;
}) {
    return (
        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white">
            <p className="text-xs text-white/70">{title}</p>

            <p className="mt-3 text-3xl font-semibold">
                {value}
            </p>
        </div>
    );
}

function Transaction({
    label,
    value,
    last = false,
}: {
    label: string;
    value: string;
    last?: boolean;
}) {
    return (
        <div
            className={`flex justify-between px-5 py-4 ${
                !last ? 'border-b' : ''
            }`}
        >
            <span className="text-xs text-gray-500">
                {label}
            </span>

            <span className="font-semibold">
                {value}
            </span>
        </div>
    );
}