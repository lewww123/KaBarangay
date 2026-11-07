import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    charterStatusBadge,
    formatCharterDate,
    type CitizenCharterServiceData,
    type Paginator,
} from '@/components/citizen-charter/charter';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Props = {
    services: Paginator<CitizenCharterServiceData>;
    filters: {
        search: string;
        status: string;
    };
    stats: {
        total: number;
        draft: number;
        published: number;
        archived: number;
    };
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

type CreateForm = {
    title: string;
    category: string;
    description: string;
    display_order: string;
    document: File | null;
};

const STATUS_TABS: {
    value: string;
    label: string;
    countKey: keyof Props['stats'];
}[] = [
    { value: 'all', label: 'All Services', countKey: 'total' },
    { value: 'draft', label: 'Drafts', countKey: 'draft' },
    { value: 'published', label: 'Published', countKey: 'published' },
    { value: 'archived', label: 'Archived', countKey: 'archived' },
];

export default withDeferredPage(
    ClerkCitizenCharterIndex,
    ['services', 'stats'],
    (props) => (
        <ClerkLayout active="citizen-charter" user={props.user}>
            <PortalPageSkeleton metrics={4} />
        </ClerkLayout>
    ),
);

function ClerkCitizenCharterIndex({ services, filters, stats, user }: Props) {
    const page = usePage<{ flash?: { success?: string; error?: string } }>();
    const flash = page.props.flash;

    const [search, setSearch] = useState(filters.search);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<CreateForm>({
        title: '',
        category: '',
        description: '',
        display_order: '0',
        document: null,
    });

    const visit = (next: Partial<Props['filters']>, preserveState = true) => {
        router.get(
            '/clerk/citizen-charter',
            {
                search: next.search ?? search,
                status: next.status ?? filters.status,
            },
            {
                preserveScroll: true,
                preserveState,
                only: ['services', 'filters'],
            },
        );
    };

    const handleSearchSubmit = (e: FormEvent) => {
        e.preventDefault();
        visit({ search }, false);
    };

    const handleCreateSubmit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/clerk/citizen-charter', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
                setShowCreateModal(false);
            },
        });
    };

    return (
        <ClerkLayout active="citizen-charter" user={user}>
            <Head title="Citizen's Charter Management | Clerk Portal" />

            <div className="mx-auto max-w-6xl space-y-6">
                {/* HEADER */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                            Citizen&apos;s Charter Directory
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Upload, publish, and manage official barangay
                            service standards and procedures.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => setShowCreateModal(true)}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#3b6fa8] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#2a5279]"
                    >
                        <span>➕</span>
                        <span>Add New Service</span>
                    </button>
                </div>

                {/* FLASH NOTICES */}
                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                        {flash.error}
                    </div>
                )}

                {/* STATS TILES */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div className="text-xs font-medium tracking-wider text-gray-400 uppercase">
                            Total Services
                        </div>
                        <div className="mt-2 text-2xl font-bold text-gray-900">
                            {stats.total}
                        </div>
                    </div>
                    <div className="rounded-xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm">
                        <div className="text-xs font-medium tracking-wider text-blue-600 uppercase">
                            Draft Revisions
                        </div>
                        <div className="mt-2 text-2xl font-bold text-blue-900">
                            {stats.draft}
                        </div>
                    </div>
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
                        <div className="text-xs font-medium tracking-wider text-emerald-600 uppercase">
                            Published Live
                        </div>
                        <div className="mt-2 text-2xl font-bold text-emerald-900">
                            {stats.published}
                        </div>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 shadow-sm">
                        <div className="text-xs font-medium tracking-wider text-gray-500 uppercase">
                            Archived
                        </div>
                        <div className="mt-2 text-2xl font-bold text-gray-700">
                            {stats.archived}
                        </div>
                    </div>
                </div>

                {/* CONTROLS (TABS & SEARCH) */}
                <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    {/* STATUS TABS */}
                    <div className="flex flex-wrap gap-1 border-b border-gray-100 pb-2 sm:border-b-0 sm:pb-0">
                        {STATUS_TABS.map((tab) => {
                            const isCurrent = filters.status === tab.value;
                            return (
                                <button
                                    key={tab.value}
                                    type="button"
                                    onClick={() => visit({ status: tab.value })}
                                    className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                        isCurrent
                                            ? 'bg-[#3b6fa8] text-white shadow-xs'
                                            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
                                    }`}
                                >
                                    <span>{tab.label}</span>
                                    <span
                                        className={`py-0.2 rounded-full px-1.5 text-[10px] ${
                                            isCurrent
                                                ? 'bg-white/25 text-white'
                                                : 'bg-gray-100 text-gray-600'
                                        }`}
                                    >
                                        {stats[tab.countKey]}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {/* SEARCH INPUT */}
                    <form
                        onSubmit={handleSearchSubmit}
                        className="flex items-center gap-2 sm:w-72"
                    >
                        <input
                            type="text"
                            placeholder="Search service title, category..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                        />
                        <button
                            type="submit"
                            className="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200"
                        >
                            Filter
                        </button>
                    </form>
                </div>

                {/* SERVICE LIST TABLE */}
                <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    {services.data.length === 0 ? (
                        <div className="p-12 text-center">
                            <div className="text-3xl">📋</div>
                            <h3 className="mt-3 text-sm font-bold text-gray-800">
                                No Citizen&apos;s Charter services found
                            </h3>
                            <p className="mt-1 text-xs text-gray-500">
                                {filters.search || filters.status !== 'all'
                                    ? 'Try adjusting your search query or status filter.'
                                    : 'Get started by adding an official service and uploading its corresponding PDF.'}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b border-gray-200 bg-gray-50 text-[11px] font-semibold tracking-wider text-gray-500 uppercase">
                                    <tr>
                                        <th className="px-4 py-3">
                                            Service Title
                                        </th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Version</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3">
                                            Published Date
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 text-gray-700">
                                    {services.data.map((service) => {
                                        const badge = charterStatusBadge(
                                            service.status,
                                        );
                                        const versionNum =
                                            service.current_version
                                                ?.version_number ?? 1;

                                        return (
                                            <tr
                                                key={service.id}
                                                className="transition hover:bg-gray-50/70"
                                            >
                                                <td className="px-4 py-3.5">
                                                    <Link
                                                        href={
                                                            service.show_url ||
                                                            `/clerk/citizen-charter/${service.id}`
                                                        }
                                                        className="font-bold text-gray-900 hover:text-[#3b6fa8]"
                                                    >
                                                        {service.title}
                                                    </Link>
                                                    {service.description && (
                                                        <p className="mt-0.5 line-clamp-1 max-w-md text-[11px] text-gray-400">
                                                            {
                                                                service.description
                                                            }
                                                        </p>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-gray-600">
                                                    {service.category || (
                                                        <span className="text-gray-400">
                                                            Uncategorized
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-700">
                                                        v{versionNum}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <span
                                                        className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${badge.classes}`}
                                                    >
                                                        {badge.label}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3.5 text-gray-500">
                                                    {formatCharterDate(
                                                        service.published_at,
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-right">
                                                    <Link
                                                        href={
                                                            service.show_url ||
                                                            `/clerk/citizen-charter/${service.id}`
                                                        }
                                                        className="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 transition hover:bg-gray-100"
                                                    >
                                                        <span>Manage</span>
                                                        <span>→</span>
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* PAGINATION */}
                    {services.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-gray-200 bg-gray-50/50 px-4 py-3 text-xs text-gray-600">
                            <div>
                                Showing{' '}
                                <span className="font-semibold">
                                    {services.from}
                                </span>{' '}
                                to{' '}
                                <span className="font-semibold">
                                    {services.to}
                                </span>{' '}
                                of{' '}
                                <span className="font-semibold">
                                    {services.total}
                                </span>{' '}
                                services
                            </div>
                            <div className="flex gap-1">
                                {services.links.map((link, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    only: [
                                                        'services',
                                                        'filters',
                                                    ],
                                                },
                                            )
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                        className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${
                                            link.active
                                                ? 'bg-[#3b6fa8] text-white'
                                                : link.url
                                                  ? 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-100'
                                                  : 'cursor-not-allowed text-gray-300'
                                        }`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* CREATE SERVICE MODAL */}
            {showCreateModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between border-b border-gray-200 pb-3">
                            <h2 className="text-base font-bold text-gray-900">
                                Add Citizen&apos;s Charter Service
                            </h2>
                            <button
                                type="button"
                                onClick={() => setShowCreateModal(false)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form
                            onSubmit={handleCreateSubmit}
                            className="mt-4 space-y-4"
                        >
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Service Title{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Issuance of Barangay Clearance"
                                    value={form.data.title}
                                    onChange={(e) =>
                                        form.setData('title', e.target.value)
                                    }
                                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                />
                                {form.errors.title && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Category (Optional)
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="e.g. Certificates, Social Services"
                                        value={form.data.category}
                                        onChange={(e) =>
                                            form.setData(
                                                'category',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                    />
                                    {form.errors.category && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {form.errors.category}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Display Order
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        value={form.data.display_order}
                                        onChange={(e) =>
                                            form.setData(
                                                'display_order',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                    />
                                    {form.errors.display_order && (
                                        <p className="mt-1 text-xs text-red-600">
                                            {form.errors.display_order}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Description / Summary (Optional)
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="Short summary of who may avail and what this service covers..."
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                />
                                {form.errors.description && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.description}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Official Service Document (PDF only){' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    required
                                    accept=".pdf,application/pdf"
                                    onChange={(e) =>
                                        form.setData(
                                            'document',
                                            e.target.files?.[0] || null,
                                        )
                                    }
                                    className="mt-1 block w-full text-xs text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-[#eaf1f8] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-[#3b6fa8] hover:file:bg-[#d6e4f2]"
                                />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    PDF file only. Max 2 MB per runtime upload
                                    limit. Scanned PDFs supported.
                                </p>
                                {form.errors.document && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.document}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-2 border-t border-gray-200 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setShowCreateModal(false)}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-100"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-lg bg-[#3b6fa8] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#2a5279] disabled:opacity-50"
                                >
                                    {form.processing
                                        ? 'Saving...'
                                        : 'Save Service as Draft'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClerkLayout>
    );
}
