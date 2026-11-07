import { useEffect, useRef, useState } from 'react';
import { Deferred, Head, router, usePage } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';

interface CertificateRequestDetailRow {
    label: string;
    value: string;
}

interface CertificateRequestRow {
    id: number;
    reference: string;
    certificate: string;
    certificate_type?: string | null;
    resident: {
        name: string;
        resident_number?: string | null;
    };
    purpose: string;
    fee: number;
    business_name?: string | null;
    business_address?: string | null;
    additional_details?: string | null;
    details?: CertificateRequestDetailRow[];
    status: string;
    admin_note?: string | null;
    submitted_at: string;
}

interface Props {
    requests?: Paginator<CertificateRequestRow>;
    stats?: {
        total: number;
        byStatus: Record<string, number>;
    };
    statuses?: string[];
    filters?: {
        search?: string;
        status?: string;
    };
}

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const emptyPaginator = <T,>(): Paginator<T> => ({
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
});

const CERTIFICATE_PARTIAL_PROPS = ['requests', 'stats', 'filters'];

const statusStyles: Record<string, string> = {
    Pending: 'bg-[#fff4d8] text-[#8a6700]',
    'Needs Action': 'bg-[#fff0e5] text-[#a65316]',
    'For Payment': 'bg-[#f3ecff] text-[#6f42a5]',
    Processing: 'bg-[#eef3fb] text-[#416b9c]',
    'Ready for Claim': 'bg-[#eef1f5] text-[#3b6fa8]',
    Released: 'bg-gray-100 text-gray-600',
    Rejected: 'bg-[#fdecec] text-[#a33a3a]',
};

function formatPeso(value: number): string {
    return `₱${value.toFixed(2)}`;
}

export default function AdminCertificates(props: Props) {
    return (
        <Deferred
            data={['requests', 'stats']}
            fallback={
                <AdminLayout active="certificates">
                    <AdminPageSkeleton metrics={4} />
                </AdminLayout>
            }
        >
            <CertificatesContent {...props} />
        </Deferred>
    );
}

