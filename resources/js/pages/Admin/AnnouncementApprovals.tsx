import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';

interface ApprovalLog {
    id: number;
    action: string;
    notes?: string | null;
    created_at: string;
    actor?: {
        id: number;
        name: string;
    } | null;
}

interface AnnouncementItem {
    id: number;
    title: string;
    content: string;
    category: string;
    target_audience: string;
    approval_status: 'pending' | 'approved' | 'rejected';
    is_published: boolean;
    rejection_reason?: string | null;
    event_start_at?: string | null;
    location?: string | null;
    created_at: string;
    author?: {
        id: number;
        name: string;
        role?: string;
    } | null;
    sector?: {
        id: number;
        name: string;
    } | null;
    reviewer?: {
        id: number;
        name: string;
    } | null;
    approvalLogs?: ApprovalLog[];
}

interface Props {
    announcements: AnnouncementItem[];
}

type TabType = 'pending' | 'approved' | 'rejected' | 'all';

export default function AnnouncementApprovals({ announcements = [] }: Props) {
    const [activeTab, setActiveTab] = useState<TabType>('pending');
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedAnnouncement, setSelectedAnnouncement] = useState<AnnouncementItem | null>(null);
    const [rejectingAnnouncement, setRejectingAnnouncement] = useState<AnnouncementItem | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        rejection_reason: '',
    });

    const pendingCount = announcements.filter((a) => a.approval_status === 'pending').length;
    const approvedCount = announcements.filter((a) => a.approval_status === 'approved').length;
    const rejectedCount = announcements.filter((a) => a.approval_status === 'rejected').length;

    const filteredAnnouncements = announcements.filter((item) => {
        const matchesTab = activeTab === 'all' || item.approval_status === activeTab;
        const matchesSearch =
            item.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
            item.content.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (item.author?.name || '').toLowerCase().includes(searchTerm.toLowerCase());

        return matchesTab && matchesSearch;
    });

    const handleApprove = (id: number) => {
        if (confirm('Are you sure you want to approve and publish this announcement?')) {
            router.post(`/admin/announcements/${id}/approve`);
        }
    };

    const handleRejectSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!rejectingAnnouncement) return;

        post(`/admin/announcements/${rejectingAnnouncement.id}/reject`, {
            onSuccess: () => {
                setRejectingAnnouncement(null);
                reset();
            },
        });
    };

    return (
        <AdminLayout active="announcements">
            <Head title="Announcement Approvals" />

            <div className="mx-auto max-w-7xl space-y-6 font-sans p-2 sm:p-4">
                {/* PAGE HEADER */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            Announcement Approvals
                        </h1>
                        <p className="text-sm text-gray-500">
                            Review, approve, or reject announcements submitted by barangay staff and clerks.
                        </p>
                    </div>

                    {/* SEARCH BAR */}
                    <div className="relative w-full sm:w-72">
                        <input
                            type="text"
                            placeholder="Search announcements..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full rounded-xl border border-gray-300 bg-white py-2 pl-9 pr-4 text-sm outline-none transition focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15"
                        />
                        <span className="absolute left-3 top-2.5 text-gray-400">
                            <SearchIcon />
                        </span>
                    </div>
                </div>

                {/* TABS NAVIGATION */}
                <div className="border-b border-gray-200 bg-white px-4 pt-3 rounded-xl shadow-sm">
                    <nav className="-mb-px flex space-x-6 overflow-x-auto">
                        <button
                            type="button"
                            onClick={() => setActiveTab('pending')}
                            className={`flex items-center gap-2 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition ${
                                activeTab === 'pending'
                                    ? 'border-amber-500 text-amber-700'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <span>Pending Review</span>
                            <span
                                className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                                    activeTab === 'pending'
                                        ? 'bg-amber-100 text-amber-800'
                                        : 'bg-gray-100 text-gray-600'
                                }`}
                            >
                                {pendingCount}
                            </span>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveTab('approved')}
                            className={`flex items-center gap-2 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition ${
                                activeTab === 'approved'
                                    ? 'border-emerald-600 text-emerald-800'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <span>Approved</span>
                            <span
                                className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                                    activeTab === 'approved'
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-gray-100 text-gray-600'
                                }`}
                            >
                                {approvedCount}
                            </span>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveTab('rejected')}
                            className={`flex items-center gap-2 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition ${
                                activeTab === 'rejected'
                                    ? 'border-red-600 text-red-800'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <span>Rejected</span>
                            <span
                                className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                                    activeTab === 'rejected'
                                        ? 'bg-red-100 text-red-800'
                                        : 'bg-gray-100 text-gray-600'
                                }`}
                            >
                                {rejectedCount}
                            </span>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveTab('all')}
                            className={`flex items-center gap-2 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition ${
                                activeTab === 'all'
                                    ? 'border-gray-800 text-gray-900'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            }`}
                        >
                            <span>All Announcements</span>
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-600">
                                {announcements.length}
                            </span>
                        </button>
                    </nav>
                </div>

                {/* ANNOUNCEMENT CARDS LIST */}
                <div className="space-y-4">
                    {filteredAnnouncements.length === 0 ? (
                        <div className="rounded-2xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                            <p className="text-base font-semibold text-gray-700">
                                No announcements found in "{activeTab.toUpperCase()}"
                            </p>
                            <p className="mt-1 text-sm text-gray-500">
                                There are no announcements matching your current filter.
                            </p>
                        </div>
                    ) : (
                        filteredAnnouncements.map((item) => (
                            <div
                                key={item.id}
                                className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition hover:shadow-md"
                            >
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div className="space-y-3 lg:max-w-3xl">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {getStatusBadge(item.approval_status)}

                                            <span className="rounded-md bg-blue-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-blue-700">
                                                {item.category}
                                            </span>

                                            <span className="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-gray-600">
                                                Target: {formatAudience(item.target_audience)}
                                            </span>
                                        </div>

                                        <h2 className="text-xl font-bold text-gray-900">
                                            {item.title}
                                        </h2>

                                        <p className="whitespace-pre-line text-sm leading-relaxed text-gray-600 line-clamp-3">
                                            {item.content}
                                        </p>

                                        {item.event_start_at && (
                                            <div className="inline-flex flex-wrap gap-4 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700">
                                                <span>📅 Schedule: {item.event_start_at}</span>
                                                {item.location && <span>📍 Venue: {item.location}</span>}
                                            </div>
                                        )}

                                        {item.approval_status === 'rejected' && item.rejection_reason && (
                                            <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-800">
                                                <strong>Rejection Reason:</strong> {item.rejection_reason}
                                            </div>
                                        )}

                                        <div className="flex flex-wrap items-center gap-4 text-xs text-gray-400 pt-1">
                                            <span>
                                                Submitted by:{' '}
                                                <strong className="text-gray-700">
                                                    {item.author?.name || 'Staff'}
                                                </strong>
                                            </span>
                                            <span>•</span>
                                            <span>Date: {item.created_at}</span>
                                            {item.reviewer?.name && (
                                                <>
                                                    <span>•</span>
                                                    <span>
                                                        Reviewed by:{' '}
                                                        <strong className="text-gray-700">
                                                            {item.reviewer.name}
                                                        </strong>
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 lg:flex-col lg:items-end lg:justify-start">
                                        {item.approval_status === 'pending' && (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => handleApprove(item.id)}
                                                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 text-xs font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                                                >
                                                    <CheckIcon />
                                                    Approve & Publish
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() => setRejectingAnnouncement(item)}
                                                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border border-red-300 bg-white px-4 text-xs font-semibold text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500/20"
                                                >
                                                    <XIcon />
                                                    Reject
                                                </button>
                                            </>
                                        )}

                                        <button
                                            type="button"
                                            onClick={() => setSelectedAnnouncement(item)}
                                            className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white px-4 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                        >
                                            View Full & Logs
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* REJECT MODAL */}
            {rejectingAnnouncement && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                        <h3 className="text-lg font-bold text-gray-900">
                            Reject Announcement
                        </h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Please provide a clear reason so the clerk can make the necessary revisions.
                        </p>

                        <form onSubmit={handleRejectSubmit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 mb-1">
                                    Rejection Reason / Revision Notes
                                </label>
                                <textarea
                                    rows={4}
                                    required
                                    value={data.rejection_reason}
                                    onChange={(e) => setData('rejection_reason', e.target.value)}
                                    placeholder="e.g. Please clarify the event venue and update the schedule time."
                                    className="w-full rounded-xl border border-gray-300 p-3 text-sm outline-none transition focus:border-red-500 focus:ring-2 focus:ring-red-500/20"
                                />
                                {errors.rejection_reason && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {errors.rejection_reason}
                                    </p>
                                )}
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setRejectingAnnouncement(null);
                                        reset();
                                    }}
                                    className="rounded-xl border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-red-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-red-700 disabled:opacity-50"
                                >
                                    {processing ? 'Rejecting...' : 'Confirm Rejection'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* DETAILS & AUDIT LOGS MODAL */}
            {selectedAnnouncement && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl space-y-5">
                        <div className="flex items-start justify-between">
                            <div>
                                <span className="text-xs font-semibold uppercase text-gray-400">
                                    Announcement Details
                                </span>
                                <h2 className="text-xl font-bold text-gray-900 mt-1">
                                    {selectedAnnouncement.title}
                                </h2>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedAnnouncement(null)}
                                className="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                            >
                                <XIcon />
                            </button>
                        </div>

                        <div className="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 whitespace-pre-line leading-relaxed">
                            {selectedAnnouncement.content}
                        </div>

                        <div className="border-t border-gray-100 pt-4 space-y-3">
                            <h3 className="text-sm font-bold text-gray-900">
                                Approval History & Audit Logs
                            </h3>

                            {selectedAnnouncement.approvalLogs && selectedAnnouncement.approvalLogs.length > 0 ? (
                                <div className="space-y-2">
                                    {selectedAnnouncement.approvalLogs.map((log) => (
                                        <div
                                            key={log.id}
                                            className="rounded-xl border border-gray-200 p-3 text-xs flex justify-between items-start bg-white"
                                        >
                                            <div>
                                                <p className="font-semibold text-gray-800 capitalize">
                                                    Action: {log.action}
                                                </p>
                                                {log.notes && (
                                                    <p className="text-gray-600 mt-0.5">
                                                        Note: {log.notes}
                                                    </p>
                                                )}
                                                <p className="text-gray-400 mt-1">
                                                    By: {log.actor?.name || 'System'}
                                                </p>
                                            </div>
                                            <time className="text-gray-400">
                                                {log.created_at}
                                            </time>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-xs text-gray-400">No logs available.</p>
                            )}
                        </div>

                        <div className="flex justify-end pt-2">
                            <button
                                type="button"
                                onClick={() => setSelectedAnnouncement(null)}
                                className="rounded-xl bg-gray-900 px-5 py-2 text-xs font-semibold text-white transition hover:bg-gray-800"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

function getStatusBadge(status: string) {
    switch (status) {
        case 'approved':
            return (
                <span className="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-emerald-800">
                    Approved & Published
                </span>
            );
        case 'rejected':
            return (
                <span className="inline-flex items-center gap-1 rounded-md bg-red-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-red-800">
                    Rejected
                </span>
            );
        default:
            return (
                <span className="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-amber-800">
                    Pending Review
                </span>
            );
    }
}

function formatAudience(audience: string) {
    if (!audience) return 'All Residents';
    return audience.replace(/_/g, ' ');
}

function SearchIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="2">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="2.5">
            <polyline points="20 6 9 17 4 12" />
        </svg>
    );
}

function XIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="2.5">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    );
}