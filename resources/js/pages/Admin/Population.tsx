import { Deferred, Head } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';

type PurokRecord = {
    id: number;
    name: string;
    total: number;
    seniors: number;
    pwd: number;
};

type PopulationStats = {
    totalResidents: number;
    seniorCitizens: number;
    pwdMembers: number;
    seniorAndPwd: number;
    regularResidents: number;
    inactiveResidents: number;
    pendingRegistrations: number;
    rejectedRegistrations: number;
};

type Props = {
    stats: PopulationStats;
    puroks: PurokRecord[];
};

export default function Population(props: Props) {
    return (
        <Deferred
            data={['stats', 'puroks']}
            fallback={
                <AdminLayout active="population">
                    <AdminPageSkeleton metrics={4} />
                </AdminLayout>
            }
        >
            <PopulationContent {...props} />
        </Deferred>
    );
}

function PopulationContent({ stats, puroks }: Props) {
    const hasPopulation = stats.totalResidents > 0;

    return (
        <>
            <Head title="Population Monitoring" />

            <AdminLayout active="population">
                {/* PAGE HEADER */}
                <div className="mb-5">
                    <h1 className="text-2xl font-semibold text-[#2a5279]">
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
                        value={stats.totalResidents}
                        type="residents"
                    />

                    <SummaryCard
                        title="Senior Citizens"
                        value={stats.seniorCitizens}
                        type="senior"
                    />

                    <SummaryCard
                        title="PWD Members"
                        value={stats.pwdMembers}
                        type="pwd"
                    />

                    <SummaryCard
                        title="Regular Residents"
                        value={stats.regularResidents}
                        type="regular"
                    />
                </div>

                {/* MAIN GRID */}
                <div className="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    {/* PUROK BREAKDOWN */}
                    <section>
                        <h2 className="mb-3 text-lg font-semibold text-[#2a5279]">
                            Purok Breakdown
                        </h2>

                        <div className="overflow-hidden rounded-xl border border-[#c2cbd4] bg-white">
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
                                                stats.totalResidents > 0
                                                    ? Math.round(
                                                          (purok.total /
                                                              stats.totalResidents) *
                                                              100,
                                                      )
                                                    : 0;

                                            return (
                                                <tr
                                                    key={purok.id}
                                                    className="border-b border-[#eee9e2] last:border-0"
                                                >
                                                    <td className="px-4 py-4 text-sm font-semibold text-gray-800">
                                                        {purok.name}
                                                    </td>

                                                    <td className="px-4 py-4 text-sm font-semibold text-[#2a5279]">
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

                            {puroks.length === 0 && (
                                <p className="px-5 py-10 text-center text-sm text-gray-400">
                                    No puroks have been defined for this
                                    barangay yet.
                                </p>
                            )}
                        </div>
                    </section>

                    {/* SECTOR DISTRIBUTION */}
                    <section>
                        <h2 className="mb-3 text-lg font-semibold text-[#2a5279]">
                            Sector Distribution
                        </h2>

                        <div className="rounded-xl border border-[#c2cbd4] bg-white p-4">
                            <DistributionRow
                                label="Total Residents"
                                value={stats.totalResidents}
                                max={stats.totalResidents}
                                type="general"
                            />

                            <DistributionRow
                                label="Regular Residents (no sector)"
                                value={stats.regularResidents}
                                max={stats.totalResidents}
                                type="regular"
                            />

                            <DistributionRow
                                label="Senior Citizens"
                                value={stats.seniorCitizens}
                                max={stats.totalResidents}
                                type="senior"
                            />

                            <DistributionRow
                                label="PWD Members"
                                value={stats.pwdMembers}
                                max={stats.totalResidents}
                                type="pwd"
                                last
                            />

                            <p className="mt-3 text-xs text-gray-400">
                                Senior and PWD membership can overlap (
                                {stats.seniorAndPwd} resident
                                {stats.seniorAndPwd === 1 ? '' : 's'} in both),
                                so category counts are not mutually exclusive.
                            </p>
                        </div>

                        {/* EXCLUDED FROM POPULATION */}
                        <div className="mt-4 rounded-xl border border-[#c2cbd4] bg-[#ffffff] p-4">
                            <h3 className="text-xs font-semibold tracking-wide text-[#a7846b] uppercase">
                                Not Counted as Population
                            </h3>

                            <p className="mt-1 text-xs text-gray-400">
                                Only active permanent residents are included in
                                the totals above.
                            </p>

                            <dl className="mt-3 space-y-2">
                                <ExcludedRow
                                    label="Inactive residents"
                                    value={stats.inactiveResidents}
                                />
                                <ExcludedRow
                                    label="Pending applications"
                                    value={stats.pendingRegistrations}
                                />
                                <ExcludedRow
                                    label="Rejected applications"
                                    value={stats.rejectedRegistrations}
                                />
                            </dl>
                        </div>
                    </section>
                </div>

                {!hasPopulation && (
                    <p className="mt-5 rounded-xl border border-[#c2cbd4] bg-white px-5 py-4 text-sm text-gray-400">
                        No active permanent residents have been recorded for
                        this barangay yet.
                    </p>
                )}
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
    type: 'residents' | 'senior' | 'pwd' | 'regular';
}) {
    const styles = {
        residents: 'border-[#3db85a] bg-[#dff0e2] text-[#2a5279]',
        senior: 'border-[#579fea] bg-[#dcecff] text-[#0d4d91]',
        pwd: 'border-[#e4ad25] bg-[#fff2c9] text-[#8a6500]',
        regular: 'border-[#b8a38d] bg-[#eee6dc] text-[#765236]',
    };

    return (
        <div className={`rounded-xl border p-5 ${styles[type]}`}>
            <p className="text-xs font-semibold tracking-wide uppercase">
                {title}
            </p>

            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Table Head                                                                 */
/* -------------------------------------------------------------------------- */

function TableHead({ children }: { children: React.ReactNode }) {
    return (
        <th className="px-4 py-3 text-left text-xs font-semibold tracking-wide text-[#a7846b] uppercase">
            {children}
        </th>
    );
}

/* -------------------------------------------------------------------------- */
/* Distribution Row                                                           */
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
    type: 'general' | 'regular' | 'senior' | 'pwd';
    last?: boolean;
}) {
    const percentage = max > 0 ? Math.min((value / max) * 100, 100) : 0;

    const barStyles = {
        general: 'bg-[#3b6fa8]',
        regular: 'bg-[#9b8065]',
        senior: 'bg-[#73bf61]',
        pwd: 'bg-[#e4ad25]',
    };

    const valueStyles = {
        general: 'text-[#2a5279]',
        regular: 'text-[#8e755c]',
        senior: 'text-[#63aa53]',
        pwd: 'text-[#8a6500]',
    };

    return (
        <div className={`py-3 ${!last ? 'border-b border-gray-100' : ''}`}>
            <div className="mb-1.5 flex items-center justify-between gap-3">
                <span className="text-xs font-medium text-gray-700">
                    {label}
                </span>

                <span className={`text-xs font-semibold ${valueStyles[type]}`}>
                    {value}
                </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-[#e8e1d7]">
                <div
                    className={`h-full rounded-full ${barStyles[type]}`}
                    style={{ width: `${percentage}%` }}
                />
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Excluded Row                                                               */
/* -------------------------------------------------------------------------- */

function ExcludedRow({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-xs text-gray-600">{label}</dt>
            <dd className="text-xs font-semibold text-gray-700">{value}</dd>
        </div>
    );
}