function CertificatesContent({
    requests,
    stats,
    statuses = [],
    filters,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;

    const paginator: Paginator<CertificateRequestRow> =
        requests ?? emptyPaginator<CertificateRequestRow>();

    // Defensive: only ever render the paginator's data array.
    const rows = Array.isArray(paginator.data) ? paginator.data : [];

    const [filterStatus, setFilterStatus] = useState(filters?.status ?? '');
    const [searchQuery, setSearchQuery] = useState(filters?.search ?? '');

    const lastQueried = useRef((filters?.search ?? '').trim());

    const hasFilters = searchQuery.trim() !== '' || filterStatus !== '';

    /*
     * Server-driven list reload. Only the paginated list, the global
     * statistics, and the echoed filters are requested, so an open modal and
     * the scroll position survive a search, filter, or page change. The status
     * parameter is dropped from the URL entirely when All is selected.
     */
    const reloadRequests = (
        params: { search?: string; status?: string; page?: number } = {},
    ) => {
        const searchValue = (params.search ?? searchQuery).trim();
        const statusValue = params.status ?? filterStatus;

        router.get(
            '/admin/certificates',
            {
                search: searchValue || undefined,
                status: statusValue || undefined,
                page: params.page ?? 1,
            },
            {
                only: CERTIFICATE_PARTIAL_PROPS,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    // Pagination reuses the server-generated links, which already carry the
    // active search and status filters.
    const navigateRequests = (url: string) => {
        router.get(
            url,
            {},
            {
                only: CERTIFICATE_PARTIAL_PROPS,
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    useEffect(() => {
        const trimmed = searchQuery.trim();

        if (trimmed === lastQueried.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastQueried.current = trimmed;

            reloadRequests({ search: trimmed, page: 1 });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [searchQuery]);

    const changeStatus = (value: string) => {
        setFilterStatus(value);
        reloadRequests({ status: value, page: 1 });
    };

    const clearFilters = () => {
        setSearchQuery('');
        setFilterStatus('');
        lastQueried.current = '';

        reloadRequests({ search: '', status: '', page: 1 });
    };

    return (
        <AdminLayout active="certificates">
            <Head title="Certificate Requests" />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {flash.success}
                </div>
            )}

            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Certificate Requests
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Monitor aggregate certificate request activity submitted
                        by residents.
                    </p>
                </div>
            </div>

            <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard label="Total" value={stats?.total ?? 0} highlight />
                <StatCard
                    label="Pending"
                    value={stats?.byStatus?.Pending ?? 0}
                />
                <StatCard
                    label="Processing"
                    value={stats?.byStatus?.Processing ?? 0}
                />
                <StatCard
                    label="Ready for Claim"
                    value={stats?.byStatus?.['Ready for Claim'] ?? 0}
                />
            </div>

            <div className="mb-4 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <input
                    type="search"
                    aria-label="Search certificate requests"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Search by reference, resident, resident no., certificate, or purpose..."
                    className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3b6fa8] sm:w-96"
                />

                <div className="flex items-center gap-2">
                    <label className="text-xs font-medium text-gray-500">
                        Status:
                    </label>
                    <select
                        aria-label="Filter by status"
                        value={filterStatus}
                        onChange={(e) => changeStatus(e.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3b6fa8]"
                    >
                        <option value="">All Statuses</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                        >
                            Clear
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 text-xs text-gray-500">
                {paginator.total === 0
                    ? hasFilters
                        ? 'No requests match the current filters.'
                        : 'No certificate requests found.'
                    : `Showing ${paginator.from ?? 0}–${paginator.to ?? 0} of ${paginator.total} ${
                          hasFilters ? 'matching requests' : 'requests'
                      }`}
            </div>

            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-gray-600">
                        <thead className="border-b border-gray-200 bg-gray-50 text-xs font-semibold text-gray-500 uppercase">
                            <tr>
                                <th className="px-5 py-3">Reference</th>
                                <th className="px-5 py-3">Resident</th>
                                <th className="px-5 py-3">Certificate</th>
                                <th className="px-5 py-3">Fee</th>
                                <th className="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-8 text-center text-sm text-gray-500"
                                    >
                                        {hasFilters
                                            ? 'No requests match the current filters.'
                                            : 'No certificate requests found.'}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((request) => (
                                    <tr
                                        key={request.id}
                                        className="hover:bg-gray-50/80"
                                    >
                                        <td className="px-5 py-3.5 font-mono text-xs font-semibold text-gray-800">
                                            {request.reference}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <p className="font-semibold text-gray-900">
                                                {request.resident.name}
                                            </p>
                                            {request.resident
                                                .resident_number && (
                                                <p className="text-xs text-gray-400">
                                                    {
                                                        request.resident
                                                            .resident_number
                                                    }
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <p className="text-gray-800">
                                                {request.certificate}
                                            </p>
                                            <p className="text-xs text-gray-400">
                                                {request.purpose}
                                            </p>
                                        </td>
                                        <td className="px-5 py-3.5 text-xs text-gray-500">
                                            {formatPeso(request.fee)}
                                        </td>
                                        <td className="px-5 py-3.5">
                                            <span
                                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyles[request.status] ?? 'bg-gray-100 text-gray-600'}`}
                                            >
                                                {request.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    paginator={paginator}
                    onNavigate={navigateRequests}
                />
            </div>
        </AdminLayout>
    );
}

function Pagination<T>({
    paginator,
    onNavigate,
}: {
    paginator: Paginator<T>;
    onNavigate: (url: string) => void;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3">
            <span className="text-xs text-gray-500">
                Page {paginator.current_page} of {paginator.last_page}
            </span>

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={!paginator.prev_page_url}
                    onClick={() =>
                        paginator.prev_page_url &&
                        onNavigate(paginator.prev_page_url)
                    }
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Previous
                </button>

                <button
                    type="button"
                    disabled={!paginator.next_page_url}
                    onClick={() =>
                        paginator.next_page_url &&
                        onNavigate(paginator.next_page_url)
                    }
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Next
                </button>
            </div>
        </div>
    );
}

function StatCard({
    label,
    value,
    highlight = false,
}: {
    label: string;
    value: number;
    highlight?: boolean;
}) {
    return (
        <div
            className={`rounded-lg border p-4 shadow-sm ${highlight ? 'border-[#3b6fa8] bg-[#3b6fa8] text-white' : 'border-gray-200 bg-white'}`}
        >
            <p
                className={`text-[11px] font-medium tracking-wide uppercase ${highlight ? 'text-white/80' : 'text-gray-400'}`}
            >
                {label}
            </p>
            <p
                className={`mt-2 text-2xl font-semibold ${highlight ? 'text-white' : 'text-gray-800'}`}
            >
                {value}
            </p>
        </div>
    );
}
