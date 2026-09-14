import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type SectorTab =
    | 'Residents'
    | 'Certificates'
    | 'Lupon'
    | 'PWD'
    | 'Senior';

type Activity = {
    name: string;
    description: string;
    date: string;
};

const sectorData: Record<
    SectorTab,
    {
        title: string;
        subtitle: string;
        total: number;
        activities: Activity[];
    }
> = {
    Residents: {
        title: 'Resident Snapshot',
        subtitle: 'Resident records and activity',
        total: 8,
        activities: [
            {
                name: 'Resident records',
                description: 'Registered residents in the barangay',
                date: 'Today',
            },
            {
                name: 'Resident Management',
                description: 'Resident information is available in the Residents module',
                date: 'Today',
            },
            {
                name: 'Recent Registration',
                description: 'New resident records can be added by authorized staff',
                date: 'Yesterday',
            },
        ],
    },

    Certificates: {
        title: 'Certificate Snapshot',
        subtitle: 'Certificate requests and issuance activity',
        total: 3,
        activities: [
            {
                name: 'Certificate Issuance',
                description: 'Certificates currently recorded in the system',
                date: 'Today',
            },
            {
                name: 'Pending Processing',
                description: 'Certificate requests awaiting processing',
                date: 'Today',
            },
            {
                name: 'Certificate Activity',
                description: 'Recent certificate-related activity',
                date: 'Yesterday',
            },
        ],
    },

    Lupon: {
        title: 'Lupon Snapshot',
        subtitle: 'Lupong Tagapamayapa cases and hearings',
        total: 0,
        activities: [
            {
                name: 'Open Cases',
                description: 'Currently open Lupon cases',
                date: 'Today',
            },
            {
                name: 'Hearings',
                description: 'Scheduled Lupon hearings',
                date: 'Today',
            },
            {
                name: 'Case Activity',
                description: 'Recent Lupon case activity',
                date: 'Yesterday',
            },
        ],
    },

    PWD: {
        title: 'PWD Snapshot',
        subtitle: 'Registered PWD members and activity',
        total: 0,
        activities: [
            {
                name: 'PWD Members',
                description: 'Registered PWD residents',
                date: 'Today',
            },
            {
                name: 'PWD Records',
                description: 'PWD sector information and membership records',
                date: 'Today',
            },
            {
                name: 'Recent Activity',
                description: 'Recent PWD sector activity',
                date: 'Yesterday',
            },
        ],
    },

    Senior: {
        title: 'Senior Citizen Snapshot',
        subtitle: 'Registered senior citizens and activity',
        total: 0,
        activities: [
            {
                name: 'Senior Citizens',
                description: 'Registered senior citizen residents',
                date: 'Today',
            },
            {
                name: 'Senior Records',
                description: 'Senior Citizen sector information and membership records',
                date: 'Today',
            },
            {
                name: 'Recent Activity',
                description: 'Recent Senior Citizen sector activity',
                date: 'Yesterday',
            },
        ],
    },
};

export default function Sectors() {
    const [activeTab, setActiveTab] =
        useState<SectorTab>('Residents');

    const data = sectorData[activeTab];

    return (
        <>
            <Head title="Sectors" />

            <AdminLayout active="sectors">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Sectors
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        Sector overview and activity
                    </p>
                </div>

                <section>
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            Recent Activity
                        </h2>
                    </div>

                    <div className="mb-5 flex flex-wrap gap-2">
                        {(
                            [
                                'Residents',
                                'Certificates',
                                'Lupon',
                                'PWD',
                                'Senior',
                            ] as SectorTab[]
                        ).map((tab) => (
                            <button
                                key={tab}
                                type="button"
                                onClick={() => setActiveTab(tab)}
                                className={`rounded-full px-4 py-2 text-xs font-medium transition ${
                                    activeTab === tab
                                        ? 'bg-[#3f6f3d] text-white'
                                        : 'text-gray-500 hover:bg-gray-100'
                                }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </div>

                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            {data.title}
                        </h2>

                        <p className="mt-1 text-xs text-gray-500">
                            {data.subtitle}
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white">
                            <p className="text-xs font-medium uppercase tracking-wide text-white/80">
                                Total
                            </p>

                            <p className="mt-3 text-3xl font-semibold">
                                {data.total}
                            </p>

                            <p className="mt-1 text-xs text-white/70">
                                Registered records
                            </p>
                        </div>

                        <div className="rounded-lg border border-gray-200 bg-white p-5">
                            <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
                                Status
                            </p>

                            <p className="mt-3 text-xl font-semibold text-gray-800">
                                Active
                            </p>

                            <p className="mt-1 text-xs text-gray-500">
                                Current sector records
                            </p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <h3 className="mb-4 text-sm font-semibold text-gray-800">
                            Recent Activity
                        </h3>

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            {data.activities.map((activity, index) => (
                                <div
                                    key={index}
                                    className={`flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between ${
                                        index !== data.activities.length - 1
                                            ? 'border-b border-gray-100'
                                            : ''
                                    }`}
                                >
                                    <div>
                                        <p className="text-sm font-medium text-gray-800">
                                            {activity.name}
                                        </p>

                                        <p className="mt-0.5 text-xs text-gray-500">
                                            {activity.description}
                                        </p>
                                    </div>

                                    <span className="text-[10px] text-gray-400">
                                        {activity.date}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            </AdminLayout>
        </>
    );
}