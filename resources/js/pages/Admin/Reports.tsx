import { Deferred, router, Head } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';

type CountPoint = { label: string; count: number };
type Filters = { period: string; date: string; date_from: string; date_to: string; category: string };
type Props = {
    filters: Filters;
    window: { from: string; to: string; label: string; timezone: string; boundary: string };
    population?: { metrics: Record<string, number>; sectors: CountPoint[]; distribution_note: string };
    certificates?: { count: number; trend: CountPoint[]; by_type: CountPoint[]; meaning: string };
    lupon?: { count: number; trend: CountPoint[]; by_type: CountPoint[]; meaning: string };
    programs?: { count: number; trend: CountPoint[]; by_category: CountPoint[]; attendance: CountPoint[] };
    financial?: { available: boolean; message: string };
};

const categoryLabels: Record<string, string> = {
    all: 'All reports',
    population: 'Population reports',
    certificates: 'Certificate reports',
    lupon: 'Lupon reports',
    programs: 'Programs & attendance reports',
    financial: 'Financial reports',
};

export default function Reports({
    filters: initialFilters,
    window,
    population = { metrics: {}, sectors: [], distribution_note: '' },
    certificates = { count: 0, trend: [], by_type: [], meaning: '' },
    lupon = { count: 0, trend: [], by_type: [], meaning: '' },
    programs = { count: 0, trend: [], by_category: [], attendance: [] },
    financial = { available: false, message: '' },
}: Props) {
    const [filters, setFilters] = useState(initialFilters);
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setErrors({});
        router.get('/admin/reports', filters, {
            only: ['filters', 'window', 'population', 'certificates', 'lupon', 'programs', 'financial'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
            onError: (validationErrors) => setErrors(validationErrors as Record<string, string>),
        });
    };

    const exportUrl = '/admin/reports/export?' + new URLSearchParams(filters).toString();
    const visible = (category: string) => filters.category === 'all' || filters.category === category;

    return (
        <>
            <Head title="Admin Reports" />
            <AdminLayout active="reports">
                <div className="mb-6">
                    <p className="text-sm font-medium text-[#3f6f3d]">Management overview</p>
                    <h1 className="mt-1 text-2xl font-semibold text-gray-800">Admin Reports</h1>
                    <p className="mt-1 text-sm text-gray-500">Barangay-scoped summaries for planning and monitoring.</p>
                </div>

                <form onSubmit={applyFilters} className="mb-6 rounded-xl border border-[#e7e4d8] bg-[#fafaf7] p-4">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <label className="text-sm font-medium text-gray-700">
                            Reporting period
                            <select
                                value={filters.period}
                                onChange={(event) => setFilters({ ...filters, period: event.target.value })}
                                className="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                                <option value="range">Date range</option>
                            </select>
                        </label>

                        {filters.period === 'range' ? (
                            <>
                                <label className="text-sm font-medium text-gray-700">
                                    From
                                    <input type="date" value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} className="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm" />
                                    {errors.date_from && <span className="mt-1 block text-xs text-red-700">{errors.date_from}</span>}
                                </label>
                                <label className="text-sm font-medium text-gray-700">
                                    To
                                    <input type="date" value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} className="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm" />
                                    {errors.date_to && <span className="mt-1 block text-xs text-red-700">{errors.date_to}</span>}
                                </label>
                            </>
                        ) : (
                            <label className="text-sm font-medium text-gray-700">
                                {filters.period === 'daily' ? 'Day' : filters.period === 'weekly' ? 'Any day in week' : filters.period === 'yearly' ? 'Year' : 'Month'}
                                <input
                                    type={filters.period === 'yearly' ? 'number' : filters.period === 'monthly' ? 'month' : 'date'}
                                    min={filters.period === 'yearly' ? '2000' : undefined}
                                    max={filters.period === 'yearly' ? '2100' : undefined}
                                    value={filters.period === 'yearly' ? filters.date.slice(0, 4) : filters.period === 'monthly' ? filters.date.slice(0, 7) : filters.date}
                                    onChange={(event) => {
                                        const date = filters.period === 'yearly'
                                            ? event.target.value + '-01-01'
                                            : filters.period === 'monthly'
                                                ? event.target.value + '-01'
                                                : event.target.value;
                                        setFilters({ ...filters, date });
                                    }}
                                    className="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm"
                                />
                                {errors.date && <span className="mt-1 block text-xs text-red-700">{errors.date}</span>}
                            </label>
                        )}

                        <label className="text-sm font-medium text-gray-700">
                            Report category
                            <select value={filters.category} onChange={(event) => setFilters({ ...filters, category: event.target.value })} className="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm">
                                {Object.entries(categoryLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </label>
                    </div>
                    {errors.period && <p role="alert" className="mt-3 text-sm text-red-700">{errors.period}</p>}
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <button type="submit" disabled={loading} className="rounded-lg bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#345b32] disabled:opacity-60">
                            {loading ? 'Generating…' : 'Generate report'}
                        </button>
                        <a href={exportUrl} className="rounded-lg border border-[#3f6f3d] px-4 py-2 text-sm font-semibold text-[#3f6f3d] hover:bg-white">Export aggregate CSV</a>
                        <span className="text-xs text-gray-500">Activity: {window.label} · {window.timezone}. Population cards are current snapshots. Category selection filters detail sections and export; the KPI summary stays complete. {window.boundary}</span>
                    </div>
                </form>

                <Deferred
                    data={['population', 'certificates', 'lupon', 'programs', 'financial']}
                    fallback={
                        <AdminPageSkeleton
                            metrics={6}
                            rows={5}
                            columns={3}
                        />
                    }
                >
                <section aria-labelledby="summary-heading">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h2 id="summary-heading" className="text-base font-semibold text-gray-800">Management summary</h2>
                            <p className="text-xs text-gray-500">Residents are current active records; service totals use the selected period.</p>
                        </div>
                        <span className="text-xs text-gray-500">{categoryLabels[filters.category]}</span>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <Metric title="Total active Residents" value={population.metrics['Total active Residents']} note="Current barangay snapshot" />
                        <Metric title="General Residents" value={population.metrics['General Residents']} note="No sector membership" />
                        <Metric title="PWD" value={population.metrics.PWD} note="Distinct active members" />
                        <Metric title="Senior Citizens" value={population.metrics['Senior Citizens']} note="Distinct active members" />
                        <Metric title="Certificate issuances" value={certificates.count} note="Generated/recorded in period" />
                        <Metric title="Lupon cases filed" value={lupon.count} note="By filed date in period" />
                    </div>
                    <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">{population.distribution_note}</p>
                </section>

                {visible('population') && (
                    <section className="mt-7" aria-labelledby="population-heading">
                        <SectionHeading id="population-heading" title="Population reports" subtitle="Current active population snapshot; not affected by activity date filters." />
                        <Chart title="Sector membership breakdown" points={population.sectors} empty="No sectors are configured." />
                    </section>
                )}

                {visible('certificates') && (
                    <section className="mt-7" aria-labelledby="certificate-heading">
                        <SectionHeading id="certificate-heading" title="Certificate reports" subtitle={certificates.meaning} />
                        <div className="grid gap-4 xl:grid-cols-2">
                            <Chart title="Certificate issuance activity" points={certificates.trend} empty="No certificate issuances in this period." />
                            <Chart title="Generated issuances by certificate type" points={certificates.by_type} empty="No certificate types were issued in this period." />
                        </div>
                    </section>
                )}

                {visible('lupon') && (
                    <section className="mt-7" aria-labelledby="lupon-heading">
                        <SectionHeading id="lupon-heading" title="Lupon reports" subtitle={lupon.meaning} />
                        <div className="grid gap-4 xl:grid-cols-2">
                            <Chart title="Cases filed activity" points={lupon.trend} empty="No cases were filed in this period." />
                            <Chart title="Cases by type" points={lupon.by_type} empty="No case types were filed in this period." />
                        </div>
                    </section>
                )}

                {visible('programs') && (
                    <section className="mt-7" aria-labelledby="program-heading">
                        <SectionHeading id="program-heading" title="Programs & attendance reports" subtitle="Only approved, published Event or attendance-required announcements with an event date are included." />
                        <div className="mb-4 rounded-xl border border-[#e7e4d8] bg-white p-4 text-sm text-gray-700">
                            <span className="font-semibold text-[#3f6f3d]">{programs.count.toLocaleString()}</span> approved and published programs in the selected period.
                        </div>
                        <div className="grid gap-4 xl:grid-cols-2">
                            <Chart title="Program activity by event date" points={programs.trend} empty="No eligible programs in this period." />
                            <Chart title="Programs by category" points={programs.by_category} empty="No program categories in this period." />
                            <Chart title="Attendance records by status" points={programs.attendance} empty="No attendance records for eligible programs in this period." />
                        </div>
                    </section>
                )}

                {visible('financial') && (
                    <section className="mt-7" aria-labelledby="financial-heading">
                        <SectionHeading id="financial-heading" title="Financial reports" subtitle="Unavailable until an authoritative revenue and expense source is established." />
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">{financial.message} Certificate fee snapshots and payment statuses are not treated as collected revenue.</div>
                    </section>
                )}
                </Deferred>
            </AdminLayout>
        </>
    );
}

