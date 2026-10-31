import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    formatCharterDate,
    formatFileSize,
    type CitizenCharterServiceData,
    type Paginator,
} from '@/components/citizen-charter/charter';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';

type Props = {
    services: Paginator<CitizenCharterServiceData>;
    categories: string[];
    filters: {
        search: string;
        category: string;
    };
    resident: {
        full_name: string;
    };
};

export default function ResidentCitizenCharter(props: Props) {
    return (
        <Deferred
            data={['services', 'categories']}
            fallback={<ResidentLayout active="charter"><PortalPageSkeleton list /></ResidentLayout>}
        >
            <CitizenCharterContent {...props} />
        </Deferred>
    );
}

function CitizenCharterContent({
    services,
    categories,
    filters,
    resident,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const filterBy = (next: { search?: string; category?: string }) => {
        router.get(
            '/resident/citizen-charter',
            {
                search: next.search !== undefined ? next.search : search,
                category: next.category !== undefined ? next.category : filters.category,
            },
            {
                preserveScroll: true,
                only: ['services', 'filters'],
            }
        );
    };

    const handleSearchSubmit = (e: FormEvent) => {
        e.preventDefault();
        filterBy({ search });
    };

    return (
        <ResidentLayout active="charter">
            <Head title="Citizen's Charter | Barangay Services" />

            <div className="mx-auto w-full max-w-5xl space-y-6">

                {/* TITLE & HEADER */}
                <div>
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Citizen&apos;s Charter
                    </h1>
                    <p className="mt-1 text-sm leading-relaxed text-gray-500">
                        Official barangay service directory, requirements, fees, and procedures.
                    </p>
                </div>

                {/* SEARCH & CATEGORY FILTER */}
                <div className="space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                    <form onSubmit={handleSearchSubmit} className="flex gap-2">
                        <input
                            type="text"
                            placeholder="Search barangay services, procedures..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm focus:border-[#3f6f3d] focus:outline-hidden"
                        />
                        <button
                            type="submit"
                            className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#315b30]"
                        >
                            Search
                        </button>
                    </form>

                    {/* CATEGORY PILLS */}
                    {categories.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5 pt-1">
                            <button
                                type="button"
                                onClick={() => filterBy({ category: '' })}
                                className={`rounded-lg px-3 py-1 text-xs font-semibold transition ${
                                    filters.category === ''
                                        ? 'bg-[#3f6f3d] text-white shadow-xs'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                All Categories
                            </button>
                            {categories.map((cat) => {
                                const active = filters.category === cat;
                                return (
                                    <button
                                        key={cat}
                                        type="button"
                                        onClick={() => filterBy({ category: cat })}
                                        className={`rounded-lg px-3 py-1 text-xs font-semibold transition ${
                                            active
                                                ? 'bg-[#3f6f3d] text-white shadow-xs'
                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                        }`}
                                    >
                                        {cat}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* SERVICE CARDS */}
                {services.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-gray-300 bg-white p-12 text-center">
                        <div className="text-3xl">📋</div>
                        <h3 className="mt-3 text-base font-bold text-gray-800">
                            No Published Services Available
                        </h3>
                        <p className="mt-1 text-xs text-gray-500">
                            {filters.search || filters.category
                                ? 'No services match your search criteria. Try clearing the filter.'
                                : 'The barangay has not published any Citizen\'s Charter services yet.'}
                        </p>
                        {(filters.search || filters.category) && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSearch('');
                                    filterBy({ search: '', category: '' });
                                }}
                                className="mt-4 rounded-lg bg-gray-100 px-3.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-200"
                            >
                                Clear Filters
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {services.data.map((service) => (
                            <div
                                key={service.id}
                                className="flex flex-col justify-between rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow-md"
                            >
                                <div>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-700 border border-emerald-200">
                                            {service.category || 'General Service'}
                                        </span>
                                        {service.current_version && (
                                            <span className="text-[10px] text-gray-400">
                                                {formatFileSize(service.current_version.file_size)} PDF
                                            </span>
                                        )}
                                    </div>

                                    <h2 className="mt-3 text-base font-bold text-gray-900 line-clamp-2">
                                        {service.title}
                                    </h2>

                                    {service.description && (
                                        <p className="mt-2 text-xs leading-relaxed text-gray-500 line-clamp-3">
                                            {service.description}
                                        </p>
                                    )}
                                </div>

                                <div className="mt-5 border-t border-gray-100 pt-4 flex items-center justify-between">
                                    <span className="text-[10px] text-gray-400">
                                        Published: {formatCharterDate(service.published_at)}
                                    </span>

                                    <div className="flex items-center gap-2">
                                        {service.download_url && (
                                            <a
                                                href={service.download_url}
                                                className="rounded-lg border border-gray-300 p-2 text-gray-600 transition hover:bg-gray-100"
                                                title="Download PDF"
                                            >
                                                ↓
                                            </a>
                                        )}
                                        <Link
                                            href={service.detail_url || `/resident/citizen-charter/${service.id}`}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#3f6f3d] px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-[#315b30]"
                                        >
                                            <span>View Details</span>
                                            <span>→</span>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* PAGINATION */}
                {services.last_page > 1 && (
                    <div className="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs text-gray-600 shadow-sm">
                        <div>
                            Showing <span className="font-semibold">{services.from}</span> to{' '}
                            <span className="font-semibold">{services.to}</span> of{' '}
                            <span className="font-semibold">{services.total}</span> services
                        </div>
                        <div className="flex gap-1">
                            {services.links.map((link, idx) => (
                                <button
                                    key={idx}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true, only: ['services', 'filters'] })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                    className={`rounded-md px-2.5 py-1 text-xs font-medium transition ${
                                        link.active
                                            ? 'bg-[#3f6f3d] text-white'
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
        </ResidentLayout>
    );
}
