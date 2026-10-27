import { Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import type { ResidentDirectoryItem, SectorType } from './types';

interface Props {
    sector: SectorType;
    residents: ResidentDirectoryItem[];
    puroks?: { id: number; name: string }[];
    createApplicationUrl: string;
}

export default function ResidentDirectoryTable({
    sector,
    residents,
    puroks = [],
    createApplicationUrl,
}: Props) {
    const [search, setSearch] = useState('');
    const [selectedPurok, setSelectedPurok] = useState('all');

    const sectorLabel = sector === 'senior' ? 'Senior Citizens' : 'Persons with Disability (PWD)';
    const themeColor = sector === 'senior' ? 'amber' : 'emerald';

    const filteredResidents = useMemo(() => {
        return residents.filter((item) => {
            const matchesPurok =
                selectedPurok === 'all' || item.purok.toLowerCase() === selectedPurok.toLowerCase();
            const term = search.toLowerCase().trim();
            const matchesSearch =
                !term ||
                item.full_name.toLowerCase().includes(term) ||
                item.resident_number.toLowerCase().includes(term);

            return matchesPurok && matchesSearch;
        });
    }, [residents, search, selectedPurok]);

    return (
        <div className="space-y-6">
            {/* TOP BAR / ACTIONS */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-black text-gray-900 tracking-tight">
                        {sectorLabel} Members
                    </h1>
                    <p className="mt-1 text-sm text-gray-600">
                        Authorized roster of {sectorLabel.toLowerCase()} sector members within the barangay.
                    </p>
                </div>

                <Link
                    href={createApplicationUrl}
                    className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold text-white shadow-sm transition ${
                        sector === 'senior'
                            ? 'bg-amber-800 hover:bg-amber-900'
                            : 'bg-[#3f6f3d] hover:bg-[#315b30]'
                    }`}
                >
                    <span className="text-base leading-none">+</span>
                    New Sector Application
                </Link>
            </div>

            {/* PRIVACY & ACCESS CONTROL NOTICE */}
            <div className="rounded-xl border border-gray-200 bg-white/80 p-4 text-xs text-gray-600 shadow-sm flex items-start gap-3">
                <span className="text-base">🔒</span>
                <div>
                    <span className="font-bold text-gray-800">Authorized Identifying Information:</span>{' '}
                    This directory surfaces essential resident identity data for sector verification and service delivery. Sensitive personal credentials and private contact records are protected under barangay tenant isolation.
                </div>
            </div>

            {/* FILTERS & SEARCH */}
            <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative flex-1">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by full name or resident number (e.g. BSJ-RES-000001)..."
                            className="w-full rounded-xl border border-gray-300 bg-gray-50/50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400 hover:text-gray-600"
                            >
                                Clear
                            </button>
                        )}
                    </div>

                    {puroks.length > 0 && (
                        <div className="w-full sm:w-56">
                            <select
                                value={selectedPurok}
                                onChange={(e) => setSelectedPurok(e.target.value)}
                                className="w-full rounded-xl border border-gray-300 bg-gray-50/50 px-3.5 py-2.5 text-sm text-gray-800 focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                            >
                                <option value="all">All Puroks</option>
                                {puroks.map((p) => (
                                    <option key={p.id} value={p.name}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {/* TABLE */}
                <div className="mt-5 overflow-x-auto rounded-xl border border-gray-100">
                    <table className="w-full text-left text-sm text-gray-600">
                        <thead className="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                            <tr>
                                <th className="px-4 py-3.5">Resident ID</th>
                                <th className="px-4 py-3.5">Full Name</th>
                                <th className="px-4 py-3.5">Sex</th>
                                <th className="px-4 py-3.5">Purok</th>
                                <th className="px-4 py-3.5">Sector Classifications</th>
                                <th className="px-4 py-3.5">Status</th>
                                <th className="px-4 py-3.5 text-center">QR Card</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 bg-white">
                            {filteredResidents.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-sm text-gray-500">
                                        <div className="flex flex-col items-center justify-center space-y-2">
                                            <span className="text-3xl">📋</span>
                                            <p className="font-semibold text-gray-700">No {sectorLabel} records found</p>
                                            <p className="text-xs text-gray-400">
                                                {search || selectedPurok !== 'all'
                                                    ? 'Try adjusting your search criteria or purok filter.'
                                                    : 'No residents currently hold active membership in this sector.'}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredResidents.map((res) => (
                                    <tr key={res.id} className="hover:bg-gray-50/80 transition">
                                        <td className="px-4 py-3.5 font-mono text-xs font-bold text-gray-900">
                                            {res.resident_number}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className="font-semibold text-gray-900">
                                                {res.full_name}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-gray-600">
                                            {res.sex || '—'}
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-gray-600">
                                            {res.purok || '—'}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <div className="flex flex-wrap gap-1.5">
                                                {res.sectors && res.sectors.length > 0 ? (
                                                    res.sectors.map((s) => (
                                                        <span
                                                            key={s.id}
                                                            className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                                                                s.slug === 'senior'
                                                                    ? 'bg-amber-100 text-amber-800'
                                                                    : s.slug === 'pwd'
                                                                    ? 'bg-indigo-100 text-indigo-800'
                                                                    : 'bg-gray-100 text-gray-700'
                                                            }`}
                                                        >
                                                            {s.name}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-xs text-gray-400">Regular</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                    res.status === 'active'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-gray-100 text-gray-600'
                                                }`}
                                            >
                                                {res.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-center">
                                            {res.has_qr ? (
                                                <span className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 border border-emerald-200">
                                                    <span>✓</span> Active
                                                </span>
                                            ) : (
                                                <span className="text-xs text-gray-400">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="mt-4 flex items-center justify-between text-xs text-gray-500">
                    <span>
                        Showing <strong className="text-gray-700">{filteredResidents.length}</strong> of{' '}
                        <strong className="text-gray-700">{residents.length}</strong> registered residents
                    </span>
                </div>
            </div>
        </div>
    );
}
