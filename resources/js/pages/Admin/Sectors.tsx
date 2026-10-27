import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type MemberRecord = {
    id: number;
    resident_number: string;
    name: string;
    purok: string;
    status: string;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type PurokOption = {
    id: number;
    name: string;
};

type MemberFilters = {
    search: string;
    purok: string;
};

type CertificateRequestRecord = {
    id: number;
    reference: string;
    certificate: string;
    resident_name: string;
    resident_number: string | null;
    purpose: string;
    fee: number;
    status: string;
    submitted_at: string;
    status_at: string;
};

type CertificateFilters = {
    search: string;
};

type LuponSummary = {
    open: number;
    closed: number;
    total: number;
};

type Props = {
    stats: {
        total?: number;
        active?: number;
        sectorApplications: number;
        generalMembers: number;
        pwdMembers: number;
        seniorMembers: number;
        certificates: number;
        openCases: number;
    };
    generalMembers: Paginator<MemberRecord>;
    pwdMembers: Paginator<MemberRecord>;
    seniorMembers: Paginator<MemberRecord>;
    puroks: PurokOption[];
    filters: MemberFilters;
    certificates: {
        processed: number;
        completed: number;
    };
    certificateRequests: Paginator<CertificateRequestRecord>;
    certificateFilters: CertificateFilters;
    luponSummary?: LuponSummary;
    initialTab?: string;
};

type ActiveTab =
    | 'applications'
    | 'general'
    | 'pwd'
    | 'senior'
    | 'certificates'
    | 'lupon';

export default function Sectors({
    stats,
    generalMembers,
    pwdMembers,
    seniorMembers,
    puroks,
    filters,
    certificates,
    certificateRequests,
    certificateFilters,
    luponSummary = { open: 0, closed: 0, total: 0 },
    initialTab = 'general',
}: Props) {
    const validInitialTab: ActiveTab = [
        'applications',
        'general',
        'pwd',
        'senior',
        'certificates',
        'lupon',
    ].includes(initialTab)
        ? (initialTab as ActiveTab)
        : 'general';

    const [activeTab, setActiveTab] = useState<ActiveTab>(validInitialTab);

    /*
     * Local mirrors of the server-applied filters. Seeded once from the
     * server props, then kept authoritative for as long as the user is
     * typing, so a partial reload never wipes an in-progress query.
     */
    const [memberSearch, setMemberSearch] = useState(filters.search);
    const [memberPurok, setMemberPurok] = useState(filters.purok);

    /*
     * Local mirror of the server-applied Certificates search. Seeded once from
     * the server prop, then kept authoritative while the user is typing.
     */
    const [certificateSearch, setCertificateSearch] = useState(
        certificateFilters.search,
    );

    /*
     * Server-side reload shared by all member rosters.
     */
    const reloadMembers = (params: Record<string, string | number>) => {
        router.get(
            '/admin/sectors',
            {
                search: memberSearch.trim(),
                purok: memberPurok,
                tab: activeTab,
                ...params,
            },
            {
                only: [
                    'generalMembers',
                    'pwdMembers',
                    'seniorMembers',
                    'stats',
                    'filters',
                ],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    /*
     * Drop both filters and restart all rosters at page 1.
     */
    const clearMemberFilters = () => {
        setMemberSearch('');
        setMemberPurok('');

        reloadMembers({
            search: '',
            purok: '',
            general_page: 1,
            pwd_page: 1,
            senior_page: 1,
        });
    };

    /*
     * Search and purok filtering always restart all rosters at page 1.
     */
    const applyMemberFilters = (
        overrides: Record<string, string | number> = {},
    ) => {
        reloadMembers({
            general_page: 1,
            pwd_page: 1,
            senior_page: 1,
            ...overrides,
        });
    };

    const submitMemberSearch = (event: React.FormEvent) => {
        event.preventDefault();
        applyMemberFilters();
    };

    const changeMemberPurok = (
        event: React.ChangeEvent<HTMLSelectElement>,
    ) => {
        const purok = event.target.value;
        setMemberPurok(purok);
        applyMemberFilters({ purok });
    };

    /*
     * Server-side pagination shared by all three rosters.
     */
    const navigateMembersTo = (page: number) => {
        applyMemberFilters({
            general_page: activeTab === 'general' ? page : 1,
            pwd_page: activeTab === 'pwd' ? page : 1,
            senior_page: activeTab === 'senior' ? page : 1,
        });
    };

    /*
     * Server-side reload for the read-only Certificates list.
     */
    const reloadCertificates = (params: Record<string, string | number> = {}) => {
        router.get(
            '/admin/sectors',
            {
                cert_search: certificateSearch.trim(),
                tab: 'certificates',
                ...params,
            },
            {
                only: ['certificateRequests', 'certificateFilters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const submitCertificateSearch = (event: React.FormEvent) => {
        event.preventDefault();
        reloadCertificates({ cert_page: 1 });
    };

    const clearCertificateFilters = () => {
        setCertificateSearch('');
        reloadCertificates({ cert_search: '', cert_page: 1 });
    };

    const navigateCertificatesTo = (page: number) => {
        reloadCertificates({ cert_page: page });
    };

    return (
        <>
            <Head title="Sectors" />

            <AdminLayout active="sectors">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">
                            Sectors
                        </h1>

                        <p className="mt-1 text-sm text-gray-500">
                            Consolidated resident sector rosters and community records
                        </p>
                    </div>
                </div>

                {/* 6-CATEGORY DASHBOARD METRICS */}
                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {/* 1. Sector Applications */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('applications')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'applications'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            Applications
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.sectorApplications}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            Under Admin Review
                        </p>
                    </button>

                    {/* 2. General Resident */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('general')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'general'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            General Resident
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.generalMembers}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            No sector affiliation
                        </p>
                    </button>

                    {/* 3. PWD Members */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('pwd')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'pwd'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            PWD Members
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.pwdMembers}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            Verified PWD roster
                        </p>
                    </button>

                    {/* 4. Senior Citizen Members */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('senior')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'senior'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            Senior Citizen
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.seniorMembers}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            Verified senior roster
                        </p>
                    </button>

                    {/* 5. Certificates */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('certificates')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'certificates'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            Certificates
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.certificates}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            Released & Completed
                        </p>
                    </button>

                    {/* 6. Lupon Cases */}
                    <button
                        type="button"
                        onClick={() => setActiveTab('lupon')}
                        className={`rounded-lg border p-4 text-left transition-colors ${
                            activeTab === 'lupon'
                                ? 'border-[#3f6f3d] bg-[#3f6f3d]/5 ring-1 ring-[#3f6f3d]'
                                : 'border-gray-200 bg-white hover:border-gray-300'
                        }`}
                    >
                        <p className="text-[11px] font-medium uppercase text-gray-400">
                            Lupon Cases
                        </p>
                        <p className="mt-2 text-2xl font-semibold text-gray-800">
                            {stats.openCases}
                        </p>
                        <p className="mt-0.5 text-[11px] text-gray-500">
                            Active disputes
                        </p>
                    </button>
                </div>

                {/* SECTION TABS */}
                <div className="mb-6 border-b border-gray-200">
                    <nav className="-mb-px flex gap-6 overflow-x-auto">
                        <SectionTab
                            label="Sector Applications"
                            active={activeTab === 'applications'}
                            onClick={() => setActiveTab('applications')}
                        />
                        <SectionTab
                            label="General Resident"
                            active={activeTab === 'general'}
                            onClick={() => setActiveTab('general')}
                        />
                        <SectionTab
                            label="PWD Members"
                            active={activeTab === 'pwd'}
                            onClick={() => setActiveTab('pwd')}
                        />
                        <SectionTab
                            label="Senior Citizens"
                            active={activeTab === 'senior'}
                            onClick={() => setActiveTab('senior')}
                        />
                        <SectionTab
                            label="Certificates"
                            active={activeTab === 'certificates'}
                            onClick={() => setActiveTab('certificates')}
                        />
                        <SectionTab
                            label="Lupon Cases"
                            active={activeTab === 'lupon'}
                            onClick={() => setActiveTab('lupon')}
                        />
                    </nav>
                </div>

                {/* 1. SECTOR APPLICATIONS TAB */}
                {activeTab === 'applications' && (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="rounded-lg bg-[#3f6f3d] p-5 text-white sm:max-w-xs">
                                <p className="text-xs uppercase text-white/80">
                                    Applications Under Review
                                </p>
                                <p className="mt-3 text-3xl font-semibold">
                                    {stats.sectorApplications}
                                </p>
                                <p className="mt-1 text-xs text-white/70">
                                    Sector applications pending Admin final decision
                                </p>
                            </div>

                            <Link
                                href="/admin/sector-applications"
                                className="inline-flex items-center gap-2 rounded-lg bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-[#345d33]"
                            >
                                Open Full Review Queue &rarr;
                            </Link>
                        </div>

                        <div className="rounded-lg border border-gray-200 bg-white p-6">
                            <h2 className="text-base font-semibold text-gray-800">
                                Sector Application Processing
                            </h2>
                            <p className="mt-2 text-sm text-gray-600">
                                Resident applications for sector classification (Senior Citizen, PWD, Solo Parent, etc.) undergo desk review before reaching the Admin final decision queue.
                            </p>
                            <div className="mt-4 flex items-center gap-3">
                                <Link
                                    href="/admin/sector-applications"
                                    className="text-sm font-medium text-[#3f6f3d] hover:underline"
                                >
                                    Go to Sector Applications page to review and process submissions &rarr;
                                </Link>
                            </div>
                        </div>
                    </div>
                )}

                {/* 2. GENERAL RESIDENT TAB */}
                {activeTab === 'general' && (
                    <div className="space-y-4">
                        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white sm:max-w-xs">
                            <p className="text-xs uppercase text-white/80">
                                General Residents
                            </p>
                            <p className="mt-3 text-3xl font-semibold">
                                {stats.generalMembers}
                            </p>
                            <p className="mt-1 text-xs text-white/70">
                                Registered residents with no specific sector membership in your barangay
                            </p>
                        </div>

                        <MembersToolbar
                            searchValue={memberSearch}
                            onSearchChange={setMemberSearch}
                            onSearchSubmit={submitMemberSearch}
                            purokValue={memberPurok}
                            puroks={puroks}
                            onPurokChange={changeMemberPurok}
                            hasFilters={
                                memberSearch.trim() !== '' || memberPurok !== ''
                            }
                            onClear={clearMemberFilters}
                        />

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px]">
                                    <thead className="bg-[#fafaf7]">
                                        <tr className="border-b border-gray-200">
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                RESIDENT NO.
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                NAME
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                PUROK
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                STATUS
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {generalMembers.data.map((member) => (
                                            <tr
                                                key={member.id}
                                                className="border-b border-gray-100 last:border-0"
                                            >
                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.resident_number}
                                                </td>

                                                <td className="px-5 py-4 text-sm font-medium">
                                                    {member.name}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.purok || '-'}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs text-[#3f6f3d]">
                                                        {member.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {generalMembers.data.length === 0 && (
                                <p className="px-5 py-10 text-center text-sm text-gray-400">
                                    {memberSearch.trim() !== '' ||
                                    memberPurok !== ''
                                        ? 'No general residents match the current filters.'
                                        : 'No general residents found for this barangay.'}
                                </p>
                            )}

                            <MembersPagination
                                paginator={generalMembers}
                                onNavigate={navigateMembersTo}
                                label="general residents"
                            />
                        </div>

                        <p className="text-xs text-gray-400">
                            Read-only list of verified residents who do not belong to any designated sector (such as Senior Citizen or PWD) in your barangay.
                        </p>
                    </div>
                )}

                {/* 3. PWD MEMBERS TAB */}
                {activeTab === 'pwd' && (
                    <div className="space-y-4">
                        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white sm:max-w-xs">
                            <p className="text-xs uppercase text-white/80">
                                PWD Members
                            </p>

                            <p className="mt-3 text-3xl font-semibold">
                                {stats.pwdMembers}
                            </p>

                            <p className="mt-1 text-xs text-white/70">
                                Residents tagged with the PWD sector in your
                                barangay
                            </p>
                        </div>

                        <MembersToolbar
                            searchValue={memberSearch}
                            onSearchChange={setMemberSearch}
                            onSearchSubmit={submitMemberSearch}
                            purokValue={memberPurok}
                            puroks={puroks}
                            onPurokChange={changeMemberPurok}
                            hasFilters={
                                memberSearch.trim() !== '' || memberPurok !== ''
                            }
                            onClear={clearMemberFilters}
                        />

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px]">
                                    <thead className="bg-[#fafaf7]">
                                        <tr className="border-b border-gray-200">
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                RESIDENT NO.
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                NAME
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                PUROK
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                STATUS
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {pwdMembers.data.map((member) => (
                                            <tr
                                                key={member.id}
                                                className="border-b border-gray-100 last:border-0"
                                            >
                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.resident_number}
                                                </td>

                                                <td className="px-5 py-4 text-sm font-medium">
                                                    {member.name}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.purok || '-'}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs text-[#3f6f3d]">
                                                        {member.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {pwdMembers.data.length === 0 && (
                                <p className="px-5 py-10 text-center text-sm text-gray-400">
                                    {memberSearch.trim() !== '' ||
                                    memberPurok !== ''
                                        ? 'No PWD members match the current filters.'
                                        : 'No PWD members have been recorded for this barangay yet.'}
                                </p>
                            )}

                            <MembersPagination
                                paginator={pwdMembers}
                                onNavigate={navigateMembersTo}
                                label="PWD members"
                            />
                        </div>

                        <p className="text-xs text-gray-400">
                            Read-only list of residents tagged with the PWD
                            sector in your barangay, including inactive
                            accounts. Contact details and documents are not
                            shown here.
                        </p>
                    </div>
                )}

                {/* 4. SENIOR CITIZENS TAB */}
                {activeTab === 'senior' && (
                    <div className="space-y-4">
                        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white sm:max-w-xs">
                            <p className="text-xs uppercase text-white/80">
                                Senior Citizens
                            </p>

                            <p className="mt-3 text-3xl font-semibold">
                                {stats.seniorMembers}
                            </p>

                            <p className="mt-1 text-xs text-white/70">
                                Residents tagged with the senior sector in your
                                barangay
                            </p>
                        </div>

                        <MembersToolbar
                            searchValue={memberSearch}
                            onSearchChange={setMemberSearch}
                            onSearchSubmit={submitMemberSearch}
                            purokValue={memberPurok}
                            puroks={puroks}
                            onPurokChange={changeMemberPurok}
                            hasFilters={
                                memberSearch.trim() !== '' || memberPurok !== ''
                            }
                            onClear={clearMemberFilters}
                        />

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px]">
                                    <thead className="bg-[#fafaf7]">
                                        <tr className="border-b border-gray-200">
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                RESIDENT NO.
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                NAME
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                PUROK
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                STATUS
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {seniorMembers.data.map((member) => (
                                            <tr
                                                key={member.id}
                                                className="border-b border-gray-100 last:border-0"
                                            >
                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.resident_number}
                                                </td>

                                                <td className="px-5 py-4 text-sm font-medium">
                                                    {member.name}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {member.purok || '-'}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs text-[#3f6f3d]">
                                                        {member.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {seniorMembers.data.length === 0 && (
                                <p className="px-5 py-10 text-center text-sm text-gray-400">
                                    {memberSearch.trim() !== '' ||
                                    memberPurok !== ''
                                        ? 'No senior citizens match the current filters.'
                                        : 'No senior citizens have been recorded for this barangay yet.'}
                                </p>
                            )}

                            <MembersPagination
                                paginator={seniorMembers}
                                onNavigate={navigateMembersTo}
                                label="senior citizens"
                            />
                        </div>

                        <p className="text-xs text-gray-400">
                            Read-only list of residents tagged with the senior
                            sector in your barangay, including inactive
                            accounts. Contact details and documents are not
                            shown here.
                        </p>
                    </div>
                )}

                {/* 5. CERTIFICATES TAB */}
                {activeTab === 'certificates' && (
                    <div className="space-y-4">
                        <div className="rounded-lg bg-[#3f6f3d] p-5 text-white sm:max-w-xs">
                            <p className="text-xs uppercase text-white/80">
                                Processed Certificates
                            </p>

                            <p className="mt-3 text-3xl font-semibold">
                                {certificates.processed}
                            </p>

                            <p className="mt-1 text-xs text-white/70">
                                Certificate requests with a terminal status in
                                your barangay
                            </p>
                        </div>

                        <p className="text-xs text-gray-400">
                            Read-only monitoring of certificate requests whose
                            status is Released or Rejected. Processed and
                            Completed share the same approved definition, so a
                            single count is shown. Requests still being processed
                            are handled by the Clerk and are not shown here.
                        </p>

                        <form
                            onSubmit={submitCertificateSearch}
                            className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm lg:flex-row lg:items-center lg:justify-between"
                        >
                            <input
                                type="search"
                                aria-label="Search certificate requests"
                                value={certificateSearch}
                                onChange={(event) =>
                                    setCertificateSearch(event.target.value)
                                }
                                placeholder="Search reference, resident, or certificate type..."
                                className="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3f6f3d]"
                            />

                            <div className="flex items-center gap-2">
                                <button
                                    type="submit"
                                    className="rounded-md bg-[#3f6f3d] px-4 py-1.5 text-xs font-medium text-white hover:bg-[#345d33]"
                                >
                                    Search
                                </button>

                                {certificateSearch.trim() !== '' && (
                                    <button
                                        type="button"
                                        onClick={clearCertificateFilters}
                                        className="rounded-md border border-gray-300 px-4 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                        </form>

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px]">
                                    <thead className="bg-[#fafaf7]">
                                        <tr className="border-b border-gray-200">
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                REFERENCE
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                CERTIFICATE
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                RESIDENT
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                PURPOSE
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                FEE
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                STATUS
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                SUBMITTED
                                            </th>
                                            <th className="px-5 py-3 text-left text-xs text-gray-400">
                                                RELEASED / REJECTED
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {certificateRequests.data.map((request) => (
                                            <tr
                                                key={request.id}
                                                className="border-b border-gray-100 last:border-0"
                                            >
                                                <td className="px-5 py-4 text-sm font-medium">
                                                    {request.reference}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {request.certificate}
                                                </td>

                                                <td className="px-5 py-4 text-sm">
                                                    <span className="font-medium">
                                                        {request.resident_name}
                                                    </span>

                                                    {request.resident_number && (
                                                        <span className="block text-xs text-gray-400">
                                                            {request.resident_number}
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {request.purpose}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    ₱{request.fee.toFixed(2)}
                                                </td>

                                                <td className="px-5 py-4">
                                                    <span
                                                        className={`rounded-full px-3 py-1 text-xs ${
                                                            request.status === 'Released'
                                                                ? 'bg-[#edf4ea] text-[#3f6f3d]'
                                                                : 'bg-[#fdecec] text-[#a33a3a]'
                                                        }`}
                                                    >
                                                        {request.status}
                                                    </span>
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {request.submitted_at || '-'}
                                                </td>

                                                <td className="px-5 py-4 text-sm text-gray-600">
                                                    {request.status_at || '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {certificateRequests.data.length === 0 && (
                                <p className="px-5 py-10 text-center text-sm text-gray-400">
                                    {certificateSearch.trim() !== ''
                                        ? 'No released or rejected requests match the current search.'
                                        : 'No released or rejected certificate requests have been recorded for this barangay yet.'}
                                </p>
                            )}

                            <MembersPagination
                                paginator={certificateRequests}
                                onNavigate={navigateCertificatesTo}
                                label="certificate requests"
                            />
                        </div>
                    </div>
                )}

                {/* 6. LUPON CASES TAB */}
                {activeTab === 'lupon' && (
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div className="rounded-lg bg-[#3f6f3d] p-5 text-white">
                                <p className="text-xs uppercase text-white/80">
                                    Open / Active Cases
                                </p>
                                <p className="mt-3 text-3xl font-semibold">
                                    {luponSummary.open}
                                </p>
                                <p className="mt-1 text-xs text-white/70">
                                    Active, Under Mediation, or Conciliation
                                </p>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-5">
                                <p className="text-xs uppercase text-gray-400">
                                    Resolved Cases
                                </p>
                                <p className="mt-3 text-3xl font-semibold text-gray-800">
                                    {luponSummary.closed}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">
                                    Settled, Dismissed, or Unresolved
                                </p>
                            </div>

                            <div className="rounded-lg border border-gray-200 bg-white p-5">
                                <p className="text-xs uppercase text-gray-400">
                                    Total Filed Cases
                                </p>
                                <p className="mt-3 text-3xl font-semibold text-gray-800">
                                    {luponSummary.total}
                                </p>
                                <p className="mt-1 text-xs text-gray-500">
                                    All Lupon cases recorded for this barangay
                                </p>
                            </div>
                        </div>

                        <div className="rounded-lg border border-gray-200 bg-white p-6">
                            <h2 className="text-base font-semibold text-gray-800">
                                Lupong Tagapamayapa Confidentiality Notice
                            </h2>
                            <p className="mt-2 text-sm text-gray-600">
                                Under Republic Act No. 7160 (Local Government Code), Katarungang Pambarangay proceedings and case details are confidential to protect the parties involved. Detailed case records, hearing minutes, and amicable settlement terms are accessible only to authorized Lupon desk officers.
                            </p>
                            <p className="mt-3 text-xs text-gray-400">
                                The Admin dashboard displays aggregate case statistics only. Direct mediation records are restricted to the Lupong Tagapamayapa portal.
                            </p>
                        </div>
                    </div>
                )}
            </AdminLayout>
        </>
    );
}

/*
|--------------------------------------------------------------------------
| Section Tab
|--------------------------------------------------------------------------
*/

function SectionTab({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors ${
                active
                    ? 'border-[#3f6f3d] text-[#3f6f3d]'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
        >
            {label}
        </button>
    );
}

/*
|--------------------------------------------------------------------------
| Member Roster Toolbar
|--------------------------------------------------------------------------
*/

function MembersToolbar({
    searchValue,
    onSearchChange,
    onSearchSubmit,
    purokValue,
    puroks,
    onPurokChange,
    hasFilters,
    onClear,
}: {
    searchValue: string;
    onSearchChange: (value: string) => void;
    onSearchSubmit: (event: React.FormEvent) => void;
    purokValue: string;
    puroks: PurokOption[];
    onPurokChange: (event: React.ChangeEvent<HTMLSelectElement>) => void;
    hasFilters: boolean;
    onClear: () => void;
}) {
    return (
        <form
            onSubmit={onSearchSubmit}
            className="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-3.5 shadow-sm lg:flex-row lg:items-center lg:justify-between"
        >
            <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                <input
                    type="search"
                    aria-label="Search members"
                    value={searchValue}
                    onChange={(event) => onSearchChange(event.target.value)}
                    placeholder="Search resident number, name, or purok..."
                    className="min-w-0 flex-1 rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3f6f3d]"
                />

                <select
                    aria-label="Filter by purok"
                    value={purokValue}
                    onChange={onPurokChange}
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-700 outline-none focus:border-[#3f6f3d]"
                >
                    <option value="">All Puroks</option>

                    {puroks.map((purok) => (
                        <option key={purok.id} value={String(purok.id)}>
                            {purok.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex items-center gap-2">
                <button
                    type="submit"
                    className="rounded-md bg-[#3f6f3d] px-4 py-1.5 text-xs font-medium text-white hover:bg-[#345d33]"
                >
                    Search
                </button>

                {hasFilters && (
                    <button
                        type="button"
                        onClick={onClear}
                        className="rounded-md border border-gray-300 px-4 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                    >
                        Clear
                    </button>
                )}
            </div>
        </form>
    );
}

/*
|--------------------------------------------------------------------------
| Members Pagination
|--------------------------------------------------------------------------
*/

function MembersPagination<T>({
    paginator,
    onNavigate,
    label,
}: {
    paginator: Paginator<T>;
    onNavigate: (page: number) => void;
    label: string;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t border-gray-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-gray-500">
                Showing {paginator.from ?? 0}&ndash;{paginator.to ?? 0} of{' '}
                {paginator.total} {label}
            </p>

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={!paginator.prev_page_url}
                    onClick={() => onNavigate(paginator.current_page - 1)}
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Previous
                </button>

                <span className="text-xs text-gray-500">
                    Page {paginator.current_page} of {paginator.last_page}
                </span>

                <button
                    type="button"
                    disabled={!paginator.next_page_url}
                    onClick={() => onNavigate(paginator.current_page + 1)}
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Next
                </button>
            </div>
        </div>
    );
}
