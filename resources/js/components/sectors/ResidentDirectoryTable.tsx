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

    const sectorLabel =
        sector === 'senior'
            ? 'Senior Citizens'
            : 'Persons with Disability (PWD)';
    const _themeColor = sector === 'senior' ? 'amber' : 'emerald';

    const filteredResidents = useMemo(() => {
        return residents.filter((item) => {
            const matchesPurok =
                selectedPurok === 'all' ||
                item.purok.toLowerCase() === selectedPurok.toLowerCase();
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
                    <h1 className="text-2xl font-semibold tracking-tight text-ink-strong">
                        {sectorLabel} Members
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Authorized roster of {sectorLabel.toLowerCase()} sector
                        members within the barangay.
                    </p>
                </div>

                <Link
                    href={createApplicationUrl}
                    className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-white shadow-card transition ${
                        sector === 'senior'
                            ? 'bg-amber-800 hover:bg-amber-900'
                            : 'bg-brand hover:bg-brand-deep'
                    }`}
                >
                    <span className="text-base leading-none">+</span>
                    New Sector Application
                </Link>
            </div>

            {/* PRIVACY & ACCESS CONTROL NOTICE */}
            <div className="flex items-start gap-3 rounded-xl border border-line-soft bg-white/80 p-4 text-xs text-ink-muted shadow-card">
                <span className="text-base">🔒</span>
                <div>
                    <span className="font-semibold text-ink-strong">
                        Authorized Identifying Information:
                    </span>{' '}
                    This directory surfaces essential resident identity data for
                    sector verification and service delivery. Sensitive personal
                    credentials and private contact records are protected under
                    barangay tenant isolation.
                </div>
            </div>

            {/* FILTERS & SEARCH */}
            <div className="rounded-xl border border-line-soft bg-white p-4 shadow-card sm:p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="relative flex-1">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by full name or resident number (e.g. BSJ-RES-000001)..."
                            className="w-full rounded-xl border border-line bg-canvas/50 px-4 py-2.5 text-sm text-ink-strong placeholder-gray-400 transition focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
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

                    {puroks.length > 0 && (
                        <div className="w-full sm:w-56">
                            <select
                                value={selectedPurok}
                                onChange={(e) =>
                                    setSelectedPurok(e.target.value)
                                }
                                className="w-full rounded-xl border border-line bg-canvas/50 px-3.5 py-2.5 text-sm text-ink-strong focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
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
                <div className="mt-5 overflow-x-auto rounded-xl border border-line-soft">
                    <table className="w-full text-left text-sm text-ink-muted">
                        <thead className="border-b border-line-soft bg-canvas text-xs font-semibold tracking-wider text-ink-muted uppercase">
                            <tr>
                                <th className="px-4 py-3.5">Resident ID</th>
                                <th className="px-4 py-3.5">Full Name</th>
                                <th className="px-4 py-3.5">Sex</th>
                                <th className="px-4 py-3.5">Purok</th>
                                <th className="px-4 py-3.5">
                                    Sector Classifications
                                </th>
                                <th className="px-4 py-3.5">Status</th>
                                <th className="px-4 py-3.5 text-center">
                                    QR Card
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 bg-white">
                            {filteredResidents.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-12 text-center text-sm text-ink-muted"
                                    >
                                        <div className="flex flex-col items-center justify-center space-y-2">
                                            <span className="text-3xl">📋</span>
                                            <p className="font-semibold text-ink">
                                                No {sectorLabel} records found
                                            </p>
                                            <p className="text-xs text-ink-faint">
                                                {search ||
                                                selectedPurok !== 'all'
                                                    ? 'Try adjusting your search criteria or purok filter.'
                                                    : 'No residents currently hold active membership in this sector.'}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredResidents.map((res) => (
                                    <tr
                                        key={res.id}
                                        className="transition hover:bg-canvas/80"
                                    >
                                        <td className="px-4 py-3.5 font-mono text-xs font-semibold text-ink-strong">
                                            {res.resident_number}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span className="font-semibold text-ink-strong">
                                                {res.full_name}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-ink-muted">
                                            {res.sex || '—'}
                                        </td>
                                        <td className="px-4 py-3.5 text-xs text-ink-muted">
                                            {res.purok || '—'}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <div className="flex flex-wrap gap-1.5">
                                                {res.sectors &&
                                                res.sectors.length > 0 ? (
                                                    res.sectors.map((s) => (
                                                        <span
                                                            key={s.id}
                                                            className={`inline-flex items-center rounded-lg px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase ${
                                                                s.slug ===
                                                                'senior'
                                                                    ? 'bg-amber-100 text-amber-800'
                                                                    : s.slug ===
                                                                        'pwd'
                                                                      ? 'bg-indigo-100 text-indigo-800'
                                                                      : 'bg-secondary text-ink'
                                                            }`}
                                                        >
                                                            {s.name}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-xs text-ink-faint">
                                                        Regular
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <span
                                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ${
                                                    res.status === 'active'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-secondary text-ink-muted'
                                                }`}
                                            >
                                                {res.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-center">
                                            {res.has_qr ? (
                                                <span className="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                    <span>✓</span> Active
                                                </span>
                                            ) : (
                                                <span className="text-xs text-ink-faint">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="mt-4 flex items-center justify-between text-xs text-ink-muted">
                    <span>
                        Showing{' '}
                        <strong className="text-ink">
                            {filteredResidents.length}
                        </strong>{' '}
                        of{' '}
                        <strong className="text-ink">
                            {residents.length}
                        </strong>{' '}
                        registered residents
                    </span>
                </div>
            </div>
        </div>
    );
}
