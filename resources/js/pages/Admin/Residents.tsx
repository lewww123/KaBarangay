import { Head, router } from '@inertiajs/react';
import {
    FormEvent,
    ReactNode,
    useEffect,
    useRef,
    useState,
} from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type Sector = {
    id: number;
    name: string;
    slug: 'senior' | 'pwd' | string;
};

type RegistrationDocument = {
    id: number;
    type: string;
    label: string;
    mime_type: string | null;
    file_size: number | null;
    verification_status: string;
    url: string;
    is_image: boolean;
};

type PendingRegistration = {
    id: number;
    reference: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    birthdate: string;
    sex: string;
    civil_status: string;
    address_line: string;
    mobile: string;
    email: string | null;
    barangay: string | null;
    purok: string | null;
    sectors: Sector[];
    documents: RegistrationDocument[];
    status: 'pending' | 'needs_correction' | 'rejected' | 'approved';
    submitted_at: string | null;
    review_notes?: string | null;
    reviewed_at?: string | null;
    last_resubmitted_at?: string | null;
};

type Stats = {
    totalResidents: number;
    activeResidents: number;
    pendingRegistrations: number;
};

type Purok = {
    id: number;
    name: string;
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

type AllResidentRow = {
    type: 'resident' | 'registration';
    id: number;
    reference: string;
    fullName: string;
    sex: string | null;
    purok: string;
    sectors: Sector[];
    status: string;
    accountActive: boolean | null;
    qrStatus: 'active' | 'revoked' | 'none' | null;
};

type ResidentDetails = {
    resident_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    birthdate: string | null;
    sex: string | null;
    civil_status: string | null;
    address_line: string | null;
    mobile: string | null;
    email: string | null;
    status: string;
    approved_at: string | null;
    barangay: string | null;
    purok: string | null;
    account_active: boolean;
    sectors: Sector[];
};

type RegistrationDetails = {
    reference: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    birthdate: string | null;
    sex: string | null;
    civil_status: string | null;
    address_line: string | null;
    mobile: string | null;
    email: string | null;
    status: string;
    review_notes: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    last_resubmitted_at: string | null;
    barangay: string | null;
    purok: string | null;
    sectors: Sector[];
    documents: RegistrationDocument[];
};

type DetailsTarget = 'resident' | 'registration';

type DetailsState = {
    loading: boolean;
    error: string | null;
    type: DetailsTarget;
    resident: ResidentDetails | null;
    registration: RegistrationDetails | null;
};

type Props = {
    allResidents?: Paginator<AllResidentRow>;
    pendingRegistrations?: Paginator<PendingRegistration>;
    needsCorrectionRegistrations?: Paginator<PendingRegistration>;
    rejectedRegistrations?: Paginator<PendingRegistration>;
    puroks?: Purok[];
    residents?: Paginator<Resident>;
    filters?: {
        resident_search?: string;
        registration_search?: string;
        purok_id?: number | null;
    };
    stats?: Stats;
    flash?: { success?: string | null; error?: string | null };
};

type Tab = 'all' | 'pending' | 'approved' | 'needs_correction' | 'rejected';

type ResidentStatus = 'active' | 'inactive';

type Resident = {
    id: number;
    residentId: string;
    fullName: string;
    sex: string;
    purok: string;
    sectors: Sector[];
    status: ResidentStatus;
    accountActive: boolean;
    qrStatus: 'active' | 'revoked' | 'none';
};

type ResidentPaginator = Paginator<Resident>;

type AllResidentsPaginator = Paginator<AllResidentRow>;

type RegistrationPaginator = Paginator<PendingRegistration>;

const emptyPaginator = <T,>(): Paginator<T> => ({
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
});

const RESIDENT_PARTIAL_PROPS = [
    'allResidents',
    'pendingRegistrations',
    'needsCorrectionRegistrations',
    'rejectedRegistrations',
    'residents',
    'filters',
];

export default function Residents({
    allResidents,
    pendingRegistrations,
    needsCorrectionRegistrations,
    rejectedRegistrations,
    puroks = [],
    residents,
    filters,
    stats = {
        totalResidents: 0,
        activeResidents: 0,
        pendingRegistrations: 0,
    },
    flash,
}: Props) {
    const safePuroks = Array.isArray(puroks) ? puroks : [];

    const selectedPurokId =
        typeof filters?.purok_id === 'number' ? filters.purok_id : null;

    const paginator: ResidentPaginator = residents ?? emptyPaginator<Resident>();

    const allPaginator: AllResidentsPaginator =
        allResidents ?? emptyPaginator<AllResidentRow>();

    const pendingPaginator: RegistrationPaginator =
        pendingRegistrations ?? emptyPaginator<PendingRegistration>();

    const needsCorrectionPaginator: RegistrationPaginator =
        needsCorrectionRegistrations ?? emptyPaginator<PendingRegistration>();

    const rejectedPaginator: RegistrationPaginator =
        rejectedRegistrations ?? emptyPaginator<PendingRegistration>();

    const pendingRows = Array.isArray(pendingPaginator.data)
        ? pendingPaginator.data
        : [];

    const needsCorrectionRows = Array.isArray(needsCorrectionPaginator.data)
        ? needsCorrectionPaginator.data
        : [];

    const rejectedRows = Array.isArray(rejectedPaginator.data)
        ? rejectedPaginator.data
        : [];

    const approvedResidents = Array.isArray(paginator.data)
        ? paginator.data
        : [];

    const [activeTab, setActiveTab] = useState<Tab>('all');
    const [registrationSearch, setRegistrationSearch] = useState(
        filters?.registration_search ?? '',
    );
    const [approvedSearch, setApprovedSearch] = useState(
        filters?.resident_search ?? '',
    );
    const [selectedApplication, setSelectedApplication] =
        useState<PendingRegistration | null>(null);
    const [rejectingApplication, setRejectingApplication] =
        useState<PendingRegistration | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [rejectError, setRejectError] = useState('');
    const [processingReject, setProcessingReject] = useState(false);

    const [processingApproveId, setProcessingApproveId] =
        useState<number | null>(null);
    const [approveError, setApproveError] = useState('');
    const [residentActionError, setResidentActionError] = useState('');

    const [details, setDetails] = useState<DetailsState | null>(null);
    const detailsRequestId = useRef(0);

    const lastQueried = useRef(filters?.resident_search ?? '');
    const lastQueriedRegistration = useRef(
        filters?.registration_search ?? '',
    );

    /*
     * Partial reloads keep every tab's independent pagination state intact so
     * changing one tab's search or the purok filter never discards another
     * tab's current page. `overrides` reset only the pages that must restart.
     */
    const navigateResidentsWith = (
        overrides: Record<string, string | number | undefined>,
    ) => {
        router.get(
            '/admin/residents',
            {
                resident_search: approvedSearch.trim() || undefined,
                registration_search: registrationSearch.trim() || undefined,
                purok: selectedPurokId ?? undefined,
                page: paginator.current_page,
                all_page: allPaginator.current_page,
                pending_page: pendingPaginator.current_page,
                needs_correction_page: needsCorrectionPaginator.current_page,
                rejected_page: rejectedPaginator.current_page,
                ...overrides,
            },
            {
                only: RESIDENT_PARTIAL_PROPS,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    useEffect(() => {
        if (activeTab !== 'approved' && activeTab !== 'all') {
            return;
        }

        if (approvedSearch === lastQueried.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastQueried.current = approvedSearch;

            navigateResidentsWith({ page: 1, all_page: 1 });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [approvedSearch, activeTab, filters?.purok_id]);

    useEffect(() => {
        if (activeTab === 'all' || activeTab === 'approved') {
            return;
        }

        if (registrationSearch === lastQueriedRegistration.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastQueriedRegistration.current = registrationSearch;

            navigateResidentsWith({
                pending_page: 1,
                needs_correction_page: 1,
                rejected_page: 1,
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [registrationSearch, activeTab]);

    const navigateResidents = (url: string) => {
        router.get(
            url,
            {},
            {
                only: RESIDENT_PARTIAL_PROPS,
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const selectPurok = (purokId: number | null) => {
        if (purokId === selectedPurokId) {
            return;
        }

        navigateResidentsWith({
            purok: purokId ?? undefined,
            page: 1,
            all_page: 1,
            pending_page: 1,
            needs_correction_page: 1,
            rejected_page: 1,
        });
    };

    const openResidentDetails = (residentId: number) => {
        loadDetails('resident', residentId);
    };

    const openRegistrationDetails = (registrationId: number) => {
        loadDetails('registration', registrationId);
    };

    const loadDetails = (type: DetailsTarget, id: number) => {
        detailsRequestId.current += 1;
        const requestId = detailsRequestId.current;

        setDetails({
            loading: true,
            error: null,
            type,
            resident: null,
            registration: null,
        });

        const url =
            type === 'resident'
                ? `/admin/residents/${id}/details`
                : `/admin/resident-registrations/${id}/details`;

        fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Request failed');
                }

                return response.json();
            })
            .then((payload) => {
                if (requestId !== detailsRequestId.current) {
                    return;
                }

                setDetails({
                    loading: false,
                    error: null,
                    type,
                    resident:
                        type === 'resident'
                            ? (payload.data as ResidentDetails)
                            : null,
                    registration:
                        type === 'registration'
                            ? (payload.data as RegistrationDetails)
                            : null,
                });
            })
            .catch(() => {
                if (requestId !== detailsRequestId.current) {
                    return;
                }

                setDetails({
                    loading: false,
                    error: 'Unable to load the details for this record. Please try again.',
                    type,
                    resident: null,
                    registration: null,
                });
            });
    };

    const closeDetails = () => {
        detailsRequestId.current += 1;
        setDetails(null);
    };

    const toggleResidentStatus = (resident: Resident) => {
        const next: ResidentStatus =
            resident.status === 'active' ? 'inactive' : 'active';

        if (
            next === 'inactive' &&
            !window.confirm(
                `Deactivate ${resident.fullName}? They will lose portal access until reactivated.`,
            )
        ) {
            return;
        }

        setResidentActionError('');

        router.patch(
            `/admin/residents/${resident.id}/status`,
            { status: next },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setResidentActionError(
                        typeof errors.status === 'string'
                            ? errors.status
                            : 'Unable to update the resident status.',
                    );
                },
            },
        );
    };

    const toggleAccountStatus = (resident: Resident) => {
        const next = !resident.accountActive;

        if (
            !next &&
            !window.confirm(
                `Disable the login account for ${resident.fullName}? They will not be able to sign in.`,
            )
        ) {
            return;
        }

        setResidentActionError('');

        router.patch(
            `/admin/residents/${resident.id}/account-status`,
            { is_active: next },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setResidentActionError(
                        typeof errors.is_active === 'string'
                            ? errors.is_active
                            : 'Unable to update the login account.',
                    );
                },
            },
        );
    };

    const revokeQrCredential = (resident: Resident) => {
        if (
            !window.confirm(
                `Revoke the QR credential for ${resident.fullName}? Their current QR code will stop working immediately.`,
            )
        ) {
            return;
        }

        setResidentActionError('');

        router.post(
            `/admin/residents/${resident.id}/qr/revoke`,
            {},
            {
                preserveScroll: true,
                onError: (errors) => {
                    setResidentActionError(
                        typeof errors.qr === 'string'
                            ? errors.qr
                            : 'Unable to revoke the QR credential.',
                    );
                },
            },
        );
    };

    const reissueQrCredential = (resident: Resident) => {
        if (
            !window.confirm(
                `Reissue the QR credential for ${resident.fullName}? Any previously issued QR code will stop working immediately.`,
            )
        ) {
            return;
        }

        setResidentActionError('');

        router.post(
            `/admin/residents/${resident.id}/qr/reissue`,
            {},
            {
                preserveScroll: true,
                onError: () => {
                    setResidentActionError(
                        'Unable to reissue the QR credential.',
                    );
                },
            },
        );
    };

    const openReject = (application: PendingRegistration) => {
        setSelectedApplication(null);
        setRejectingApplication(application);
        setRejectionReason('');
        setRejectError('');
        setApproveError('');
    };

    const approveRegistration = (application: PendingRegistration) => {
        if (processingApproveId !== null) {
            return;
        }

        const confirmed = window.confirm(
            `Approve ${getApplicantName(application)} as a permanent resident?`,
        );

        if (!confirmed) {
            return;
        }

        setProcessingApproveId(application.id);
        setApproveError('');

        router.post(
            `/admin/residents/${application.id}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApplication(null);
                    setApproveError('');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.approval === 'string'
                            ? errors.approval
                            : 'Unable to approve this registration. Please review the application and try again.';

                    setApproveError(message);
                },
                onFinish: () => {
                    setProcessingApproveId(null);
                },
            },
        );
    };

    const rejectRegistration = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!rejectingApplication) {
            return;
        }

        const reason = rejectionReason.trim();

        if (reason.length < 5) {
            setRejectError('Please enter a clear rejection reason.');
            return;
        }

        setProcessingReject(true);
        setRejectError('');

        router.post(
            `/admin/residents/${rejectingApplication.id}/reject`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectingApplication(null);
                    setRejectionReason('');
                    setRejectError('');
                },
                onError: (errors) => {
                    setRejectError(
                        typeof errors.reason === 'string'
                            ? errors.reason
                            : 'Unable to reject this application.',
                    );
                },
                onFinish: () => {
                    setProcessingReject(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Residents" />

            <AdminLayout active="residents">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Residents
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review resident registrations and manage approved residents
                    </p>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <SummaryCard
                        title="Total Residents"
                        value={stats.totalResidents}
                    />
                    <SummaryCard
                        title="Active Residents"
                        value={stats.activeResidents}
                    />
                    <SummaryCard
                        title="Pending Registrations"
                        value={stats.pendingRegistrations}
                        highlight={stats.pendingRegistrations > 0}
                    />
                </div>

                <PurokTabs
                    puroks={safePuroks}
                    selected={selectedPurokId}
                    onSelect={selectPurok}
                />

                <div className="mb-5 flex flex-col gap-4 border-b border-gray-200 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex overflow-x-auto">
                        <TabButton
                            label={`All Residents (${allPaginator.total})`}
                            active={activeTab === 'all'}
                            onClick={() => setActiveTab('all')}
                        />
                        <TabButton
                            label={`Pending (${pendingPaginator.total})`}
                            active={activeTab === 'pending'}
                            onClick={() => setActiveTab('pending')}
                        />
                        <TabButton
                            label={`Approved (${paginator.total})`}
                            active={activeTab === 'approved'}
                            onClick={() => setActiveTab('approved')}
                        />
                        <TabButton
                            label={`Needs Correction (${needsCorrectionPaginator.total})`}
                            active={activeTab === 'needs_correction'}
                            onClick={() => setActiveTab('needs_correction')}
                        />
                        <TabButton
                            label={`Rejected (${rejectedPaginator.total})`}
                            active={activeTab === 'rejected'}
                            onClick={() => setActiveTab('rejected')}
                        />
                    </div>

                    <div className="pb-3">
                        <input
                            type="text"
                            value={
                                activeTab === 'all' || activeTab === 'approved'
                                    ? approvedSearch
                                    : registrationSearch
                            }
                            onChange={(event) => {
                                if (
                                    activeTab === 'all' ||
                                    activeTab === 'approved'
                                ) {
                                    setApprovedSearch(event.target.value);
                                } else {
                                    setRegistrationSearch(event.target.value);
                                }
                            }}
                            placeholder={
                                activeTab === 'all' || activeTab === 'approved'
                                    ? 'Search resident...'
                                    : 'Search applicant...'
                            }
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] sm:w-72"
                        />
                    </div>
                </div>

                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-[#b7d8b2] bg-[#edf4ea] px-4 py-3">
                        <p className="text-sm font-medium text-[#3f6f3d]">
                            {flash.success}
                        </p>
                    </div>
                )}

                {approveError && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <p className="text-sm font-medium text-red-700">
                            {approveError}
                        </p>
                    </div>
                )}

                {residentActionError && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <p className="text-sm font-medium text-red-700">
                            {residentActionError}
                        </p>
                    </div>
                )}

                {activeTab === 'all' && (
                    <AllResidentsTable
                        paginator={allPaginator}
                        onNavigate={navigateResidents}
                        onViewResident={openResidentDetails}
                        onViewRegistration={openRegistrationDetails}
                    />
                )}

                {activeTab === 'approved' && (
                    <ApprovedResidentsTable
                        residents={approvedResidents}
                        paginator={paginator}
                        onNavigate={navigateResidents}
                        onToggleStatus={toggleResidentStatus}
                        onToggleAccount={toggleAccountStatus}
                        onRevokeQr={revokeQrCredential}
                        onReissueQr={reissueQrCredential}
                        onView={(resident) =>
                            openResidentDetails(resident.id)
                        }
                    />
                )}

                {activeTab === 'pending' && (
                    <PendingRegistrationsTable
                        applications={pendingRows}
                        paginator={pendingPaginator}
                        onNavigate={navigateResidents}
                        searchActive={registrationSearch.trim() !== ''}
                        onView={(application) => {
                            setApproveError('');
                            setSelectedApplication(application);
                        }}
                        onReject={openReject}
                        onApprove={approveRegistration}
                        processingApproveId={processingApproveId}
                    />
                )}

                {activeTab === 'needs_correction' && (
                    <PendingRegistrationsTable
                        applications={needsCorrectionRows}
                        paginator={needsCorrectionPaginator}
                        onNavigate={navigateResidents}
                        searchActive={registrationSearch.trim() !== ''}
                        onView={(application) => {
                            setApproveError('');
                            setSelectedApplication(application);
                        }}
                        onReject={openReject}
                        onApprove={approveRegistration}
                        processingApproveId={processingApproveId}
                    />
                )}

                {activeTab === 'rejected' && (
                    <RejectedRegistrationsTable
                        applications={rejectedRows}
                        paginator={rejectedPaginator}
                        onNavigate={navigateResidents}
                        searchActive={registrationSearch.trim() !== ''}
                        onView={(application) =>
                            openRegistrationDetails(application.id)
                        }
                    />
                )}

                {selectedApplication && (
                    <ApplicationModal
                        application={selectedApplication}
                        onClose={() => {
                            if (processingApproveId !== null) {
                                return;
                            }

                            setSelectedApplication(null);
                            setApproveError('');
                        }}
                        onReject={() => openReject(selectedApplication)}
                        onApprove={() =>
                            approveRegistration(selectedApplication)
                        }
                        processingApprove={
                            processingApproveId === selectedApplication.id
                        }
                    />
                )}

                {rejectingApplication && (
                    <RejectModal
                        application={rejectingApplication}
                        reason={rejectionReason}
                        setReason={(value) => {
                            setRejectionReason(value);
                            if (rejectError) {
                                setRejectError('');
                            }
                        }}
                        error={rejectError}
                        processing={processingReject}
                        onCancel={() => {
                            if (processingReject) {
                                return;
                            }

                            setRejectingApplication(null);
                            setRejectionReason('');
                            setRejectError('');
                        }}
                        onSubmit={rejectRegistration}
                    />
                )}

                {details && (
                    <ResidentDetailsModal details={details} onClose={closeDetails} />
                )}
            </AdminLayout>
        </>
    );
}

function ApprovedResidentsTable({
    residents,
    paginator,
    onNavigate,
    onToggleStatus,
    onToggleAccount,
    onRevokeQr,
    onReissueQr,
    onView,
}: {
    residents: Resident[];
    paginator: ResidentPaginator;
    onNavigate: (url: string) => void;
    onToggleStatus: (resident: Resident) => void;
    onToggleAccount: (resident: Resident) => void;
    onRevokeQr: (resident: Resident) => void;
    onReissueQr: (resident: Resident) => void;
    onView: (resident: Resident) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1120px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Resident ID</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Resident Status</TableHead>
                            <TableHead>Login Account</TableHead>
                            <TableHead>QR Code</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {residents.map((resident, index) => (
                            <tr
                                key={resident.id}
                                className={
                                    index !== residents.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4 font-mono text-xs font-semibold text-[#3f6f3d]">
                                    {resident.residentId}
                                </td>
                                <td className="px-5 py-4">
                                    <button
                                        type="button"
                                        onClick={() => onView(resident)}
                                        className="text-left text-sm font-medium text-gray-800 hover:text-[#3f6f3d] hover:underline"
                                    >
                                        {resident.fullName}
                                    </button>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {resident.sex}
                                    </p>
                                </td>
                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {resident.purok}
                                </td>
                                <td className="px-5 py-4">
                                    <SectorBadges sectors={resident.sectors} />
                                </td>
                                <td className="px-5 py-4">
                                    <StatusBadge
                                        active={resident.status === 'active'}
                                        activeLabel="Active"
                                        inactiveLabel="Inactive"
                                    />
                                </td>
                                <td className="px-5 py-4">
                                    <StatusBadge
                                        active={resident.accountActive}
                                        activeLabel="Enabled"
                                        inactiveLabel="Disabled"
                                    />
                                </td>
                                <td className="px-5 py-4">
                                    <StatusBadge
                                        active={resident.qrStatus === 'active'}
                                        activeLabel="Active"
                                        inactiveLabel={
                                            resident.qrStatus === 'revoked'
                                                ? 'Revoked'
                                                : 'Not issued'
                                        }
                                    />
                                </td>
                                <td className="px-5 py-4">
                                    <div className="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onToggleStatus(resident)
                                            }
                                            className="text-xs font-medium text-[#3f6f3d] hover:underline"
                                        >
                                            {resident.status === 'active'
                                                ? 'Deactivate'
                                                : 'Activate'}
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onToggleAccount(resident)
                                            }
                                            className="text-xs font-medium text-[#3f6f3d] hover:underline"
                                        >
                                            {resident.accountActive
                                                ? 'Disable Login'
                                                : 'Enable Login'}
                                        </button>

                                        {resident.qrStatus === 'active' && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onRevokeQr(resident)
                                                }
                                                className="text-xs font-medium text-red-600 hover:underline"
                                            >
                                                Revoke QR
                                            </button>
                                        )}

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onReissueQr(resident)
                                            }
                                            className="text-xs font-medium text-[#3f6f3d] hover:underline"
                                        >
                                            {resident.qrStatus === 'none'
                                                ? 'Issue QR'
                                                : 'Reissue QR'}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}

                        {residents.length === 0 && (
                            <tr>
                                <td
                                    colSpan={8}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        No approved residents yet
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        Approved resident accounts will appear here.
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination paginator={paginator} onNavigate={onNavigate} />
        </div>
    );
}

function StatusBadge({
    active,
    activeLabel,
    inactiveLabel,
}: {
    active: boolean;
    activeLabel: string;
    inactiveLabel: string;
}) {
    return (
        <span
            className={`inline-flex rounded-full px-3 py-1 text-xs font-medium ${
                active
                    ? 'bg-[#edf4ea] text-[#3f6f3d]'
                    : 'bg-gray-100 text-gray-500'
            }`}
        >
            {active ? activeLabel : inactiveLabel}
        </span>
    );
}

function Pagination<T>({
    paginator,
    onNavigate,
    label = 'residents',
}: {
    paginator: Paginator<T>;
    onNavigate: (url: string) => void;
    label?: string;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t border-gray-100 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-gray-500">
                Showing {paginator.from ?? 0}–{paginator.to ?? 0} of{' '}
                {paginator.total} {label}
            </p>

            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled={!paginator.prev_page_url}
                    onClick={() =>
                        paginator.prev_page_url &&
                        onNavigate(paginator.prev_page_url)
                    }
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
                    onClick={() =>
                        paginator.next_page_url &&
                        onNavigate(paginator.next_page_url)
                    }
                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Next
                </button>
            </div>
        </div>
    );
}

function PendingRegistrationsTable({
    applications,
    paginator,
    onNavigate,
    searchActive,
    onView,
    onReject,
    onApprove,
    processingApproveId,
}: {
    applications: PendingRegistration[];
    paginator: RegistrationPaginator;
    onNavigate: (url: string) => void;
    searchActive: boolean;
    onView: (application: PendingRegistration) => void;
    onReject: (application: PendingRegistration) => void;
    onApprove: (application: PendingRegistration) => void;
    processingApproveId: number | null;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[950px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Applicant</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Submitted</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        {applications.map((application, index) => (
                            <tr
                                key={application.id}
                                className={
                                    index !== applications.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-gray-800">
                                        {getApplicantName(application)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {application.mobile}
                                    </p>
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {application.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges sectors={application.sectors} />
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-500">
                                    {application.submitted_at || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            onClick={() => onView(application)}
                                            className="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                        >
                                            View
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => onReject(application)}
                                            className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50"
                                        >
                                            Reject
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => onApprove(application)}
                                            disabled={
                                                processingApproveId !== null
                                            }
                                            className="rounded-md bg-[#3f6f3d] px-3 py-2 text-xs font-medium text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {processingApproveId === application.id
                                                ? 'Approving...'
                                                : 'Approve'}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}

                        {applications.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        {searchActive
                                            ? 'No matching registrations'
                                            : 'No pending registrations'}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        {searchActive
                                            ? 'Try a different name, ID, or contact number.'
                                            : 'New resident registrations will appear here.'}
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination
                paginator={paginator}
                onNavigate={onNavigate}
                label="registrations"
            />
        </div>
    );
}

function AllResidentsTable({
    paginator,
    onNavigate,
    onViewResident,
    onViewRegistration,
}: {
    paginator: AllResidentsPaginator;
    onNavigate: (url: string) => void;
    onViewResident: (id: number) => void;
    onViewRegistration: (id: number) => void;
}) {
    const rows = Array.isArray(paginator.data) ? paginator.data : [];

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1080px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Type</TableHead>
                            <TableHead>ID / Reference</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Status</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        {rows.map((row, index) => (
                            <tr
                                key={`${row.type}-${row.id}`}
                                className={
                                    index !== rows.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <span
                                        className={`inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide ${
                                            row.type === 'resident'
                                                ? 'bg-[#edf4ea] text-[#3f6f3d]'
                                                : 'bg-[#eef3fb] text-[#416b9c]'
                                        }`}
                                    >
                                        {row.type === 'resident'
                                            ? 'Resident'
                                            : 'Registration'}
                                    </span>
                                </td>

                                <td className="px-5 py-4 font-mono text-xs font-semibold text-gray-600">
                                    {row.reference}
                                </td>

                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-gray-800">
                                        {row.fullName}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {row.sex || '—'}
                                    </p>
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {row.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges sectors={row.sectors} />
                                </td>

                                <td className="px-5 py-4">
                                    <StatusPill status={row.status} />
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                row.type === 'resident'
                                                    ? onViewResident(row.id)
                                                    : onViewRegistration(row.id)
                                            }
                                            className="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                        >
                                            View
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}

                        {rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={7}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        No residents found
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        Approved residents and pending
                                        registrations will appear here.
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination
                paginator={paginator}
                onNavigate={onNavigate}
                label="records"
            />
        </div>
    );
}

function RejectedRegistrationsTable({
    applications,
    paginator,
    onNavigate,
    searchActive,
    onView,
}: {
    applications: PendingRegistration[];
    paginator: RegistrationPaginator;
    onNavigate: (url: string) => void;
    searchActive: boolean;
    onView: (application: PendingRegistration) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[950px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Applicant</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Reviewed</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        {applications.map((application, index) => (
                            <tr
                                key={application.id}
                                className={
                                    index !== applications.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-gray-800">
                                        {getApplicantName(application)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {application.mobile}
                                    </p>
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {application.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges
                                        sectors={application.sectors}
                                    />
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-500">
                                    {application.reviewed_at ||
                                        application.submitted_at ||
                                        '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => onView(application)}
                                            className="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                        >
                                            View
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}

                        {applications.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        {searchActive
                                            ? 'No matching rejected registrations'
                                            : 'No rejected registrations'}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        {searchActive
                                            ? 'Try a different name, ID, or contact number.'
                                            : 'Rejected registrations will appear here.'}
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Pagination
                paginator={paginator}
                onNavigate={onNavigate}
                label="registrations"
            />
        </div>
    );
}

function StatusPill({ status }: { status: string }) {
    const normalized = (status || '').toLowerCase();

    const styles: Record<string, string> = {
        active: 'bg-[#edf4ea] text-[#3f6f3d]',
        approved: 'bg-[#edf4ea] text-[#3f6f3d]',
        pending: 'bg-[#fff4d8] text-[#946b00]',
        needs_correction: 'bg-[#fde8e8] text-[#b23b3b]',
        rejected: 'bg-red-50 text-red-600',
        inactive: 'bg-gray-100 text-gray-500',
    };

    return (
        <span
            className={`inline-flex rounded-full px-3 py-1 text-xs font-medium capitalize ${
                styles[normalized] ?? 'bg-gray-100 text-gray-600'
            }`}
        >
            {normalized.replace(/_/g, ' ') || '—'}
        </span>
    );
}

function fullNameOf(person: {
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
}) {
    return [person.first_name, person.middle_name, person.last_name, person.suffix]
        .filter(Boolean)
        .join(' ');
}

function ResidentDetailsBody({ resident }: { resident: ResidentDetails }) {
    return (
        <div className="space-y-5">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Info label="Resident ID" value={resident.resident_number} />
                <Info label="Full Name" value={fullNameOf(resident)} />
                <Info label="Birthdate" value={resident.birthdate || '—'} />
                <Info label="Sex" value={resident.sex || '—'} />
                <Info
                    label="Civil Status"
                    value={resident.civil_status || '—'}
                />
                <Info label="Mobile" value={resident.mobile || '—'} />
                <Info label="Email" value={resident.email || '—'} />
                <Info label="Address" value={resident.address_line || '—'} />
                <Info label="Barangay" value={resident.barangay || '—'} />
                <Info label="Purok" value={resident.purok || '—'} />
                <Info
                    label="Resident Status"
                    value={resident.status || '—'}
                />
                <Info label="Approved At" value={resident.approved_at || '—'} />
                <Info
                    label="Portal Account"
                    value={resident.account_active ? 'Active' : 'Inactive'}
                />
            </div>

            <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                    Sectors
                </p>
                <div className="mt-2">
                    <SectorBadges sectors={resident.sectors} />
                </div>
            </div>
        </div>
    );
}

function RegistrationDetailsBody({
    registration,
}: {
    registration: RegistrationDetails;
}) {
    return (
        <div className="space-y-5">
            <div className="flex items-center gap-2">
                <StatusPill status={registration.status} />
                <span className="font-mono text-xs text-gray-500">
                    {registration.reference}
                </span>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Info label="Full Name" value={fullNameOf(registration)} />
                <Info label="Birthdate" value={registration.birthdate || '—'} />
                <Info label="Sex" value={registration.sex || '—'} />
                <Info
                    label="Civil Status"
                    value={registration.civil_status || '—'}
                />
                <Info label="Mobile" value={registration.mobile || '—'} />
                <Info label="Email" value={registration.email || '—'} />
                <Info label="Address" value={registration.address_line || '—'} />
                <Info label="Barangay" value={registration.barangay || '—'} />
                <Info label="Purok" value={registration.purok || '—'} />
                <Info
                    label="Submitted At"
                    value={registration.submitted_at || '—'}
                />
                <Info
                    label="Reviewed At"
                    value={registration.reviewed_at || '—'}
                />
                <Info
                    label="Last Resubmitted"
                    value={registration.last_resubmitted_at || '—'}
                />
            </div>

            {registration.review_notes && (
                <div className="rounded-lg border border-gray-200 bg-[#fafaf7] p-4">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                        Review Notes
                    </p>
                    <p className="mt-1 whitespace-pre-line text-sm text-gray-700">
                        {registration.review_notes}
                    </p>
                </div>
            )}

            <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                    Sectors
                </p>
                <div className="mt-2">
                    <SectorBadges sectors={registration.sectors} />
                </div>
            </div>

            <div>
                <p className="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                    Documents
                </p>
                <ApplicationDocuments documents={registration.documents} />
            </div>
        </div>
    );
}

function ResidentDetailsModal({
    details,
    onClose,
}: {
    details: DetailsState;
    onClose: () => void;
}) {
    const isResident = details.type === 'resident';

    return (
        <ModalContainer>
            <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title={isResident ? 'Resident Details' : 'Registration Details'}
                    description={
                        isResident
                            ? 'Read-only summary of this resident record.'
                            : 'Read-only summary of this registration record.'
                    }
                    onClose={onClose}
                />

                <div className="max-h-[70vh] overflow-y-auto px-5 py-5">
                    {details.loading && (
                        <p className="text-sm text-gray-500">Loading…</p>
                    )}

                    {!details.loading && details.error && (
                        <p className="text-sm text-red-600">{details.error}</p>
                    )}

                    {!details.loading &&
                        !details.error &&
                        details.resident && (
                            <ResidentDetailsBody resident={details.resident} />
                        )}

                    {!details.loading &&
                        !details.error &&
                        details.registration && (
                            <RegistrationDetailsBody
                                registration={details.registration}
                            />
                        )}
                </div>

                <div className="flex justify-end border-t border-gray-200 px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    >
                        Close
                    </button>
                </div>
            </div>
        </ModalContainer>
    );
}

function ApplicationModal({
    application,
    onClose,
    onReject,
    onApprove,
    processingApprove,
}: {
    application: PendingRegistration;
    onClose: () => void;
    onReject: () => void;
    onApprove: () => void;
    processingApprove: boolean;
}) {
    return (
        <ModalContainer>
            <div className="w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title="Review Registration"
                    description="Verify the resident information before taking action"
                    onClose={onClose}
                />

                <div className="max-h-[75vh] overflow-y-auto p-6">
                    <div className="mb-5 flex flex-wrap gap-2">
                        <span className="rounded-full bg-[#fff4d8] px-3 py-1 text-xs font-medium capitalize text-[#946b00]">
                            {application.status.replace('_', ' ')}
                        </span>

                        <SectorBadges sectors={application.sectors} />
                    </div>

                    <div className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <Info
                            label="Full Name"
                            value={getApplicantName(application)}
                        />
                        <Info
                            label="Birthdate"
                            value={application.birthdate || '—'}
                        />
                        <Info label="Sex" value={application.sex || '—'} />
                        <Info
                            label="Civil Status"
                            value={application.civil_status || '—'}
                        />
                        <Info
                            label="Mobile Number"
                            value={application.mobile || '—'}
                        />
                        <Info
                            label="Email"
                            value={application.email || 'Not provided'}
                        />
                        <Info
                            label="Barangay"
                            value={application.barangay || '—'}
                        />
                        <Info
                            label="Purok"
                            value={application.purok || '—'}
                        />

                        <div className="sm:col-span-2">
                            <Info
                                label="Local Address"
                                value={application.address_line || '—'}
                            />
                        </div>

                        <Info
                            label="Submitted"
                            value={application.submitted_at || '—'}
                        />
                        <Info
                            label="Application Reference"
                            value={application.reference}
                        />
                    </div>

                    <div className="mt-6">
                        <div className="mb-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Identity Verification
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                Private registration documents. Only authorized barangay administrators can open these files.
                            </p>
                        </div>

                        <ApplicationDocuments
                            documents={
                                Array.isArray(application.documents)
                                    ? application.documents
                                    : []
                            }
                        />
                    </div>
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-6 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processingApprove}
                        className="rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Close
                    </button>

                    <button
                        type="button"
                        onClick={onReject}
                        disabled={processingApprove}
                        className="rounded-md border border-red-200 px-4 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Reject
                    </button>

                    <button
                        type="button"
                        onClick={onApprove}
                        disabled={processingApprove}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {processingApprove
                            ? 'Approving...'
                            : 'Approve Registration'}
                    </button>
                </div>
            </div>
        </ModalContainer>
    );
}

function ApplicationDocuments({
    documents,
}: {
    documents: RegistrationDocument[];
}) {
    if (documents.length === 0) {
        return (
            <div className="rounded-lg border border-gray-200 bg-[#fafaf7] p-4">
                <p className="text-sm font-medium text-gray-600">
                    No registration documents found.
                </p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {documents.map((document) => (
                <div
                    key={document.id}
                    className="overflow-hidden rounded-lg border border-gray-200 bg-white"
                >
                    <div className="border-b border-gray-100 bg-[#fafaf7] px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-gray-800">
                                    {document.label}
                                </p>
                                <p className="mt-0.5 text-[11px] text-gray-400">
                                    {document.mime_type || 'Document'}
                                    {document.file_size
                                        ? ` • ${formatFileSize(document.file_size)}`
                                        : ''}
                                </p>
                            </div>

                            <span className="rounded-full bg-[#fff4d8] px-2.5 py-1 text-[10px] font-medium capitalize text-[#946b00]">
                                {document.verification_status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>

                    {document.is_image ? (
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="block bg-gray-50"
                            title={`Open ${document.label}`}
                        >
                            <img
                                src={document.url}
                                alt={document.label}
                                className="h-56 w-full object-contain p-3"
                            />
                        </a>
                    ) : (
                        <div className="flex h-40 items-center justify-center bg-gray-50 p-4 text-center">
                            <div>
                                <p className="text-sm font-medium text-gray-600">
                                    Document preview
                                </p>
                                <p className="mt-1 text-xs text-gray-400">
                                    Open the file to review it securely.
                                </p>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end px-4 py-3">
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-md border border-[#b7d8b2] px-3 py-2 text-xs font-medium text-[#3f6f3d] transition hover:bg-[#edf4ea]"
                        >
                            {document.is_image ? 'View Full Image' : 'Open Document'}
                        </a>
                    </div>
                </div>
            ))}
        </div>
    );
}

function formatFileSize(bytes: number) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function RejectModal({
    application,
    reason,
    setReason,
    error,
    processing,
    onCancel,
    onSubmit,
}: {
    application: PendingRegistration;
    reason: string;
    setReason: (value: string) => void;
    error: string;
    processing: boolean;
    onCancel: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <ModalContainer>
            <div className="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title="Reject Registration"
                    description={getApplicantName(application)}
                    onClose={onCancel}
                />

                <form onSubmit={onSubmit}>
                    <div className="p-5">
                        <div className="mb-4 rounded-lg border border-red-100 bg-red-50 p-3">
                            <p className="text-xs leading-5 text-red-700">
                                A rejection reason is required. This will be saved with the application so the resident can be informed why the request was rejected.
                            </p>
                        </div>

                        <label className="mb-1.5 block text-xs font-medium text-gray-700">
                            Reason for rejection
                            <span className="ml-1 text-red-500">*</span>
                        </label>

                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                            maxLength={1000}
                            placeholder="Example: Valid ID is unclear. Please submit a clearer copy."
                            className={[
                                inputClass,
                                'resize-none',
                                error ? 'border-red-300' : '',
                            ].join(' ')}
                            autoFocus
                        />

                        <div className="mt-1.5 flex items-start justify-between gap-3">
                            <div>
                                {error && (
                                    <p className="text-xs font-medium text-red-600">
                                        {error}
                                    </p>
                                )}
                            </div>
                            <p className="shrink-0 text-[11px] text-gray-400">
                                {reason.length}/1000
                            </p>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={processing}
                            className="rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            disabled={
                                processing || rejectionReasonIsInvalid(reason)
                            }
                            className="rounded-md bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing
                                ? 'Rejecting...'
                                : 'Reject Registration'}
                        </button>
                    </div>
                </form>
            </div>
        </ModalContainer>
    );
}

const inputClass =
    'w-full rounded-md border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]';

function SummaryCard({
    title,
    value,
    highlight = false,
}: {
    title: string;
    value: number;
    highlight?: boolean;
}) {
    return (
        <div
            className={`rounded-lg border p-5 ${
                highlight
                    ? 'border-[#b7d8b2] bg-[#edf4ea]'
                    : 'border-gray-200 bg-white'
            }`}
        >
            <p
                className={`text-xs font-medium uppercase tracking-wide ${
                    highlight ? 'text-[#3f6f3d]' : 'text-gray-400'
                }`}
            >
                {title}
            </p>
            <p className="mt-3 text-3xl font-semibold text-gray-800">
                {value}
            </p>
        </div>
    );
}

function TabButton({
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
            className={`whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium ${
                active
                    ? 'border-[#3f6f3d] text-[#3f6f3d]'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
        >
            {label}
        </button>
    );
}

function PurokTabs({
    puroks,
    selected,
    onSelect,
}: {
    puroks: Purok[];
    selected: number | null;
    onSelect: (purokId: number | null) => void;
}) {
    return (
        <div className="mb-5">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                Filter by Purok
            </p>

            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => onSelect(null)}
                    className={`rounded-full border px-3.5 py-1.5 text-xs font-medium transition ${
                        selected === null
                            ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                            : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                    }`}
                >
                    All Puroks
                </button>

                {puroks.map((purok) => (
                    <button
                        key={purok.id}
                        type="button"
                        onClick={() => onSelect(purok.id)}
                        className={`rounded-full border px-3.5 py-1.5 text-xs font-medium transition ${
                            selected === purok.id
                                ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        {purok.name}
                    </button>
                ))}

                {puroks.length === 0 && (
                    <span className="text-xs text-gray-400">
                        No puroks are configured for this barangay.
                    </span>
                )}
            </div>
        </div>
    );
}

function TableHead({ children }: { children: ReactNode }) {
    return (
        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
            {children}
        </th>
    );
}

function SectorBadges({ sectors }: { sectors: Sector[] }) {
    const safeSectors = Array.isArray(sectors) ? sectors : [];

    if (safeSectors.length === 0) {
        return <span className="text-xs text-gray-400">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {safeSectors.map((sector) => {
                if (sector.slug === 'senior') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-[#edf4ea] px-2.5 py-1 text-xs font-medium text-[#3f6f3d]"
                        >
                            Senior
                        </span>
                    );
                }

                if (sector.slug === 'pwd') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-[#eef3fb] px-2.5 py-1 text-xs font-medium text-[#416b9c]"
                        >
                            PWD
                        </span>
                    );
                }

                return (
                    <span
                        key={sector.id}
                        className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600"
                    >
                        {sector.name}
                    </span>
                );
            })}
        </div>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                {label}
            </p>
            <p className="mt-1 break-words text-sm font-medium text-gray-800">
                {value}
            </p>
        </div>
    );
}

function ModalContainer({ children }: { children: ReactNode }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
            {children}
        </div>
    );
}

function ModalHeader({
    title,
    description,
    onClose,
}: {
    title: string;
    description: string;
    onClose: () => void;
}) {
    return (
        <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <div>
                <h2 className="text-lg font-semibold text-gray-800">
                    {title}
                </h2>
                <p className="mt-1 text-xs text-gray-500">{description}</p>
            </div>

            <button
                type="button"
                onClick={onClose}
                className="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100"
                aria-label="Close"
            >
                ×
            </button>
        </div>
    );
}

function getApplicantName(application: PendingRegistration) {
    return [
        application.first_name,
        application.middle_name,
        application.last_name,
        application.suffix,
    ]
        .filter(Boolean)
        .join(' ');
}

function rejectionReasonIsInvalid(reason: string) {
    return reason.trim().length < 5;
}
