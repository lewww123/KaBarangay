import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type SectorRecord = {
    id: number;
    name: string;
    slug: string;
    requires_proof: boolean;
    is_active: boolean;
    residents_count: number;
    registration_sectors_count: number;
    registration_documents_count: number;
};

type SectorForm = {
    name: string;
    slug: string;
    requires_proof: boolean;
    is_active: boolean;
};

type PwdMemberRecord = {
    id: number;
    resident_number: string;
    name: string;
    purok: string;
    status: string;
};

type SeniorMemberRecord = {
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

type Props = {
    sectors: SectorRecord[];
    stats: {
        total: number;
        active: number;
        pwdMembers: number;
        seniorMembers: number;
    };
    pwdMembers: Paginator<PwdMemberRecord>;
    seniorMembers: Paginator<SeniorMemberRecord>;
    puroks: PurokOption[];
    filters: MemberFilters;
    certificates: {
        processed: number;
        completed: number;
    };
    certificateRequests: Paginator<CertificateRequestRecord>;
    certificateFilters: CertificateFilters;
};

const emptyForm: SectorForm = {
    name: '',
    slug: '',
    requires_proof: true,
    is_active: true,
};

const referencesFor = (sector: SectorRecord) =>
    sector.residents_count +
    sector.registration_sectors_count +
    sector.registration_documents_count;

export default function Sectors({
    sectors,
    stats,
    pwdMembers,
    seniorMembers,
    puroks,
    filters,
    certificates,
    certificateRequests,
    certificateFilters,
}: Props) {
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState<SectorRecord | null>(null);
    const [activeTab, setActiveTab] = useState<
        'sectors' | 'pwd' | 'senior' | 'certificates' | 'lupon'
    >('sectors');

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

    const form = useForm<SectorForm>({ ...emptyForm });

    const openEdit = (sector: SectorRecord) => {
        setEditing(sector);
        form.setData({
            name: sector.name,
            slug: sector.slug,
            requires_proof: sector.requires_proof,
            is_active: sector.is_active,
        });
        form.clearErrors();
        setShowModal(true);
    };

    const closeModal = () => {
        setShowModal(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        };

        if (editing) {
            form.put(`/admin/sectors/${editing.id}`, options);
        }
    };

    /*
     * Server-side reload shared by both member rosters.
     *
     * Only the roster payloads, the headline counts, and the echoed filters
     * are requested, so tab selection, an open modal, and scroll position all
     * survive a search, filter, or page change.
     */
    const reloadMembers = (params: Record<string, string | number>) => {
        router.get(
            '/admin/sectors',
            {
                // Defaults come from the live local state; `params` is spread
                // last so a caller can override one of them (for example the
                // purok being changed in the same tick as the state update).
                search: memberSearch.trim(),
                purok: memberPurok,
                ...params,
            },
            {
                only: ['pwdMembers', 'seniorMembers', 'stats', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    /*
     * Drop both filters and restart both rosters at page 1.
     */
    const clearMemberFilters = () => {
        setMemberSearch('');
        setMemberPurok('');

        reloadMembers({
            search: '',
            purok: '',
            pwd_page: 1,
            senior_page: 1,
        });
    };

    /*
     * Search and purok filtering always restart both rosters at page 1, so
     * narrowing a filter can never strand the user beyond the last page.
     */
    const applyMemberFilters = (
        overrides: Record<string, string | number> = {},
    ) => {
        reloadMembers({
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
     * Server-side pagination shared by both rosters. Only the roster the user
     * is actually viewing advances; the other is reset to page 1 so its
     * generated links stay consistent with the filters in effect.
     */
    const navigateMembersTo = (page: number) => {
        const pagingPwd = activeTab === 'pwd';

        applyMemberFilters({
            pwd_page: pagingPwd ? page : 1,
            senior_page: pagingPwd ? 1 : page,
        });
    };

    /*
     * Server-side reload for the read-only Certificates list.
     *
     * Only the terminal-request payload and the echoed search are requested, so
     * the Released/Rejected restriction stays server-enforced and switching
     * tabs, an in-progress member search, and scroll position all survive a
     * search or page change.
     */
    const reloadCertificates = (params: Record<string, string | number> = {}) => {
        router.get(
            '/admin/sectors',
            {
                cert_search: certificateSearch.trim(),
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

    /*
     * Search always restarts the list at page 1, so narrowing the filter can
     * never strand the user beyond the last page.
     */
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
                            Manage resident sector definitions
                        </p>
                    </div>
                </div>

                {/* SECTION TABS */}
                <div className="mb-6 border-b border-gray-200">
                    <nav className="-mb-px flex gap-6">
                        <SectionTab
                            label="Sector Definitions"
                            active={activeTab === 'sectors'}
                            onClick={() => setActiveTab('sectors')}
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

                {activeTab === 'sectors' && (
                    <>
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="rounded-lg bg-[#3f6f3d] p-5 text-white">
                        <p className="text-xs uppercase text-white/80">
                            Active Sectors
                        </p>

                        <p className="mt-3 text-3xl font-semibold">
                            {stats.active}
                        </p>

                        <p className="mt-1 text-xs text-white/70">
                            Available for resident tagging
                        </p>
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-5">
                        <p className="text-xs uppercase text-gray-400">
                            Total Sectors
                        </p>

                        <p className="mt-3 text-3xl font-semibold text-gray-800">
                            {stats.total}
                        </p>

                        <p className="mt-1 text-xs text-gray-500">
                            Sector definitions in the system
                        </p>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px]">
                            <thead className="bg-[#fafaf7]">
                                <tr className="border-b border-gray-200">
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        NAME
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        SLUG
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        PROOF
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        STATUS
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        RESIDENTS
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        REFERENCED
                                    </th>
                                    <th className="px-5 py-3 text-right text-xs text-gray-400">
                                        ACTION
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {sectors.map((sector) => (
                                    <tr
                                        key={sector.id}
                                        className="border-b border-gray-100 last:border-0"
                                    >
                                        <td className="px-5 py-4 text-sm font-medium">
                                            {sector.name}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {sector.slug}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {sector.requires_proof
                                                ? 'Required'
                                                : 'Not required'}
                                        </td>

                                        <td className="px-5 py-4">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs ${
                                                    sector.is_active
                                                        ? 'bg-[#edf4ea] text-[#3f6f3d]'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {sector.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {sector.residents_count}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {referencesFor(sector)}
                                        </td>

                                        <td className="px-5 py-4 text-right">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(sector)}
                                                className="text-xs font-medium text-[#3f6f3d] hover:underline"
                                            >
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {sectors.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-gray-400">
                            No sectors have been defined yet.
                        </p>
                    )}
                    </div>
                    </>
                )}

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

                {activeTab === 'lupon' && (
                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <div className="px-5 py-10 text-center">
                            <h2 className="text-lg font-semibold text-gray-800">
                                Lupon Cases
                            </h2>

                            <p className="mt-2 text-sm text-gray-500">
                                Lupon case counts are unavailable because case
                                records are not yet connected.
                            </p>

                            <p className="mt-4 text-xs text-gray-400">
                                No case data source currently exists, so no
                                counts are shown. Case management is not
                                available from this page.
                            </p>
                        </div>
                    </div>
                )}

                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-lg rounded-xl bg-white shadow-xl">
                            <div className="border-b border-gray-200 px-5 py-4">
                                <h2 className="text-lg font-semibold">
                                    Edit Sector
                                </h2>
                            </div>

                            <form
                                onSubmit={handleSubmit}
                                className="space-y-4 p-5"
                            >
                                <div>
                                    <input
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        placeholder="Sector Name"
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                        required
                                    />

                                    {form.errors.name && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {form.errors.name}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <input
                                        value={form.data.slug}
                                        onChange={(e) =>
                                            form.setData('slug', e.target.value)
                                        }
                                        placeholder="Slug (optional, auto-generated from name)"
                                        disabled={!!editing}
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm disabled:bg-gray-100 disabled:text-gray-400"
                                    />

                                    {editing && (
                                        <p className="mt-1 text-xs text-gray-400">
                                            The slug is fixed after creation.
                                        </p>
                                    )}

                                    {form.errors.slug && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {form.errors.slug}
                                        </p>
                                    )}
                                </div>

                                <label className="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.requires_proof}
                                        onChange={(e) =>
                                            form.setData(
                                                'requires_proof',
                                                e.target.checked,
                                            )
                                        }
                                        className="rounded border-gray-300"
                                    />
                                    Requires supporting proof
                                </label>

                                <label className="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_active}
                                        onChange={(e) =>
                                            form.setData(
                                                'is_active',
                                                e.target.checked,
                                            )
                                        }
                                        className="rounded border-gray-300"
                                    />
                                    Active
                                </label>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={closeModal}
                                        className="rounded-md border border-gray-300 px-4 py-2.5 text-sm"
                                    >
                                        Cancel
                                    </button>

                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm text-white disabled:opacity-50"
                                    >
                                        {form.processing
                                            ? 'Saving...'
                                            : 'Save Changes'}
                                    </button>
                                </div>
                            </form>
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
            className={`border-b-2 pb-3 text-sm font-medium ${
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
|
| Shared by the PWD Members and Senior Citizens tabs. Both fields are URL
| driven: submitting the search or picking a purok reloads only the roster
| payloads, so the values survive pagination and a browser refresh.
|
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
