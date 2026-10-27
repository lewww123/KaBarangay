import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    FormEvent,
    ReactNode,
    useEffect,
    useRef,
    useState,
} from 'react';
import { QRCodeSVG } from 'qrcode.react';
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
    is_superseded?: boolean;
    superseded_at?: string | null;
    sector_slug?: string | null;
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
    correction_field_options?: string[];
    correction_document_options?: string[];
};

type ResidentCandidate = {
    id: number;
    resident_number: string;
    full_name: string;
    birthdate: string | null;
    sex: string | null;
    purok: string | null;
    email: string | null;
    status: string;
    account_exists: boolean;
    account_active: boolean;
    qr_suggested?: boolean;
};

type Stats = {
    totalResidents: number;
    activeResidents: number;
    pendingRegistrations: number;
    pendingClaims: number;
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
    type: 'resident';
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
    id: number;
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
    email_verified: boolean;
    status: string;
    source: string;
    approved_at: string | null;
    barangay: string | null;
    purok: string | null;
    account_exists: boolean;
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
    reviewer: string | null;
    matched_at: string | null;
    last_resubmitted_at: string | null;
    barangay: string | null;
    purok: string | null;
    sectors: Sector[];
    documents: RegistrationDocument[];
    matched_resident: {
        id: number;
        resident_number: string;
        full_name: string;
        birthdate: string | null;
        email: string | null;
        status: string;
        purok: string | null;
        account_exists: boolean;
        account_active: boolean;
    } | null;
    correction_cycles?: Array<{
        cycle_number: number;
        instructions: string;
        field_targets: string[];
        document_targets: string[];
        reviewer: string | null;
        requested_at: string | null;
        resubmitted_at: string | null;
    }>;
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
    addedByAdmin?: Paginator<Resident>;
    filters?: {
        resident_search?: string;
        registration_search?: string;
        purok_id?: number | null;
    };
    stats?: Stats;
    flash?: { success?: string | null; error?: string | null };
};

type Tab =
    | 'all'
    | 'added_by_admin'
    | 'pending'
    | 'needs_correction'
    | 'rejected';

type ResidentStatus = 'active' | 'inactive';

type Resident = {
    id: number;
    residentId: string;
    fullName: string;
    sex: string;
    purok: string;
    sectors: Sector[];
    status: ResidentStatus;
    source: string;
    accountExists: boolean;
    accountActive: boolean;
    emailVerified: boolean;
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
    'addedByAdmin',
    'filters',
];

