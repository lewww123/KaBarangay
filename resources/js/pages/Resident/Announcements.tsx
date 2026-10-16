import { Head } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';

interface AnnouncementItem {
    id: number;
    title: string;
    content: string;
    category: string;
    target_audience: string;
    event_start_at?: string | null;
    location?: string | null;
    created_at: string;
    author?: { name: string };
}

interface Props {
    announcements: AnnouncementItem[];
}

export default function Announcements({ announcements = [] }: Props) {
    return (
        <ResidentLayout active="announcements">
            <Head title="Barangay Announcements" />

            <div className="mx-auto max-w-4xl space-y-6">
                {/* HEADER SECTION */}
                <div className="border-b border-gray-200 pb-5">
                    <h1 className="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
                        Barangay Announcements & Advisories
                    </h1>
                    <p className="mt-2 text-sm text-gray-500 sm:text-base">
                        Stay updated with official news, events, and community distribution schedules.
                    </p>
                </div>

                {/* ANNOUNCEMENTS LIST */}
                <div className="space-y-4">
                    {announcements.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-[#edf4ea] text-[#3f6f3d]">
                                <MegaphoneIcon />
                            </div>
                            <h3 className="mt-4 text-base font-semibold text-gray-900">
                                No active announcements
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Please check back later for official updates from your barangay.
                            </p>
                        </div>
                    ) : (
                        announcements.map((item) => (
                            <article
                                key={item.id}
                                className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#cfddca] hover:shadow-md sm:p-6"
                            >
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold uppercase tracking-wider ${getCategoryBadgeStyle(item.category)}`}>
                                            {formatCategoryLabel(item.category)}
                                        </span>

                                        {item.target_audience && item.target_audience !== 'all' && (
                                            <span className="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                {formatAudienceLabel(item.target_audience)}
                                            </span>
                                        )}
                                    </div>

                                    <time className="text-xs font-medium text-gray-400">
                                        {formatDate(item.created_at)}
                                    </time>
                                </div>

                                <h2 className="text-lg font-bold text-gray-900 sm:text-xl">
                                    {item.title}
                                </h2>

                                <p className="mt-2.5 whitespace-pre-line text-sm leading-relaxed text-gray-600 sm:text-base">
                                    {item.content}
                                </p>

                                {item.event_start_at && (
                                    <div className="mt-4 flex flex-col gap-2 rounded-xl border border-[#d6e3d2] bg-[#f7faf6] p-3.5 text-xs font-medium text-gray-700 sm:flex-row sm:items-center sm:gap-6 sm:text-sm">
                                        <div className="flex items-center gap-2 text-[#315b30]">
                                            <CalendarIcon />
                                            <span>
                                                <strong>Schedule:</strong> {formatDateTime(item.event_start_at)}
                                            </span>
                                        </div>

                                        {item.location && (
                                            <div className="flex items-center gap-2 text-[#315b30]">
                                                <LocationIcon />
                                                <span>
                                                    <strong>Venue:</strong> {item.location}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {item.author?.name && (
                                    <p className="mt-3 text-xs text-gray-400">
                                        Posted by: <span className="font-medium text-gray-600">{item.author.name}</span>
                                    </p>
                                )}
                            </article>
                        ))
                    )}
                </div>
            </div>
        </ResidentLayout>
    );
}

/* HELPER FUNCTIONS */

function getCategoryBadgeStyle(category: string) {
    const cat = category?.toLowerCase() || '';
    if (cat.includes('emergency') || cat.includes('urgent') || cat.includes('advisory')) {
        return 'bg-red-100 text-red-800';
    }
    if (cat.includes('event') || cat.includes('activity')) {
        return 'bg-purple-100 text-purple-800';
    }
    if (cat.includes('aid') || cat.includes('distribution') || cat.includes('ayuda') || cat.includes('financial')) {
        return 'bg-amber-100 text-amber-800';
    }
    return 'bg-[#e8f1e4] text-[#3f6f3d]';
}

function formatCategoryLabel(category: string) {
    if (!category) return 'General';
    return category.replace(/_/g, ' ');
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
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4 13V9a2 2 0 0 1 2-2h3l8-3v14l-8-3H6a2 2 0 0 1-2-2Z" />
            <path d="M8 15l1 5h3l-1-5" />
            <path d="M20 8v6" />
        </svg>
    );
}

function CalendarIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4 shrink-0" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <rect width="18" height="18" x="3" y="4" rx="2" ry="2" />
            <line x1="16" x2="16" y1="2" y2="6" />
            <line x1="8" x2="8" y1="2" y2="6" />
            <line x1="3" x2="21" y1="10" y2="10" />
        </svg>
    );
}

function LocationIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4 shrink-0" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
            <circle cx="12" cy="10" r="3" />
        </svg>
    );
}