import { Deferred, Head, Link } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import { useState, useMemo } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    applications: SectorApplicationItem[];
}

export default function AdminSectorApplicationsIndex(props: Props) {
    return <Deferred data="applications" fallback={<AdminLayout active="sector-applications"><AdminPageSkeleton /></AdminLayout>}><SectorApplicationsContent {...props} /></Deferred>;
}

function SectorApplicationsContent({ applications = [] }: Props) {
    const [search, setSearch] = useState('');
    const [sectorFilter, setSectorFilter] = useState<'all' | 'senior' | 'pwd'>('all');
    const [statusFilter, setStatusFilter] = useState<'all' | string>('all');

    const filteredApplications = useMemo(() => {
        return applications.filter((app) => {
            const matchesSector = sectorFilter === 'all' || app.sector === sectorFilter;
            const matchesStatus = statusFilter === 'all' || app.status === statusFilter;
            const term = search.toLowerCase().trim();
            const matchesSearch =
                !term ||
                app.applicant_name.toLowerCase().includes(term) ||
                app.resident_number.toLowerCase().includes(term) ||
                app.tracking_number.toLowerCase().includes(term);

            return matchesSector && matchesStatus && matchesSearch;
        });
    }, [applications, search, sectorFilter, statusFilter]);

    return (
        <AdminLayout active="sector-applications">
            <Head title="Sector Applications Management - Admin" />

            <div className="mx-auto max-w-7xl space-y-6">
                {/* HEADER */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-black text-gray-900 tracking-tight">
                            Sector Membership Applications
                        </h1>
                        <p className="mt-1 text-sm text-gray-600">
                            Barangay Administrator final approval queue for Senior Citizens and PWD sector registrations.
                        </p>
                    </div>

                    <div className="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-700 shadow-sm">
                        <span>Total Queue:</span>
                        <span className="font-extrabold text-[#3f6f3d]">{applications.length}</span>
                    </div>
                </div>

                {/* STATS TILES */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-amber-800">
                            Pending Final Decision
                        </span>
                        <p className="mt-1 text-2xl font-black text-amber-900">
                            {applications.filter((a) => a.status === 'under_admin_review').length}
                        </p>
                    </div>

                    <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-800">
                            Admin Approved
                        </span>
                        <p className="mt-1 text-2xl font-black text-emerald-900">
                            {applications.filter((a) => a.status === 'approved').length}
                        </p>
                    </div>

                    <div className="rounded-xl border border-purple-200 bg-purple-50/50 p-4 shadow-sm">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-purple-800">
                            In Staff Review
                        </span>
                        <p className="mt-1 text-2xl font-black text-purple-900">
                            {applications.filter((a) => a.status === 'pending_staff_review').length}
                        </p>
                    </div>

                    <div className="rounded-xl border border-red-200 bg-red-50/50 p-4 shadow-sm">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-red-800">
                            Rejected / Returned
                        </span>
                        <p className="mt-1 text-2xl font-black text-red-900">
                            {applications.filter((a) => a.status === 'rejected' || a.status === 'needs_correction').length}
                        </p>
                    </div>
                </div>

                {/* SEARCH & FILTER CONTROLS */}
                <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="relative flex-1">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by resident name, resident ID, or tracking number..."
                                className="w-full rounded-xl border border-gray-300 bg-gray-50/50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                            />
                        </div>

                        <div className="flex gap-2">
                            <select
                                value={sectorFilter}
                                onChange={(e) => setSectorFilter(e.target.value as any)}
                                className="rounded-xl border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm text-gray-800 focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                            >
                                <option value="all">All Sectors</option>
                                <option value="senior">Senior Citizens</option>
                                <option value="pwd">PWD Sector</option>
                            </select>

                            <select
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="rounded-xl border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm text-gray-800 focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                            >
                                <option value="all">All Statuses</option>
                                <option value="under_admin_review">Awaiting Admin Decision</option>
                                <option value="pending_staff_review">Pending Staff Review</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>

                    {/* TABLE */}
                    <div className="overflow-x-auto rounded-xl border border-gray-100">
                        <table className="w-full text-left text-sm text-gray-600">
                            <thead className="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th className="px-4 py-3.5">Tracking No.</th>
                                    <th className="px-4 py-3.5">Applicant Resident</th>
                                    <th className="px-4 py-3.5">Sector</th>
                                    <th className="px-4 py-3.5">Staff Recommendation</th>
                                    <th className="px-4 py-3.5">Status</th>
                                    <th className="px-4 py-3.5 text-right">Admin Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 bg-white">
                                {filteredApplications.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-12 text-center text-sm text-gray-500">
                                            No sector applications match the active filters.
                                        </td>
                                    </tr>
                                ) : (
                                    filteredApplications.map((app) => (
                                        <tr key={app.id} className="hover:bg-gray-50/80 transition">
                                            <td className="px-4 py-3.5 font-mono text-xs font-bold text-gray-900">
                                                {app.tracking_number}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <div className="font-bold text-gray-900">{app.applicant_name}</div>
                                                <div className="font-mono text-xs text-gray-400">{app.resident_number}</div>
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span
                                                    className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                                                        app.sector === 'senior'
                                                            ? 'bg-amber-100 text-amber-800'
                                                            : 'bg-indigo-100 text-indigo-800'
                                                    }`}
                                                >
                                                    {app.sector_name}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5 text-xs text-gray-600">
                                                {app.staff_checklist_completed ? (
                                                    <span className="inline-flex items-center gap-1 text-emerald-700 font-semibold">
                                                        <span>✓</span> Recommended ({app.reviewed_by_staff_name || 'Staff'})
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400">In Intake Review</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span
                                                    className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                        app.status === 'approved'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : app.status === 'under_admin_review'
                                                            ? 'bg-blue-100 text-blue-800'
                                                            : app.status === 'rejected'
                                                            ? 'bg-red-100 text-red-800'
                                                            : 'bg-amber-100 text-amber-800'
                                                    }`}
                                                >
                                                    {app.status.replace(/_/g, ' ')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5 text-right">
                                                <Link
                                                    href={`/admin/sector-applications/${app.id}`}
                                                    className="inline-flex rounded-lg bg-[#3f6f3d] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#315b30] transition shadow-sm"
                                                >
                                                    Review & Decide →
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