export default function Residents({
    allResidents,
    pendingRegistrations,
    needsCorrectionRegistrations,
    rejectedRegistrations,
    puroks = [],
    addedByAdmin,
    filters,
    stats = {
        totalResidents: 0,
        activeResidents: 0,
        pendingRegistrations: 0,
        pendingClaims: 0,
    },
    flash,
}: Props) {
    const safePuroks = Array.isArray(puroks) ? puroks : [];

    const selectedPurokId =
        typeof filters?.purok_id === 'number' ? filters.purok_id : null;

    const addedByAdminPaginator: ResidentPaginator =
        addedByAdmin ?? emptyPaginator<Resident>();

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

    const adminAddedResidents = Array.isArray(addedByAdminPaginator.data)
        ? addedByAdminPaginator.data
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
    const [correctingApplication, setCorrectingApplication] =
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

    const [showCreateResident, setShowCreateResident] = useState(false);
    const createResidentForm = useForm({
        purok_id: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        suffix: '',
        birthdate: '',
        sex: '',
        civil_status: '',
        address_line: '',
        mobile: '',
        email: '',
    });

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
                added_page: addedByAdminPaginator.current_page,
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
        if (activeTab !== 'added_by_admin' && activeTab !== 'all') {
            return;
        }

        if (approvedSearch === lastQueried.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastQueried.current = approvedSearch;

            navigateResidentsWith({ all_page: 1, added_page: 1 });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [approvedSearch, activeTab, filters?.purok_id]);

    useEffect(() => {
        if (activeTab === 'all' || activeTab === 'added_by_admin') {
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
            all_page: 1,
            added_page: 1,
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
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Could not load these details (HTTP ${response.status}).`);
                }

                const payload = await response.json();
                if (
                    !payload ||
                    payload.type !== type ||
                    !payload.data ||
                    typeof payload.data !== 'object'
                ) {
                    throw new Error('The server returned details in an unexpected format.');
                }
                return payload;
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
            .catch((error: unknown) => {
                if (requestId !== detailsRequestId.current) {
                    return;
                }

                setDetails({
                    loading: false,
                    error: error instanceof Error
                        ? error.message
                        : 'Unable to load the details for this record. Please try again.',
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

    const submitCreateResident = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        createResidentForm.post('/admin/residents', {
            preserveScroll: true,
            onSuccess: () => {
                createResidentForm.reset();
                setShowCreateResident(false);
            },
        });
    };

    const openReject = (application: PendingRegistration) => {
        setSelectedApplication(null);
        setRejectingApplication(application);
        setRejectionReason('');
        setRejectError('');
        setApproveError('');
    };

    const openApplicationReview = (application: PendingRegistration) => {
        setApproveError('');
        setSelectedApplication(application);
    };

    const approveRegistration = (
        application: PendingRegistration,
        residentId: number,
    ) => {
        if (processingApproveId !== null || !Number.isInteger(residentId)) {
            return;
        }

        const confirmed = window.confirm(
            `Link ${getApplicantName(application)} to the selected official Resident record? This will create portal access without changing the Resident's existing identity or QR record.`,
        );

        if (!confirmed) {
            return;
        }

        setProcessingApproveId(application.id);
        setApproveError('');

        router.post(
            `/admin/residents/${application.id}/approve`,
            { resident_id: residentId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApplication(null);
                    setApproveError('');
                },
                onError: (errors) => {
                    const message =
                        (typeof errors.resident_id === 'string' && errors.resident_id) ||
                        (typeof errors.approval === 'string' && errors.approval) ||
                        'Unable to link this registration. Please review the application and selected Resident.';

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
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">Resident Management</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Manage verified online accounts and official Resident records
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setShowCreateResident(true)}
                            className="inline-flex min-h-10 items-center justify-center rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                        >
                            Add Resident
                        </button>
                    </div>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <SummaryCard
                        title="Total Residents"
                        value={stats.totalResidents}
                    />
                    <SummaryCard
                        title="Active Residents"
                        value={stats.activeResidents}
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
                            label={`Added by Admin (${addedByAdminPaginator.total})`}
                            active={activeTab === 'added_by_admin'}
                            onClick={() => setActiveTab('added_by_admin')}
                        />
                        <Link
                            href="/admin/resident-claims"
                            className="inline-flex min-h-11 items-center whitespace-nowrap border-b-2 border-transparent px-4 py-3 text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            Online Accounts
                        </Link>
                    </div>

                    <div className="pb-3">
                        <input
                            type="text"
                            value={
                                activeTab === 'all' || activeTab === 'added_by_admin'
                                    ? approvedSearch
                                    : registrationSearch
                            }
                            onChange={(event) => {
                                if (
                                    activeTab === 'all' ||
                                    activeTab === 'added_by_admin'
                                ) {
                                    setApprovedSearch(event.target.value);
                                } else {
                                    setRegistrationSearch(event.target.value);
                                }
                            }}
                            placeholder={
                                activeTab === 'all' || activeTab === 'added_by_admin'
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
                    />
                )}

                {activeTab === 'added_by_admin' && (
                    <AddedByAdminResidentsTable
                        residents={adminAddedResidents}
                        paginator={addedByAdminPaginator}
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
                        onView={openApplicationReview}
                        onReject={openReject}
                        onApprove={openApplicationReview}
                        processingApproveId={processingApproveId}
                    />
                )}

                {activeTab === 'needs_correction' && (
                    <PendingRegistrationsTable
                        applications={needsCorrectionRows}
                        paginator={needsCorrectionPaginator}
                        onNavigate={navigateResidents}
                        searchActive={registrationSearch.trim() !== ''}
                        onView={openApplicationReview}
                        onReject={openReject}
                        onApprove={openApplicationReview}
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
                        key={selectedApplication.id}
                        application={selectedApplication}
                        approveError={approveError}
                        onClose={() => {
                            if (processingApproveId !== null) {
                                return;
                            }

                            setSelectedApplication(null);
                            setApproveError('');
                        }}
                        onReject={() => openReject(selectedApplication)}
                        onRequestCorrection={() => {
                            setCorrectingApplication(selectedApplication);
                            setSelectedApplication(null);
                        }}
                        onApprove={(residentId) =>
                            approveRegistration(selectedApplication, residentId)
                        }
                        processingApprove={
                            processingApproveId === selectedApplication.id
                        }
                    />
                )}

                {correctingApplication && (
                    <CorrectionRequestModal
                        application={correctingApplication}
                        onClose={() => setCorrectingApplication(null)}
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

                {showCreateResident && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
                            <ModalHeader
                                title="Add Resident"
                                description="Create a canonical Resident record without creating a portal account."
                                onClose={() => {
                                    if (!createResidentForm.processing) {
                                        setShowCreateResident(false);
                                    }
                                }}
                            />

                            <form
                                onSubmit={submitCreateResident}
                                className="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-5"
                            >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField label="Purok" error={createResidentForm.errors.purok_id}>
                                        <select
                                            value={createResidentForm.data.purok_id}
                                            onChange={(event) => createResidentForm.setData('purok_id', event.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select purok</option>
                                            {safePuroks.map((purok) => (
                                                <option key={purok.id} value={purok.id}>{purok.name}</option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <CreateField label="First Name" error={createResidentForm.errors.first_name}>
                                        <input value={createResidentForm.data.first_name} onChange={(event) => createResidentForm.setData('first_name', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required />
                                    </CreateField>

                                    <CreateField label="Middle Name" error={createResidentForm.errors.middle_name}>
                                        <input value={createResidentForm.data.middle_name} onChange={(event) => createResidentForm.setData('middle_name', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                    </CreateField>

                                    <CreateField label="Last Name" error={createResidentForm.errors.last_name}>
                                        <input value={createResidentForm.data.last_name} onChange={(event) => createResidentForm.setData('last_name', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required />
                                    </CreateField>

                                    <CreateField label="Suffix" error={createResidentForm.errors.suffix}>
                                        <input value={createResidentForm.data.suffix} onChange={(event) => createResidentForm.setData('suffix', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                    </CreateField>

                                    <CreateField label="Birthdate" error={createResidentForm.errors.birthdate}>
                                        <input type="date" value={createResidentForm.data.birthdate} onChange={(event) => createResidentForm.setData('birthdate', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required />
                                    </CreateField>

                                    <CreateField label="Sex" error={createResidentForm.errors.sex}>
                                        <select value={createResidentForm.data.sex} onChange={(event) => createResidentForm.setData('sex', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required>
                                            <option value="">Select sex</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </CreateField>

                                    <CreateField label="Civil Status" error={createResidentForm.errors.civil_status}>
                                        <select value={createResidentForm.data.civil_status} onChange={(event) => createResidentForm.setData('civil_status', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required>
                                            <option value="">Select civil status</option>
                                            <option value="Single">Single</option>
                                            <option value="Married">Married</option>
                                            <option value="Widowed">Widowed</option>
                                            <option value="Separated">Separated</option>
                                        </select>
                                    </CreateField>

                                    <CreateField label="Mobile Number" error={createResidentForm.errors.mobile}>
                                        <input value={createResidentForm.data.mobile} onChange={(event) => createResidentForm.setData('mobile', event.target.value)} placeholder="09XXXXXXXXX" className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required />
                                    </CreateField>

                                    <CreateField label="Email Address (optional)" error={createResidentForm.errors.email}>
                                        <input type="email" value={createResidentForm.data.email} onChange={(event) => createResidentForm.setData('email', event.target.value)} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                    </CreateField>
                                </div>

                                <CreateField label="Address" error={createResidentForm.errors.address_line}>
                                    <textarea value={createResidentForm.data.address_line} onChange={(event) => createResidentForm.setData('address_line', event.target.value)} rows={3} className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" required />
                                </CreateField>

                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                    An invitation is sent when an email address is provided. The QR code is not emailed; deliver it securely from the Resident details view.
                                </p>

                                <div className="flex justify-end gap-2 border-t border-gray-200 pt-4">
                                    <button type="button" onClick={() => setShowCreateResident(false)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                                        Cancel
                                    </button>
                                    <button type="submit" disabled={createResidentForm.processing} className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30] disabled:opacity-60">
                                        {createResidentForm.processing ? 'Creating...' : 'Create Resident'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </ModalContainer>
                )}

                {details && (
                    <ResidentDetailsModal details={details} onClose={closeDetails} />
                )}
            </AdminLayout>
        </>
    );
}

function AddedByAdminResidentsTable({
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
                                    <button
                                        type="button"
                                        onClick={() => onView(resident)}
                                        className="text-left hover:underline focus-visible:rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3f6f3d]"
                                        aria-label={`View details for Resident ID ${resident.residentId}`}
                                    >
                                        {resident.residentId}
                                    </button>
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
                                            No Residents added by Admin yet
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
}: {
    paginator: AllResidentsPaginator;
    onNavigate: (url: string) => void;
    onViewResident: (id: number) => void;
}) {
    const rows = Array.isArray(paginator.data) ? paginator.data : [];

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1080px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Resident ID</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead>Resident Status</TableHead>
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
                                    <StatusBadge
                                        active={Boolean(row.accountActive)}
                                        activeLabel="Linked"
                                        inactiveLabel="Disabled"
                                    />
                                </td>

                                <td className="px-5 py-4">
                                    <StatusPill status={row.status} />
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => onViewResident(row.id)}
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
                                        Verified Residents with linked online
                                        accounts will appear here.
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
                <Info label="Birthdate" value={resident.birthdate || '�?"'} />
                <Info label="Sex" value={resident.sex || '�?"'} />
                <Info
                    label="Civil Status"
                    value={resident.civil_status || '�?"'}
                />
                <Info label="Mobile" value={resident.mobile || '�?"'} />
                <Info label="Email" value={resident.email || '�?"'} />
                <Info
                    label="Email Verification"
                    value={resident.email_verified ? 'Verified' : 'Unverified'}
                />
                <Info label="Address" value={resident.address_line || '�?"'} />
                <Info label="Barangay" value={resident.barangay || '�?"'} />
                <Info label="Purok" value={resident.purok || '�?"'} />
                <Info
                    label="Resident Status"
                    value={resident.status || '�?"'}
                />
                <Info label="Source" value={resident.source === 'admin' ? 'Admin-created' : 'Registration'} />
                <Info label="Approved At" value={resident.approved_at || '�?"'} />
                <Info
                    label="Portal Account"
                    value={
                        !resident.account_exists
                            ? 'No account yet'
                            : resident.account_active
                              ? 'Active'
                              : 'Inactive'
                    }
                />
            </div>

            <ResidentQrPanel residentId={resident.id} />

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

function ResidentQrPanel({ residentId }: { residentId: number }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [credential, setCredential] = useState<{
        resident_number: string;
        full_name: string;
        verification_url: string;
    } | null>(null);

    const reveal = async () => {
        setLoading(true);
        setError('');

        try {
            const response = await fetch(
                `/admin/residents/${residentId}/qr/credential`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            const payload = await response.json();

            if (!response.ok) {
                throw new Error(
                    payload?.errors?.qr?.[0] ||
                        'Unable to load the QR credential.',
                );
            }

            setCredential(payload);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Unable to load the QR credential.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="rounded-lg border border-gray-200 bg-[#fafaf7] p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                        QR Credential Delivery
                    </p>
                    <p className="mt-1 text-xs leading-5 text-gray-600">
                        Reveal only when handing the credential to the Resident in person or through an approved secure channel. This action is audited.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={reveal}
                    disabled={loading}
                    className="rounded-md border border-[#3f6f3d] px-3 py-2 text-xs font-semibold text-[#3f6f3d] hover:bg-[#edf4ea] disabled:opacity-60"
                >
                    {loading ? 'Loading...' : credential ? 'Refresh QR' : 'Reveal QR'}
                </button>
            </div>

            {error && <p className="mt-3 text-xs font-medium text-red-600">{error}</p>}

            {credential && (
                <div className="mt-4 flex flex-col items-center gap-3 rounded-lg bg-white p-4 sm:flex-row">
                    <QRCodeSVG value={credential.verification_url} size={148} />
                    <div className="min-w-0 text-center sm:text-left">
                        <p className="text-sm font-semibold text-gray-900">{credential.full_name}</p>
                        <p className="mt-1 font-mono text-xs text-gray-600">{credential.resident_number}</p>
                        <p className="mt-2 break-all text-[10px] leading-4 text-gray-400">
                            {credential.verification_url}
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}

function CreateField({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label className="block text-xs font-semibold text-gray-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
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

            {registration.matched_resident && (
                <section className="rounded-lg border border-[#b7d8b2] bg-[#edf4ea] p-4">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-[#3f6f3d]">
                        Linked Official Resident
                    </p>
                    <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Info label="Resident ID" value={registration.matched_resident.resident_number} />
                        <Info label="Name" value={registration.matched_resident.full_name} />
                        <Info label="Birthdate" value={registration.matched_resident.birthdate || '—'} />
                        <Info label="Purok" value={registration.matched_resident.purok || '—'} />
                        <Info label="Resident status" value={registration.matched_resident.status} />
                        <Info label="Account" value={registration.matched_resident.account_exists
                            ? (registration.matched_resident.account_active ? 'Linked and enabled' : 'Linked and disabled')
                            : 'No account linked'} />
                    </div>
                    {registration.matched_at && (
                        <p className="mt-2 text-xs text-gray-600">Linked at {registration.matched_at}</p>
                    )}
                </section>
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

            {(registration.correction_cycles?.length ?? 0) > 0 && (
                <section className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-amber-900">Correction history</p>
                    {registration.correction_cycles?.map((cycle) => (
                        <div key={cycle.cycle_number} className="border-t border-amber-200 pt-3 first:border-0 first:pt-0">
                            <p className="text-xs font-semibold text-gray-800">
                                Cycle {cycle.cycle_number} · {cycle.requested_at || 'Request time unavailable'}
                                {cycle.resubmitted_at ? ` · Resubmitted ${cycle.resubmitted_at}` : ' · Awaiting applicant'}
                            </p>
                            <p className="mt-1 whitespace-pre-line text-sm text-gray-700">{cycle.instructions}</p>
                            <p className="mt-1 text-xs text-gray-500">Reviewer: {cycle.reviewer || '—'}</p>
                        </div>
                    ))}
                </section>
            )}
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

                    {!details.loading && !details.error &&
                        !details.resident && !details.registration && (
                            <p className="text-sm text-gray-500">No details are available for this record.</p>
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
    approveError,
    onClose,
    onReject,
    onRequestCorrection,
    onApprove,
    processingApprove,
}: {
    application: PendingRegistration;
    approveError: string;
    onClose: () => void;
    onReject: () => void;
    onRequestCorrection: () => void;
    onApprove: (residentId: number) => void;
    processingApprove: boolean;
}) {
    const [candidateSearch, setCandidateSearch] = useState(
        `${application.first_name} ${application.last_name}`.trim(),
    );
    const [candidates, setCandidates] = useState<ResidentCandidate[]>([]);
    const [selectedCandidateId, setSelectedCandidateId] = useState<number | null>(null);
    const [loadingCandidates, setLoadingCandidates] = useState(false);
    const [candidateError, setCandidateError] = useState('');

    useEffect(() => {
        const query = candidateSearch.trim();
        if (query.length < 2) {
            setCandidates([]);
            setLoadingCandidates(false);
            setCandidateError('Enter at least two characters to search this barangay\'s active Resident records.');
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoadingCandidates(true);
            setCandidateError('');
            fetch(
                `/admin/resident-registrations/${application.id}/candidates?q=${encodeURIComponent(query)}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            )
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('Unable to load Resident candidates.');
                    }
                    return (await response.json()) as { data?: ResidentCandidate[] };
                })
                .then((payload) => setCandidates(Array.isArray(payload.data) ? payload.data : []))
                .catch((error: unknown) => {
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }
                    setCandidates([]);
                    setCandidateError('Unable to load candidates. Refresh the review and try again.');
                })
                .finally(() => setLoadingCandidates(false));
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [application.id, candidateSearch]);

    const selectedCandidate = candidates.find(
        (candidate) => candidate.id === selectedCandidateId,
    );

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

                    <section className="mt-6 rounded-xl border border-[#d7e5d2] bg-[#f8fbf6] p-4">
                        <div className="mb-3">
                            <h2 className="text-sm font-semibold text-gray-800">
                                Select the official Barangay Resident
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-gray-500">
                                Search suggestions do not link automatically. Review the official record and explicitly select one. If no correct record exists, leave this application pending; approval will not create a Resident.
                            </p>
                            <p className="mt-2 text-xs leading-5 text-blue-800">
                                A QR reference, when valid, adds a suggestion only. It does not establish identity or select a candidate automatically.
                            </p>
                        </div>

                        <label htmlFor="resident-candidate-search" className="mb-1 block text-xs font-medium text-gray-600">
                            Search by Resident ID or name
                        </label>
                        <input
                            id="resident-candidate-search"
                            type="search"
                            value={candidateSearch}
                            onChange={(event) => {
                                setCandidateSearch(event.target.value);
                                setSelectedCandidateId(null);
                            }}
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-[#6b9464] focus:ring-2 focus:ring-[#b7d8b2]"
                            placeholder="Resident number or name"
                            disabled={processingApprove}
                        />

                        {loadingCandidates && <p className="mt-3 text-xs text-gray-500">Searching this barangay…</p>}
                        {!loadingCandidates && candidateError && <p className="mt-3 text-xs text-red-600">{candidateError}</p>}
                        {!loadingCandidates && !candidateError && candidates.length === 0 && (
                            <p className="mt-3 rounded-md border border-gray-200 bg-white p-3 text-xs text-gray-500">
                                No active Resident matches this search. The application remains pending until a correct official record is selected.
                            </p>
                        )}

                        {candidates.length > 0 && (
                            <fieldset className="mt-3 space-y-2" disabled={processingApprove}>
                                <legend className="sr-only">Official Resident candidate</legend>
                                {candidates.map((candidate) => (
                                    <label
                                        key={candidate.id}
                                        className={`flex cursor-pointer items-start gap-3 rounded-lg border bg-white p-3 ${selectedCandidateId === candidate.id ? 'border-[#6b9464] ring-1 ring-[#6b9464]' : 'border-gray-200'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="resident-candidate"
                                            value={candidate.id}
                                            checked={selectedCandidateId === candidate.id}
                                            onChange={() => setSelectedCandidateId(candidate.id)}
                                            className="mt-1 accent-[#3f6f3d]"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium text-gray-800">{candidate.full_name}</span>
                                            {candidate.qr_suggested && (
                                                <span className="mt-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-800">
                                                    Suggested by QR reference — select explicitly to continue
                                                </span>
                                            )}
                                            <span className="mt-0.5 block text-xs text-gray-500">
                                                {candidate.resident_number} · Born {candidate.birthdate || '—'} · {candidate.purok || 'Purok not recorded'}
                                            </span>
                                            {candidate.account_exists && (
                                                <span className="mt-1 block text-xs font-medium text-amber-700">
                                                    An account is already linked. This candidate cannot receive another account.
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                        )}

                        {selectedCandidate && (
                            <div className="mt-3 rounded-lg border border-gray-200 bg-white p-4">
                                <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Official record selected</p>
                                <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <Info label="Resident ID" value={selectedCandidate.resident_number} />
                                    <Info label="Official Name" value={selectedCandidate.full_name} />
                                    <Info label="Birthdate" value={selectedCandidate.birthdate || '—'} />
                                    <Info label="Email on record" value={selectedCandidate.email || '—'} />
                                    <Info label="Account linkage" value={selectedCandidate.account_exists ? 'Already linked' : 'No account'} />
                                    <Info label="Resident status" value={selectedCandidate.status} />
                                </div>
                            </div>
                        )}
                    </section>

                    {approveError && (
                        <p role="alert" className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{approveError}</p>
                    )}

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

                    {application.status === 'pending' && (
                        <button
                            type="button"
                            onClick={onRequestCorrection}
                            disabled={processingApprove}
                            className="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Request Correction
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => selectedCandidate && onApprove(selectedCandidate.id)}
                        disabled={processingApprove || !selectedCandidate || selectedCandidate.account_exists}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {processingApprove
                            ? 'Approving...'
                            : 'Confirm Resident Link'}
                    </button>
                </div>
            </div>
        </ModalContainer>
    );
}

const CORRECTION_FIELD_LABELS: Record<string, string> = {
    first_name: 'First name',
    middle_name: 'Middle name',
    last_name: 'Last name',
    suffix: 'Suffix',
    birthdate: 'Birthdate',
    sex: 'Sex',
    civil_status: 'Civil status',
    address_line: 'Address',
    mobile: 'Mobile number',
    email: 'Email address',
    purok_id: 'Purok',
};

const CORRECTION_DOCUMENT_LABELS: Record<string, string> = {
    valid_id: 'Valid ID',
    live_identity_photo: 'Live Identity Photo',
    senior_proof: 'Senior Citizen Proof',
    pwd_proof: 'PWD Proof',
};

function CorrectionRequestModal({
    application,
    onClose,
}: {
    application: PendingRegistration;
    onClose: () => void;
}) {
    const form = useForm({
        instructions: '',
        field_targets: [] as string[],
        document_targets: [] as string[],
    });
    const availableFields = (application.correction_field_options ?? []).filter(
        (field) => Object.prototype.hasOwnProperty.call(CORRECTION_FIELD_LABELS, field),
    );
    const availableDocuments = (application.correction_document_options ?? []).filter(
        (document) => Object.prototype.hasOwnProperty.call(CORRECTION_DOCUMENT_LABELS, document),
    );
    const formErrors = form.errors as Record<string, string | undefined>;

    const toggle = (key: string, current: string[], setter: (value: string[]) => void) => {
        setter(current.includes(key)
            ? current.filter((item) => item !== key)
            : [...current, key]);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/admin/residents/${application.id}/correction`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <ModalContainer>
            <div className="my-auto w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title="Request Applicant Correction"
                    description={`Select only the information or documents this applicant should correct (${application.reference}).`}
                    onClose={() => { if (!form.processing) onClose(); }}
                />
                <form onSubmit={submit} className="space-y-5 px-5 py-5">
                    <div>
                        <label htmlFor="correction-instructions" className="mb-1 block text-sm font-medium text-gray-700">
                            Instructions for applicant
                        </label>
                        <textarea
                            id="correction-instructions"
                            value={form.data.instructions}
                            onChange={(event) => form.setData('instructions', event.target.value)}
                            rows={4}
                            required
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                        />
                        {form.errors.instructions && <p role="alert" className="mt-1 text-xs text-red-700">{form.errors.instructions}</p>}
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-semibold text-gray-800">Applicant fields</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {availableFields.map((field) => (
                                <label key={field} className="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={form.data.field_targets.includes(field)}
                                        onChange={() => toggle(field, form.data.field_targets, (value) => form.setData('field_targets', value))}
                                    />
                                    {CORRECTION_FIELD_LABELS[field]}
                                </label>
                            ))}
                        </div>
                        {form.errors.field_targets && <p role="alert" className="text-xs text-red-700">{form.errors.field_targets}</p>}
                        {form.errors['field_targets.0'] && <p role="alert" className="text-xs text-red-700">{form.errors['field_targets.0']}</p>}
                    </fieldset>

                    {availableDocuments.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-semibold text-gray-800">Existing documents</legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {availableDocuments.map((document) => (
                                    <label key={document} className="flex items-center gap-2 text-sm text-gray-700">
                                        <input
                                            type="checkbox"
                                            checked={form.data.document_targets.includes(document)}
                                            onChange={() => toggle(document, form.data.document_targets, (value) => form.setData('document_targets', value))}
                                        />
                                        {CORRECTION_DOCUMENT_LABELS[document]}
                                    </label>
                                ))}
                            </div>
                            {form.errors.document_targets && <p role="alert" className="text-xs text-red-700">{form.errors.document_targets}</p>}
                            {form.errors['document_targets.0'] && <p role="alert" className="text-xs text-red-700">{form.errors['document_targets.0']}</p>}
                        </fieldset>
                    )}

                    {formErrors.correction && <p role="alert" className="text-sm text-red-700">{formErrors.correction}</p>}
                    <p className="text-xs leading-5 text-gray-500">Sectors, Resident identity, account details, QR credentials, and application status cannot be changed through this request.</p>
                    <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                        <button type="button" onClick={onClose} disabled={form.processing} className="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 disabled:opacity-50">Cancel</button>
                        <button type="submit" disabled={form.processing} className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                            {form.processing ? 'Saving…' : 'Send correction request'}
                        </button>
                    </div>
                </form>
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
                                {document.is_superseded && (
                                    <p className="mt-1 text-[11px] font-semibold text-amber-800">
                                        Superseded{document.superseded_at ? ` on ${document.superseded_at}` : ''}
                                    </p>
                                )}
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
