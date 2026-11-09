import { Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import type { SectorApplicationItem, SectorType } from './types';

interface Props {
    sector: SectorType;
    applications: SectorApplicationItem[];
    newApplicationUrl: string;
    detailsBaseUrl: string;
}

export default function SectorApplicationList({
    sector,
    applications,
    newApplicationUrl,
    detailsBaseUrl,
}: Props) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<string>('all');

    const sectorTitle = sector === 'senior' ? 'Senior Citizens' : 'PWD Sector';

    const filteredApplications = useMemo(() => {
        return applications.filter((app) => {
            const matchesStatus =
                statusFilter === 'all' || app.status === statusFilter;
            const term = search.toLowerCase().trim();
            const matchesSearch =
                !term ||
                app.applicant_name.toLowerCase().includes(term) ||
                app.resident_number.toLowerCase().includes(term) ||
                app.tracking_number.toLowerCase().includes(term);

            return matchesStatus && matchesSearch;
        });
    }, [applications, search, statusFilter]);

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'pending_staff_review':
                return (
                    <span className="inline-flex items-center rounded-lg bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800 uppercase">
                        Pending Staff Review
                    </span>
                );
            case 'under_admin_review':
                return (
                    <span className="inline-flex items-center rounded-lg bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-800 uppercase">
                        Legacy Forwarded
                    </span>
                );
            case 'approved':
                return (
                    <span className="inline-flex items-center rounded-lg bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 uppercase">
                        Approved
                    </span>
                );
            case 'rejected':
                return (
                    <span className="inline-flex items-center rounded-lg bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-800 uppercase">
                        Rejected
                    </span>
                );
            case 'requires_correction':
            case 'needs_correction':
                return (
                    <span className="inline-flex items-center rounded-lg bg-purple-100 px-2 py-0.5 text-[10px] font-semibold text-purple-800 uppercase">
                        Needs Correction
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center rounded-lg bg-secondary px-2 py-0.5 text-[10px] font-semibold text-ink uppercase">
                        {status}
                    </span>
                );
        }
    };

    return (
        <div className="space-y-6">
            {/* HEADER */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-ink-strong">
                        {sectorTitle} Applications
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Track, verify, and decide sector membership
                        applications.
                    </p>
                </div>

                <Link
                    href={newApplicationUrl}
                    className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white shadow-card transition ${
                        sector === 'senior'
                            ? 'bg-amber-800 hover:bg-amber-900'
                            : 'bg-brand hover:bg-brand-deep'
                    }`}
                >
                    <span className="text-base leading-none">+</span>
                    New Application
                </Link>
            </div>

            {/* STATUS CARDS */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    onClick={() => setStatusFilter('all')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-card transition ${
                        statusFilter === 'all'
                            ? 'border-gray-900 bg-gray-900 text-white'
                            : 'border-line-soft bg-white text-ink-strong hover:border-line'
                    }`}
                >
                    <span className="text-[10px] font-semibold tracking-wider uppercase opacity-75">
                        All Applications
                    </span>
                    <p className="mt-1 text-2xl font-semibold">
                        {applications.length}
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('pending_staff_review')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-card transition ${
                        statusFilter === 'pending_staff_review'
                            ? 'border-amber-700 bg-amber-700 text-white'
                            : 'border-amber-200 bg-amber-50/50 text-amber-900 hover:border-amber-300'
                    }`}
                >
                    <span className="text-[10px] font-semibold tracking-wider uppercase opacity-75">
                        Staff Review Needed
                    </span>
                    <p className="mt-1 text-2xl font-semibold">
                        {
                            applications.filter(
                                (a) => a.status === 'pending_staff_review',
                            ).length
                        }
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('under_admin_review')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-card transition ${
                        statusFilter === 'under_admin_review'
                            ? 'border-blue-700 bg-blue-700 text-white'
                            : 'border-blue-200 bg-blue-50/50 text-blue-900 hover:border-blue-300'
                    }`}
                >
                    <span className="text-[10px] font-semibold tracking-wider uppercase opacity-75">
                        Legacy Forwarded
                    </span>
                    <p className="mt-1 text-2xl font-semibold">
                        {
                            applications.filter(
                                (a) => a.status === 'under_admin_review',
                            ).length
                        }
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('approved')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-card transition ${
                        statusFilter === 'approved'
                            ? 'border-emerald-700 bg-emerald-700 text-white'
                            : 'border-emerald-200 bg-emerald-50/50 text-emerald-900 hover:border-emerald-300'
                    }`}
                >
                    <span className="text-[10px] font-semibold tracking-wider uppercase opacity-75">
                        Approved
                    </span>
                    <p className="mt-1 text-2xl font-semibold">
                        {
                            applications.filter((a) => a.status === 'approved')
                                .length
                        }
                    </p>
                </div>
            </div>

            {/* SEARCH & FILTERS */}
            <div className="space-y-4 rounded-xl border border-line-soft bg-white p-4 shadow-card sm:p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative flex-1">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search applicant name, resident ID, or tracking number..."
                            className="w-full rounded-xl border border-line bg-canvas/50 px-4 py-2.5 text-sm text-ink-strong placeholder-gray-400 focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                className="absolute top-1/2 right-3 -translate-y-1/2 text-xs font-semibold text-ink-faint hover:text-ink-muted"
                            >
                                Clear
                            </button>
                        )}
                    </div>

                    <div className="w-full sm:w-56">
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full rounded-xl border border-line bg-canvas/50 px-3.5 py-2.5 text-sm text-ink-strong focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                        >
                            <option value="all">All Statuses</option>
                            <option value="pending_staff_review">
                                Pending Staff Review
                            </option>
                            <option value="under_admin_review">
                                Legacy Forwarded
                            </option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="requires_correction">
                                Needs Correction
                            </option>
                        </select>
                    </div>
                </div>

                {/* APPLICATIONS TABLE */}
                <div className="overflow-x-auto rounded-xl border border-line-soft">
                    <table className="w-full text-left text-sm text-ink-muted">
                        <thead className="border-b border-line-soft bg-canvas text-xs font-semibold tracking-wider text-ink-muted uppercase">
                            <tr>
                                <th className="px-4 py-3.5">Tracking No.</th>
                                <th className="px-4 py-3.5">
                                    Applicant Resident
                                </th>
                                <th className="px-4 py-3.5">Purok</th>
                                <th className="px-4 py-3.5">Source</th>
                                <th className="px-4 py-3.5">Submitted</th>
                                <th className="px-4 py-3.5">Status</th>
                                <th className="px-4 py-3.5 text-right">
                                    Review Action
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 bg-white">
                            {filteredApplications.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-12 text-center text-sm text-ink-muted"
                                    >
                                        <div className="flex flex-col items-center justify-center space-y-2">
                                            <span className="text-3xl"></span>
                                            <p className="font-semibold text-ink">
                                                No applications found
                                            </p>
                                            <p className="text-xs text-ink-faint">
                                                {search ||
                                                statusFilter !== 'all'
                                                    ? 'No applications match your active search or status filter.'
                                                    : 'No sector applications have been submitted yet.'}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredApplications.map((app) => (
                                    <tr
                                        key={app.id}
                                        className="transition hover:bg-canvas/80"
                                    >
                                        <td className="px-4 py-3.5 font-mono text-xs font-semibold text-ink-strong">
                                            {app.tracking_number}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <div className="font-semibold text-ink-strong">
                                                {app.applicant_name}
                                            </div>
                                            <div className="font-mono text-xs text-ink-faint">
                                                {app.resident_number}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-ink-muted">
                                            {app.purok}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className="inline-flex items-center rounded-lg bg-secondary px-2 py-0.5 text-[10px] font-semibold text-ink">
                                                {app.submission_type ===
                                                'staff_assisted'
                                                    ? 'Staff-Assisted'
                                                    : 'Self-Service'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-ink-muted">
                                            {app.submitted_at}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            {getStatusBadge(app.status)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <Link
                                                href={`${detailsBaseUrl}/${app.id}`}
                                                className="inline-flex rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-gray-800"
                                            >
                                                Review 
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between pt-1 text-xs text-ink-muted">
                    <span>
                        Showing{' '}
                        <strong className="text-ink">
                            {filteredApplications.length}
                        </strong>{' '}
                        of{' '}
                        <strong className="text-ink">
                            {applications.length}
                        </strong>{' '}
                        applications
                    </span>
                </div>
            </div>
        </div>
    );
}
