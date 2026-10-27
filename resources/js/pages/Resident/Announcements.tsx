import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

interface AnnouncementItem {
    id: number;
    title: string;
    content: string;
    category: string;
    importance?: string | null;
    target_audience: string;
    type?: 'event' | 'informational';
    event_start_at?: string | null;
    location?: string | null;
    created_at: string;
    author?: { name: string };
}

interface Filters {
    q: string;
    category: string;
    importance: string;
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    announcements: AnnouncementItem[];
    categories: string[];
    filters?: Filters;
    pagination?: Pagination;
    kind?: 'announcements' | 'updates';
    period?: 'current' | 'past';
}

const emptyFilters: Filters = {
    q: '',
    category: '',
    importance: '',
};

const emptyPagination: Pagination = {
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: 0,
    from: null,
    to: null,
};

const importanceOptions = [
    { value: 'urgent', label: 'Urgent' },
    { value: 'high', label: 'High' },
    { value: 'normal', label: 'Normal' },
    { value: 'low', label: 'Low' },
];

export default function Announcements({
    announcements,
    categories,
    filters = emptyFilters,
    pagination = emptyPagination,
    kind = 'announcements',
    period = 'current',
}: Props) {
    const isUpdates = kind === 'updates';
    const basePath = isUpdates ? '/resident/updates' : '/resident/announcements';
    const [query, setQuery] = useState(filters.q);
    const [category, setCategory] = useState(filters.category);
    const [importance, setImportance] = useState(filters.importance);
    const [filtering, setFiltering] = useState(false);

    const hasActiveFilters = Boolean(
        filters.q || filters.category || filters.importance,
    );

    useEffect(() => {
        setQuery(filters.q);
        setCategory(filters.category);
        setImportance(filters.importance);
    }, [filters.q, filters.category, filters.importance]);

    const visit = (next: Filters, page = 1, nextPeriod = period) => {
        const params: Record<string, string> = {};

        if (next.q.trim()) {
            params.q = next.q.trim();
        }

        if (next.category) {
            params.category = next.category;
        }

        if (next.importance) {
            params.importance = next.importance;
        }

        if (page > 1) {
            params.page = String(page);
        }

        if (isUpdates) {
            params.period = nextPeriod;
        }

        router.get(basePath, params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => setFiltering(true),
            onFinish: () => setFiltering(false),
        });
    };

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();

        visit({
            q: query,
            category,
            importance,
        });
    };

    const handleCategoryChange = (value: string) => {
        setCategory(value);

        visit(
            {
                q: query,
                category: value,
                importance,
            },
            1,
        );
    };

    const handleImportanceChange = (value: string) => {
        setImportance(value);

        visit(
            {
                q: query,
                category,
                importance: value,
            },
            1,
        );
    };

    const clearFilters = () => {
        setQuery('');
        setCategory('');
        setImportance('');

        router.get(
            basePath,
            isUpdates ? { period } : {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onStart: () => setFiltering(true),
                onFinish: () => setFiltering(false),
            },
        );
    };

    const goToPage = (page: number) => {
        visit(filters, page);
    };

    const pages = getVisiblePages(
        pagination.current_page,
        pagination.last_page,
    );

    return (
        <ResidentLayout active={isUpdates ? 'updates' : 'announcements'}>
            <Head title={isUpdates ? (period === 'past' ? 'Past Updates' : 'Current Updates') : 'Barangay Updates'} />

            <div className="mx-auto max-w-4xl space-y-6">
                {/* HEADER SECTION */}
                <div className="border-b border-gray-200 pb-5">
                    <h1 className="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
                        {isUpdates
                            ? period === 'past' ? 'Past Updates' : 'Current Updates'
                            : 'Barangay Updates & Advisories'}
                    </h1>
                    <p className="mt-2 text-sm text-gray-500 sm:text-base">
                        {isUpdates
                            ? period === 'past'
                                ? 'Browse completed informational updates from your barangay.'
                                : 'Official informational updates currently in effect.'
                            : 'Stay updated with official news, events, and community distribution schedules.'}
                    </p>
                </div>

                {isUpdates && (
                    <nav aria-label="Update history" className="flex gap-2">
                        <Link
                            href="/resident/updates?period=current"
                            className={`rounded-full px-4 py-2 text-sm font-semibold ${period === 'current' ? 'bg-[#2f5e3a] text-white' : 'bg-white text-gray-600 hover:bg-gray-100'}`}
                        >
                            Current Updates
                        </Link>
                        <Link
                            href="/resident/updates?period=past"
                            className={`rounded-full px-4 py-2 text-sm font-semibold ${period === 'past' ? 'bg-[#2f5e3a] text-white' : 'bg-white text-gray-600 hover:bg-gray-100'}`}
                        >
                            Past Updates
                        </Link>
                    </nav>
                )}

                {/* SEARCH & FILTERS */}
                <section
                    aria-label={isUpdates ? 'Search and filter updates' : 'Search and filter announcements'}
                    className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                >
                    <form onSubmit={handleSubmit}>
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
                            <div className="min-w-0 flex-1">
                                <label
                                    htmlFor="announcement-search"
                                    className="mb-1.5 block text-xs font-bold text-gray-700"
                                >
                                    {isUpdates ? 'Search updates' : 'Search announcements'}
                                </label>

                                <div className="relative">
                                    <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                        <SearchIcon />
                                    </span>

                                    <input
                                        id="announcement-search"
                                        type="search"
                                        value={query}
                                        maxLength={100}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder="Search by title or content"
                                        className="h-11 w-full rounded-xl border border-gray-300 bg-white py-2 pr-3 pl-10 text-sm text-gray-800 transition outline-none focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3 lg:w-[340px]">
                                <div>
                                    <label
                                        htmlFor="announcement-category"
                                        className="mb-1.5 block text-xs font-bold text-gray-700"
                                    >
                                        Category
                                    </label>

                                    <select
                                        id="announcement-category"
                                        value={category}
                                        onChange={(event) =>
                                            handleCategoryChange(
                                                event.target.value,
                                            )
                                        }
                                        className="h-11 w-full rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-800 transition outline-none focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15"
                                    >
                                        <option value="">All categories</option>

                                        {categories.map((item) => (
                                            <option key={item} value={item}>
                                                {formatCategoryLabel(item)}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label
                                        htmlFor="announcement-importance"
                                        className="mb-1.5 block text-xs font-bold text-gray-700"
                                    >
                                        Importance
                                    </label>

                                    <select
                                        id="announcement-importance"
                                        value={importance}
                                        onChange={(event) =>
                                            handleImportanceChange(
                                                event.target.value,
                                            )
                                        }
                                        className="h-11 w-full rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-800 transition outline-none focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15"
                                    >
                                        <option value="">All importance</option>

                                        {importanceOptions.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div className="flex shrink-0 gap-2">
                                <button
                                    type="submit"
                                    disabled={filtering}
                                    className="inline-flex h-11 items-center justify-center rounded-xl bg-[#3f6f3d] px-4 text-sm font-semibold text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {filtering ? 'Searching…' : 'Search'}
                                </button>

                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        disabled={filtering}
                                        className="inline-flex h-11 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                        </div>
                    </form>
                </section>

                {/* RESULTS SUMMARY */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs font-medium text-gray-500 sm:text-sm">
                        {pagination.total > 0
                            ? `Showing ${pagination.from}–${pagination.to} of ${pagination.total} ${isUpdates ? 'update' : 'announcement'}${pagination.total === 1 ? '' : 's'}`
                            : `No ${isUpdates ? period === 'past' ? 'past updates' : 'current updates' : 'announcements'} found`}
                    </p>

                    {hasActiveFilters && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="text-xs font-semibold text-[#3f6f3d] hover:text-[#315b30] sm:text-sm"
                        >
                            Reset search and filters
                        </button>
                    )}
                </div>

                {/* ANNOUNCEMENTS LIST */}
                <div className="space-y-4">
                    {announcements.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-[#edf4ea] text-[#3f6f3d]">
                                {hasActiveFilters ? (
                                    <SearchIcon />
                                ) : (
                                    <MegaphoneIcon />
                                )}
                            </div>
                            <h3 className="mt-4 text-base font-semibold text-gray-900">
                                {hasActiveFilters
                                    ? 'No announcements match your search'
                                    : isUpdates
                                      ? period === 'past' ? 'No past updates' : 'No current updates'
                                      : 'No active announcements'}
                            </h3>
                            <p className="mt-1 max-w-md text-sm text-gray-500">
                                {hasActiveFilters
                                    ? 'Try a different keyword, category, or importance level.'
                                    : 'Please check back later for official updates from your barangay.'}
                            </p>

                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="mt-4 rounded-lg bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#315b30]"
                                >
                                    Clear search and filters
                                </button>
                            )}
                        </div>
                    ) : (
                        announcements.map((item) => (
                            <article
                                key={item.id}
                                className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#cfddca] hover:shadow-md sm:p-6"
                            >
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold tracking-wider uppercase ${getCategoryBadgeStyle(item.category)}`}
                                        >
                                            {formatCategoryLabel(item.category)}
                                        </span>

                                        {item.importance &&
                                            item.importance !== 'normal' && (
                                                <span
                                                    className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold tracking-wider uppercase ${getImportanceBadgeStyle(item.importance)}`}
                                                >
                                                    {formatImportanceLabel(
                                                        item.importance,
                                                    )}
                                                </span>
                                            )}

                                        {item.target_audience &&
                                            item.target_audience !== 'all' && (
                                                <span className="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1 text-xs font-semibold tracking-wider text-gray-600 uppercase">
                                                    {formatAudienceLabel(
                                                        item.target_audience,
                                                    )}
                                                </span>
                                            )}
                                    </div>

                                    <time className="text-xs font-medium text-gray-400">
                                        {formatDate(item.created_at)}
                                    </time>
                                </div>

                                <h2 className="text-lg font-bold text-gray-900 sm:text-xl">
                                    <Link
                                        href={`${basePath}/${item.id}${isUpdates ? `?period=${period}` : ''}`}
                                        className="hover:text-[#3f6f3d]"
                                    >
                                        {item.title}
                                    </Link>
                                </h2>

                                <p className="mt-2.5 text-sm leading-relaxed whitespace-pre-line text-gray-600 sm:text-base">
                                    {item.content}
                                </p>

                                {item.event_start_at && (
                                    <div className="mt-4 flex flex-col gap-2 rounded-xl border border-[#d6e3d2] bg-[#f7faf6] p-3.5 text-xs font-medium text-gray-700 sm:flex-row sm:items-center sm:gap-6 sm:text-sm">
                                        <div className="flex items-center gap-2 text-[#315b30]">
                                            <CalendarIcon />
                                            <span>
                                                <strong>Schedule:</strong>{' '}
                                                {formatDateTime(
                                                    item.event_start_at,
                                                )}
                                            </span>
                                        </div>

                                        {item.location && (
                                            <div className="flex items-center gap-2 text-[#315b30]">
                                                <LocationIcon />
                                                <span>
                                                    <strong>Venue:</strong>{' '}
                                                    {item.location}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="mt-4 flex items-center justify-between gap-3">
                                    {item.author?.name ? (
                                        <p className="text-xs text-gray-400">
                                            Posted by:{' '}
                                            <span className="font-medium text-gray-600">
                                                {item.author.name}
                                            </span>
                                        </p>
                                    ) : (
                                        <span />
                                    )}

                                    <Link
                                        href={`${basePath}/${item.id}${isUpdates ? `?period=${period}` : ''}`}
                                        className="shrink-0 text-xs font-semibold text-[#3f6f3d] hover:text-[#315b30]"
                                    >
                                        View details
                                    </Link>
                                </div>
                            </article>
                        ))
                    )}
                </div>

                {/* PAGINATION */}
                {pagination.last_page > 1 && (
                    <nav
                        aria-label="Announcement pages"
                        className="flex flex-wrap items-center justify-center gap-1.5 pt-2"
                    >
                        <button
                            type="button"
                            onClick={() =>
                                goToPage(pagination.current_page - 1)
                            }
                            disabled={filtering || pagination.current_page <= 1}
                            className="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 sm:text-sm"
                        >
                            Previous
                        </button>

                        {pages.map((page, index) =>
                            page === 'ellipsis' ? (
                                <span
                                    key={`ellipsis-${index}`}
                                    className="px-1.5 text-sm text-gray-400"
                                >
                                    …
                                </span>
                            ) : (
                                <button
                                    key={page}
                                    type="button"
                                    onClick={() => goToPage(page)}
                                    disabled={filtering}
                                    aria-current={
                                        page === pagination.current_page
                                            ? 'page'
                                            : undefined
                                    }
                                    className={`flex h-10 min-w-10 items-center justify-center rounded-lg border px-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm ${
                                        page === pagination.current_page
                                            ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                    }`}
                                >
                                    {page}
                                </button>
                            ),
                        )}

                        <button
                            type="button"
                            onClick={() =>
                                goToPage(pagination.current_page + 1)
                            }
                            disabled={
                                filtering ||
                                pagination.current_page >= pagination.last_page
                            }
                            className="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 sm:text-sm"
                        >
                            Next
                        </button>
                    </nav>
                )}
            </div>
        </ResidentLayout>
    );
}

/* HELPER FUNCTIONS */

function getVisiblePages(
    currentPage: number,
    lastPage: number,
): Array<number | 'ellipsis'> {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    const start = Math.max(1, Math.min(currentPage - 2, lastPage - 4));
    const end = Math.min(lastPage, Math.max(currentPage + 2, 5));
    const pages: Array<number | 'ellipsis'> = [];

    if (start > 1) {
        pages.push(1);

        if (start > 2) {
            pages.push('ellipsis');
        }
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (end < lastPage) {
        if (end < lastPage - 1) {
            pages.push('ellipsis');
        }

        pages.push(lastPage);
    }

    return pages;
}

function getCategoryBadgeStyle(category: string) {
    const cat = category?.toLowerCase() || '';

    if (
        cat.includes('emergency') ||
        cat.includes('urgent') ||
        cat.includes('advisory')
    ) {
        return 'bg-red-100 text-red-800';
    }

    if (cat.includes('event') || cat.includes('activity')) {
        return 'bg-purple-100 text-purple-800';
    }

    if (
        cat.includes('aid') ||
        cat.includes('distribution') ||
        cat.includes('ayuda') ||
        cat.includes('financial')
    ) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-[#e8f1e4] text-[#3f6f3d]';
}

function getImportanceBadgeStyle(importance: string) {
    switch (importance) {
        case 'urgent':
            return 'bg-red-100 text-red-800';
        case 'high':
            return 'bg-amber-100 text-amber-800';
        case 'low':
            return 'bg-gray-100 text-gray-600';
        default:
            return 'bg-[#e8f1e4] text-[#3f6f3d]';
    }
}

function formatCategoryLabel(category: string) {
    if (!category) return 'General';

    return category.replace(/_/g, ' ');
}

function formatImportanceLabel(importance: string) {
    return importance.charAt(0).toUpperCase() + importance.slice(1);
}

function formatAudienceLabel(audience: string) {
    switch (audience) {
        case 'senior_citizens':
            return 'Senior Citizens';
        case 'pwd':
            return 'PWD';
        case 'solo_parents':
            return 'Solo Parents';
        default:
            return audience.replace(/_/g, ' ');
    }
}

function formatDate(dateString: string) {
    if (!dateString) return '';

    try {
        const date = new Date(dateString);

        if (isNaN(date.getTime())) return dateString;

        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    } catch {
        return dateString;
    }
}

function formatDateTime(dateString: string) {
    if (!dateString) return '';

    try {
        const date = new Date(dateString);

        if (isNaN(date.getTime())) return dateString;

        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });
    } catch {
        return dateString;
    }
}

/* ICONS */

function MegaphoneIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M4 13V9a2 2 0 0 1 2-2h3l8-3v14l-8-3H6a2 2 0 0 1-2-2Z" />
            <path d="M8 15l1 5h3l-1-5" />
            <path d="M20 8v6" />
        </svg>
    );
}

function SearchIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

function CalendarIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4 shrink-0"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect width="18" height="18" x="3" y="4" rx="2" ry="2" />
            <line x1="16" x2="16" y1="2" y2="6" />
            <line x1="8" x2="8" y1="2" y2="6" />
            <line x1="3" x2="21" y1="10" y2="10" />
        </svg>
    );
}

function LocationIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4 shrink-0"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
            <circle cx="12" cy="10" r="3" />
        </svg>
    );
}