function Metric({ title, value, note }: { title: string; value: number; note: string }) {
    return (
        <div className="rounded-xl bg-[#3f6f3d] p-5 text-white shadow-sm">
            <p className="text-xs font-medium text-white/80">{title}</p>
            <p className="mt-2 text-3xl font-semibold tabular-nums">{value.toLocaleString()}</p>
            <p className="mt-1 text-xs text-white/70">{note}</p>
        </div>
    );
}

function SectionHeading({ id, title, subtitle }: { id: string; title: string; subtitle: string }) {
    return <div className="mb-3"><h2 id={id} className="text-base font-semibold text-gray-800">{title}</h2><p className="mt-1 text-xs leading-5 text-gray-500">{subtitle}</p></div>;
}

function Chart({ title, points, empty }: { title: string; points: CountPoint[]; empty: string }) {
    const maximum = Math.max(1, ...points.map((point) => point.count));
    return (
        <div className="rounded-xl border border-[#e7e4d8] bg-white p-4">
            <h3 className="mb-3 text-sm font-semibold text-gray-800">{title}</h3>
            {points.length === 0 || points.every((point) => point.count === 0) ? (
                <p className="rounded-lg bg-[#fafaf7] px-3 py-4 text-sm text-gray-500">{empty}</p>
            ) : (
                <ol aria-label={title} className="max-h-72 space-y-2 overflow-auto pr-1">
                    {points.map((point) => (
                        <li key={point.label} className="grid grid-cols-[minmax(5rem,8rem)_1fr_3rem] items-center gap-2 text-xs">
                            <span className="truncate text-gray-600" title={point.label}>{point.label}</span>
                            <span className="h-2 overflow-hidden rounded-full bg-[#eef0e8]" aria-hidden="true">
                                <span className="block h-full rounded-full bg-[#6f9668]" style={{ width: ((point.count / maximum) * 100) + '%' }} />
                            </span>
                            <span className="text-right font-semibold tabular-nums text-gray-800">{point.count.toLocaleString()}</span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
