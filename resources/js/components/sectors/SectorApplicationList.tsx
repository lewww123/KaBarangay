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
                    <span className="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 uppercase">
                        Pending Staff Review
                    </span>
                );
            case 'under_admin_review':
                return (
                    <span className="inline-flex items-center rounded-md bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-800 uppercase">
                        Legacy Forwarded
                    </span>
                );
            case 'approved':
                return (
                    <span className="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 uppercase">
                        Approved
                    </span>
                );
            case 'rejected':
                return (
                    <span className="inline-flex items-center rounded-md bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-800 uppercase">
                        Rejected
                    </span>
                );
            case 'requires_correction':
            case 'needs_correction':
                return (
                    <span className="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-[10px] font-bold text-purple-800 uppercase">
                        Needs Correction
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-700 uppercase">
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
                    <h1 className="text-2xl font-black tracking-tight text-gray-900">
                        {sectorTitle} Applications
                    </h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Track, verify, and decide sector membership
                        applications.
                    </p>
                </div>

                <Link
                    href={newApplicationUrl}
                    className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold text-white shadow-sm transition ${
                        sector === 'senior'
                            ? 'bg-amber-800 hover:bg-amber-900'
                            : 'bg-[#3b6fa8] hover:bg-[#2a5279]'
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
                    className={`cursor-pointer rounded-xl border p-4 shadow-sm transition ${
                        statusFilter === 'all'
                            ? 'border-gray-900 bg-gray-900 text-white'
                            : 'border-gray-200 bg-white text-gray-800 hover:border-gray-300'
                    }`}
                >
                    <span className="text-[10px] font-bold tracking-wider uppercase opacity-75">
                        All Applications
                    </span>
                    <p className="mt-1 text-2xl font-black">
                        {applications.length}
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('pending_staff_review')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-sm transition ${
                        statusFilter === 'pending_staff_review'
                            ? 'border-amber-700 bg-amber-700 text-white'
                            : 'border-amber-200 bg-amber-50/50 text-amber-900 hover:border-amber-300'
                    }`}
                >
                    <span className="text-[10px] font-bold tracking-wider uppercase opacity-75">
                        Staff Review Needed
                    </span>
                    <p className="mt-1 text-2xl font-black">
                        {
                            applications.filter(
                                (a) => a.status === 'pending_staff_review',
                            ).length
                        }
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('under_admin_review')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-sm transition ${
                        statusFilter === 'under_admin_review'
                            ? 'border-blue-700 bg-blue-700 text-white'
                            : 'border-blue-200 bg-blue-50/50 text-blue-900 hover:border-blue-300'
                    }`}
                >
                    <span className="text-[10px] font-bold tracking-wider uppercase opacity-75">
                        Legacy Forwarded
                    </span>
                    <p className="mt-1 text-2xl font-black">
                        {
                            applications.filter(
                                (a) => a.status === 'under_admin_review',
                            ).length
                        }
                    </p>
                </div>

                <div
                    onClick={() => setStatusFilter('approved')}
                    className={`cursor-pointer rounded-xl border p-4 shadow-sm transition ${
                        statusFilter === 'approved'
                            ? 'border-emerald-700 bg-emerald-700 text-white'
                            : 'border-emerald-200 bg-emerald-50/50 text-emerald-900 hover:border-emerald-300'
                    }`}
                >
                    <span className="text-[10px] font-bold tracking-wider uppercase opacity-75">
                        Approved
                    </span>
                    <p className="mt-1 text-2xl font-black">
                        {
                            applications.filter((a) => a.status === 'approved')
                                .length
                        }
                    </p>
                </div>
            </div>

            {/* SEARCH & FILTERS */}
            <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative flex-1">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search applicant name, resident ID, or tracking number..."
                            className="w-full rounded-xl border border-gray-300 bg-gray-50/50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-[#3b6fa8] focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                className="absolute top-1/2 right-3 -translate-y-1/2 text-xs font-bold text-gray-400 hover:text-gray-600"
                            >
                                Clear
                            </button>
                        )}
                    </div>

                    <div className="w-full sm:w-56">
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full rounded-xl border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm text-gray-800 focus:border-[#3b6fa8] focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
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
                <div className="overflow-x-auto rounded-xl border border-gray-100">
                    <table className="w-full text-left text-sm text-gray-600">
                        <thead className="border-b border-gray-200 bg-gray-50 text-xs font-semibold tracking-wider text-gray-500 uppercase">
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
                                        className="px-4 py-12 text-center text-sm text-gray-500"
                                    >
                                        <div className="flex flex-col items-center justify-center space-y-2">
                                            <span className="text-3xl">📭</span>
                                            <p className="font-semibold text-gray-700">
                                                No applications found
                                            </p>
                                            <p className="text-xs text-gray-400">
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
                                        className="transition hover:bg-gray-50/80"
                                    >
                                        <td className="px-4 py-3.5 font-mono text-xs font-bold text-gray-800">
                                            {app.tracking_number}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <div className="font-bold text-gray-900">
                                                {app.applicant_name}
                                            </div>
                                            <div className="font-mono text-xs text-gray-400">
                                                {app.resident_number}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-gray-600">
                                            {app.purok}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700">
                                                {app.submission_type ===
                                                'staff_assisted'
                                                    ? 'Staff-Assisted'
                                                    : 'Self-Service'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-gray-500">
                                            {app.submitted_at}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            {getStatusBadge(app.status)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <Link
                                                href={`${detailsBaseUrl}/${app.id}`}
                                                className="inline-flex rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-gray-800"
                                            >
                                                Review →
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between pt-1 text-xs text-gray-500">
                    <span>
                        Showing{' '}
                        <strong className="text-gray-700">
                            {filteredApplications.length}
                        </strong>{' '}
                        of{' '}
                        <strong className="text-gray-700">
                            {applications.length}
                        </strong>{' '}
                        applications
                    </span>
                </div>
            </div>
        </div>
    );
}
