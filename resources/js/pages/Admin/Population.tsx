import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';

type PurokRecord = {
    name: string;
    total: number;
    seniors: number;
    pwd: number;
};

const totalResidents = 8;
const seniorCitizens = 2;
const pwdMembers = 4;
const estimatedFamilies = 5;
const luponCases = 5;

const puroks: PurokRecord[] = [
    {
        name: 'Bagong Pag-asa',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Masunurin',
        total: 1,
        seniors: 0,
        pwd: 1,
    },
    {
        name: 'Maligaya',
        total: 1,
        seniors: 1,
        pwd: 1,
    },
    {
        name: 'Matapat',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Matiyaga',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Pagkakaisa',
        total: 1,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Masigla',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Masikap I',
        total: 3,
        seniors: 1,
        pwd: 1,
    },
    {
        name: 'Masikap II',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Mapagmahal',
        total: 2,
        seniors: 0,
        pwd: 1,
    },
    {
        name: 'Masayahin',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
    {
        name: 'Masagana',
        total: 0,
        seniors: 0,
        pwd: 0,
    },
];

export default function Population() {
    return (
        <>
            <Head title="Population Monitoring" />

            <AdminLayout active="population">
                {/* PAGE HEADER */}
                <div className="mb-5">
                    <h1 className="text-2xl font-semibold text-[#315b30]">
                        Population Monitoring
                    </h1>

                    <p className="mt-1 text-sm text-[#9b765a]">
                        Demographic overview and purok breakdown
                    </p>
                </div>

                {/* SUMMARY CARDS */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        title="Total Residents"
                        value={totalResidents}
                        type="residents"
                    />

                    <SummaryCard
                        title="Senior Citizens"
                        value={seniorCitizens}
                        type="senior"
                    />

                    <SummaryCard
                        title="PWD Members"
                        value={pwdMembers}
                        type="pwd"
                    />

                    <SummaryCard
                        title="Est. Families"
                        value={estimatedFamilies}
                        type="families"
                    />
                </div>

                {/* MAIN GRID */}
                <div className="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">

                    {/* PUROK BREAKDOWN */}
                    <section>
                        <h2 className="mb-3 text-lg font-semibold text-[#315b30]">
                            Purok Breakdown
                        </h2>

                        <div className="overflow-hidden rounded-xl border border-[#d9d0c3] bg-white">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[850px]">
                                    <thead className="bg-[#eee7dc]">
                                        <tr>
                                            <TableHead>Purok</TableHead>
                                            <TableHead>Total</TableHead>
                                            <TableHead>Seniors</TableHead>
                                            <TableHead>PWD</TableHead>
                                            <TableHead>Distribution</TableHead>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {puroks.map((purok) => {
                                            const percentage =
                                                totalResidents > 0
                                                    ? Math.round(
                                                          (purok.total /
                                                              totalResidents) *
                                                              100,
                                                      )
                                                    : 0;

                                            return (
                                                <tr
                                                    key={purok.name}
                                                    className="border-b border-[#eee9e2] last:border-0"
                                                >
                                                    <td className="px-4 py-4 text-sm font-semibold text-gray-800">
                                                        {purok.name}
                                                    </td>

                                                    <td className="px-4 py-4 text-sm font-semibold text-[#315b30]">
                                                        {purok.total}
                                                    </td>

                                                    <td className="px-4 py-4 text-sm text-gray-500">
                                                        {purok.seniors}
                                                    </td>

                                                    <td className="px-4 py-4 text-sm text-gray-500">
                                                        {purok.pwd}
                                                    </td>

                                                    <td className="px-4 py-4">
                                                        <div className="flex items-center gap-3">
                                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-[#e8e1d7]">
                                                                <div
                                                                    className="h-full rounded-full bg-[#6fbd5f]"
                                                                    style={{
                                                                        width: `${percentage}%`,
                                                                    }}
                                                                />
                                                            </div>

                                                            <span className="w-10 text-right text-xs text-gray-500">
                                                                {percentage}%
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    {/* SECTOR DISTRIBUTION */}
                    <section>
                        <h2 className="mb-3 text-lg font-semibold text-[#315b30]">
                            Sector Distribution
                        </h2>

                        <div className="rounded-xl border border-[#d9d0c3] bg-white p-4">
                            <DistributionRow
                                label="General Residents"
                                value={totalResidents}
                                max={totalResidents}
                                type="general"
                            />

                            <DistributionRow
                                label="Senior Citizens"
                                value={seniorCitizens}
                                max={totalResidents}
                                type="senior"
                            />

                            <DistributionRow
                                label="PWD Members"
                                value={pwdMembers}
                                max={totalResidents}
                                type="pwd"
                            />

                            <DistributionRow
                                label="Lupon Cases"
                                value={luponCases}
                                max={totalResidents}
                                type="lupon"
                                last
                            />
                        </div>
                    </section>

                </div>
            </AdminLayout>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/* Summary Card                                                               */
/* -------------------------------------------------------------------------- */

function SummaryCard({
    title,
    value,
    type,
}: {
    title: string;
    value: number;
    type: 'residents' | 'senior' | 'pwd' | 'families';
}) {
    const styles = {
        residents:
            'border-[#3db85a] bg-[#dff0e2] text-[#315b30]',
        senior:
            'border-[#579fea] bg-[#dcecff] text-[#0d4d91]',
        pwd:
            'border-[#e4ad25] bg-[#fff2c9] text-[#8a6500]',
        families:
            'border-[#b8a38d] bg-[#eee6dc] text-[#765236]',
    };

    return (
        <div className={`rounded-xl border p-5 ${styles[type]}`}>
            <p className="text-xs font-semibold uppercase tracking-wide">
                {title}
            </p>

            <p className="mt-2 text-2xl font-semibold">
                {value}
            </p>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Table Head                                                                 */
/* -------------------------------------------------------------------------- */

function TableHead({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#a7846b]">
            {children}
        </th>
    );
}

/* -------------------------------------------------------------------------- */
/* Distribution Row                                                          */
/* -------------------------------------------------------------------------- */

function DistributionRow({
    label,
    value,
    max,
    type,
    last = false,
}: {
    label: string;
    value: number;
    max: number;
    type: 'general' | 'senior' | 'pwd' | 'lupon';
    last?: boolean;
}) {
    const percentage =
        max > 0
            ? Math.min((value / max) * 100, 100)
            : 0;

    const barStyles = {
        general: 'bg-[#3f6f3d]',
        senior: 'bg-[#73bf61]',
        pwd: 'bg-[#9b8065]',
        lupon: 'bg-[#ed7614]',
    };

    const valueStyles = {
        general: 'text-[#315b30]',
        senior: 'text-[#63aa53]',
        pwd: 'text-[#8e755c]',
        lupon: 'text-[#dd6b12]',
    };

    return (
        <div
            className={`py-3 ${
                !last ? 'border-b border-gray-100' : ''
            }`}
        >
            <div className="mb-1.5 flex items-center justify-between gap-3">
                <span className="text-xs font-medium text-gray-700">
                    {label}
                </span>

                <span
                    className={`text-xs font-semibold ${valueStyles[type]}`}
                >
                    {value}
                </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-[#e8e1d7]">
                <div
                    className={`h-full rounded-full ${barStyles[type]}`}
                    style={{
                        width: `${percentage}%`,
                    }}
                />
            </div>
        </div>
    );
}