import BirthdateInput from '@/components/BirthdateInput';
import { Deferred, Head, router, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';
import useLivePendingAccounts from '@/hooks/useLivePendingAccounts';

type Sector = {
    id: number;
    name: string;
    slug: string;
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
    purok_id?: number | null;
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
    decision_notification?: {
        id: number;
        status: string;
        attempts: number;
    } | null;
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
    totalHouseholds: number;
};

type TabCounts = {
    allResidents?: number;
    households?: number;
    addedByAdmin?: number;
    pendingRegistrations?: number;
    needsCorrectionRegistrations?: number;
    rejectedRegistrations?: number;
};

type Purok = {
    id: number;
    name: string;
    household_count: number;
    resident_count: number;
};

type Household = {
    id: number;
    code: string;
    head: string;
    purok: string | null;
    members_count: number;
};

type HouseholdMemberDetails = {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    relationship: string;
    birthdate: string | null;
    sex: string | null;
    mobile: string | null;
    email: string | null;
    is_head: boolean;
};

type HouseholdFullDetails = {
    id: number;
    code: string;
    purok_id: number;
    purok_name: string | null;
    head_resident_id: number | null;
    head: {
        id: number;
        name: string;
        mobile: string | null;
        email: string | null;
    } | null;
    members_count: number;
    members: HouseholdMemberDetails[];
    created_at: string | null;
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

type HouseholdRow = Household;

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
    purok_id?: number | null;
    purok: string | null;
    account_exists: boolean;
    account_active: boolean;
    household: {
        id: number;
        code: string;
        head: string | null;
        relationship: string | null;
        members: Array<{
            id: number;
            full_name: string;
            relationship: string | null;
        }>;
    } | null;
    sectors: Sector[];
    designations?: string[];
    staff_assignments?: Array<{
        barangay: string | null;
        department: string | null;
        position: string | null;
    }>;
    decision_notification?: {
        id: number;
        status: string;
        attempts: number;
    } | null;
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
    purok_id?: number | null;
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
    households?: Paginator<HouseholdRow>;
    filters?: {
        resident_search?: string;
        registration_search?: string;
        purok_id?: number | null;
        tab?: Tab;
    };
    stats?: Stats;
    tabCounts?: TabCounts;
    flash?: { success?: string | null; error?: string | null };
};

type Tab =
    | 'all'
    | 'added_by_admin'
    | 'pending'
    | 'needs_correction'
    | 'rejected'
    | 'households';

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
    'households',
    'tabCounts',
    'filters',
];

const TAB_DATA_PROP: Record<Tab, string> = {
    all: 'allResidents',
    added_by_admin: 'addedByAdmin',
    households: 'households',
    pending: 'pendingRegistrations',
    needs_correction: 'needsCorrectionRegistrations',
    rejected: 'rejectedRegistrations',
};

const normalizeTab = (tab?: string): Tab =>
    tab && Object.prototype.hasOwnProperty.call(TAB_DATA_PROP, tab)
        ? (tab as Tab)
        : 'all';

export default function Residents({
    allResidents,
    pendingRegistrations,
    needsCorrectionRegistrations,
    rejectedRegistrations,
    puroks = [],
    addedByAdmin,
    households,
    filters,
    stats = {
        totalResidents: 0,
        activeResidents: 0,
        pendingRegistrations: 0,
        totalHouseholds: 0,
    },
    tabCounts,
    flash,
}: Props) {
    const safePuroks = Array.isArray(puroks) ? puroks : [];

    const selectedPurokId =
        typeof filters?.purok_id === 'number' ? filters.purok_id : null;

    const queueQuery = new URLSearchParams({
        pending_page: String(pendingRegistrations?.current_page ?? 1),
        registration_search: filters?.registration_search ?? '',
        ...(selectedPurokId !== null ? { purok: String(selectedPurokId) } : {}),
    }).toString();
    const livePending = useLivePendingAccounts(
        pendingRegistrations,
        queueQuery,
    );

    const addedByAdminPaginator: ResidentPaginator =
        addedByAdmin ?? emptyPaginator<Resident>();

    const allPaginator: AllResidentsPaginator =
        allResidents ?? emptyPaginator<AllResidentRow>();

    const pendingPaginator: RegistrationPaginator =
        livePending.data ?? emptyPaginator<PendingRegistration>();

    const needsCorrectionPaginator: RegistrationPaginator =
        needsCorrectionRegistrations ?? emptyPaginator<PendingRegistration>();

    const rejectedPaginator: RegistrationPaginator =
        rejectedRegistrations ?? emptyPaginator<PendingRegistration>();
    const householdPaginator = households ?? emptyPaginator<HouseholdRow>();

    const allResidentsTotal =
        allResidents != null
            ? allPaginator.total
            : (tabCounts?.allResidents ?? 0);
    const householdTotal =
        households != null
            ? householdPaginator.total
            : (tabCounts?.households ?? 0);
    const addedByAdminTotal =
        addedByAdmin != null
            ? addedByAdminPaginator.total
            : (tabCounts?.addedByAdmin ?? 0);
    const needsCorrectionTotal =
        needsCorrectionRegistrations != null
            ? needsCorrectionPaginator.total
            : (tabCounts?.needsCorrectionRegistrations ?? 0);
    const rejectedTotal =
        rejectedRegistrations != null
            ? rejectedPaginator.total
            : (tabCounts?.rejectedRegistrations ?? 0);
    const pendingTotal =
        livePending.total ??
        (pendingRegistrations != null
            ? pendingPaginator.total
            : (tabCounts?.pendingRegistrations ?? stats.pendingRegistrations));

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

    const [activeTab, setActiveTab] = useState<Tab>(() =>
        normalizeTab(filters?.tab),
    );
    useEffect(() => {
        if (activeTab === 'pending') {
            void livePending.refresh();
        }
    }, [activeTab, livePending.refresh]);
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
    const [emailSafeRejectionReason, setEmailSafeRejectionReason] =
        useState('');
    const [rejectError, setRejectError] = useState('');
    const [processingReject, setProcessingReject] = useState(false);

    const [processingApproveId, setProcessingApproveId] = useState<
        number | null
    >(null);
    const [approveError, setApproveError] = useState('');
    const [residentActionError, setResidentActionError] = useState('');

    const [details, setDetails] = useState<DetailsState | null>(null);
    const detailsRequestId = useRef(0);

    const [showCreateResident, setShowCreateResident] = useState(false);
    const [editingResident, setEditingResident] = useState<ResidentDetails | null>(null);
    const [loadingEditResidentId, setLoadingEditResidentId] = useState<number | null>(null);

    const editResidentForm = useForm({
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
        household_mode: 'preserve',
        household_id: '',
        household_relationship: '',
    });
    const [editHouseholdSearch, setEditHouseholdSearch] = useState('');
    const [editHouseholdMatches, setEditHouseholdMatches] = useState<Household[]>([]);

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
        household_mode: 'none',
        household_id: '',
        household_relationship: '',
    });
    const [householdSearch, setHouseholdSearch] = useState('');
    const [householdMatches, setHouseholdMatches] = useState<Household[]>([]);

    const lastQueried = useRef(filters?.resident_search ?? '');
    const lastQueriedRegistration = useRef(filters?.registration_search ?? '');

    const tabIsLoaded = (tab: Tab): boolean => {
        switch (tab) {
            case 'all':
                return allResidents != null;
            case 'households':
                return households != null;
            case 'added_by_admin':
                return addedByAdmin != null;
            case 'pending':
                return pendingRegistrations != null;
            case 'needs_correction':
                return needsCorrectionRegistrations != null;
            case 'rejected':
                return rejectedRegistrations != null;
        }
    };

    /*
     * Partial reloads keep every tab's independent pagination state intact so
     * changing one tab's search or the purok filter never discards another
     * tab's current page. `overrides` reset only the pages that must restart.
     */
    const navigateResidentsWith = (
        overrides: Record<string, string | number | undefined>,
    ) => {
        const only =
            'purok' in overrides
                ? RESIDENT_PARTIAL_PROPS
                : 'all_page' in overrides || 'added_page' in overrides
                  ? ['allResidents', 'addedByAdmin', 'filters']
                  : [
                        'pendingRegistrations',
                        'needsCorrectionRegistrations',
                        'rejectedRegistrations',
                        'filters',
                    ];
        router.get(
            '/admin/residents',
            {
                resident_search: approvedSearch.trim() || undefined,
                registration_search: registrationSearch.trim() || undefined,
                tab: activeTab === 'households' ? 'households' : undefined,
                purok: selectedPurokId ?? undefined,
                added_page: addedByAdminPaginator.current_page,
                all_page: allPaginator.current_page,
                pending_page: pendingPaginator.current_page,
                needs_correction_page: needsCorrectionPaginator.current_page,
                rejected_page: rejectedPaginator.current_page,
                ...overrides,
            },
            {
                only,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const selectTab = (tab: Tab) => {
        if (tab === activeTab) {
            return;
        }

        setActiveTab(tab);

        if (tabIsLoaded(tab)) {
            return;
        }

        router.get(
            '/admin/residents',
            {
                resident_search: approvedSearch.trim() || undefined,
                registration_search: registrationSearch.trim() || undefined,
                tab: tab === 'households' ? 'households' : undefined,
                purok: selectedPurokId ?? undefined,
                added_page: addedByAdminPaginator.current_page,
                all_page: allPaginator.current_page,
                pending_page: pendingPaginator.current_page,
                needs_correction_page: needsCorrectionPaginator.current_page,
                rejected_page: rejectedPaginator.current_page,
                household_page: householdPaginator.current_page,
            },
            {
                only: [TAB_DATA_PROP[tab], 'filters'],
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

    useEffect(() => {
        if (
            createResidentForm.data.household_mode !== 'existing' ||
            householdSearch.trim().length < 2
        ) {
            setHouseholdMatches([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                `/admin/residents/households/search?q=${encodeURIComponent(householdSearch.trim())}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(),
                )
                .then((payload: { data?: Household[] }) =>
                    setHouseholdMatches(
                        Array.isArray(payload.data) ? payload.data : [],
                    ),
                )
                .catch(() => setHouseholdMatches([]));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [householdSearch, createResidentForm.data.household_mode]);

    useEffect(() => {
        if (
            editResidentForm.data.household_mode !== 'existing' ||
            editHouseholdSearch.trim().length < 2
        ) {
            setEditHouseholdMatches([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                `/admin/residents/households/search?q=${encodeURIComponent(editHouseholdSearch.trim())}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(),
                )
                .then((payload: { data?: Household[] }) =>
                    setEditHouseholdMatches(
                        Array.isArray(payload.data) ? payload.data : [],
                    ),
                )
                .catch(() => setEditHouseholdMatches([]));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [editHouseholdSearch, editResidentForm.data.household_mode]);

    // Household Management States
    const [showCreateHousehold, setShowCreateHousehold] = useState(false);
    const [viewingHousehold, setViewingHousehold] = useState<HouseholdFullDetails | null>(null);
    const [editingHousehold, setEditingHousehold] = useState<HouseholdFullDetails | null>(null);
    const [loadingHouseholdId, setLoadingHouseholdId] = useState<number | null>(null);
    const [transferringMember, setTransferringMember] = useState<HouseholdMemberDetails | null>(null);

    const createHouseholdForm = useForm({
        purok_id: '',
        household_code: '',
        head_resident_id: '',
        members: [] as Array<{
            resident_id: number;
            resident_name: string;
            household_relationship: string;
        }>,
    });
    const [createHouseholdResidentSearch, setCreateHouseholdResidentSearch] = useState('');
    const [createHouseholdResidentMatches, setCreateHouseholdResidentMatches] = useState<Array<{ id: number; full_name: string; purok: string }>>([]);

    // Household codes are immutable after creation, so the edit form only
    // carries the mutable location and head assignment.
    const editHouseholdForm = useForm({
        purok_id: '',
        head_resident_id: '',
    });

    const addMemberForm = useForm({
        resident_id: '',
        household_relationship: 'Member',
        is_head: false,
    });
    const [addMemberResidentSearch, setAddMemberResidentSearch] = useState('');
    const [addMemberResidentMatches, setAddMemberResidentMatches] = useState<Array<{ id: number; full_name: string; purok: string }>>([]);
    const [selectedAddMemberResident, setSelectedAddMemberResident] = useState<{
        id: number;
        full_name: string;
    } | null>(null);

    const transferMemberForm = useForm({
        target_household_id: '',
        household_relationship: 'Member',
        is_head: false,
    });
    const [transferHouseholdSearch, setTransferHouseholdSearch] = useState('');
    const [transferHouseholdMatches, setTransferHouseholdMatches] = useState<Household[]>([]);

    useEffect(() => {
        if (createHouseholdResidentSearch.trim().length < 2) {
            setCreateHouseholdResidentMatches([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/admin/residents/search?q=${encodeURIComponent(createHouseholdResidentSearch.trim())}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject()))
                .then((data: { residents?: Array<{ id: number; full_name: string; purok: string }> }) =>
                    setCreateHouseholdResidentMatches(Array.isArray(data.residents) ? data.residents : [])
                )
                .catch(() => setCreateHouseholdResidentMatches([]));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [createHouseholdResidentSearch]);

    useEffect(() => {
        // Once a resident is chosen, the filled name must not trigger a new
        // suggestion fetch that would reopen the dropdown.
        if (selectedAddMemberResident) {
            setAddMemberResidentMatches([]);
            return;
        }
        if (addMemberResidentSearch.trim().length < 2) {
            setAddMemberResidentMatches([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/admin/residents/search?q=${encodeURIComponent(addMemberResidentSearch.trim())}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject()))
                .then((data: { residents?: Array<{ id: number; full_name: string; purok: string }> }) =>
                    setAddMemberResidentMatches(Array.isArray(data.residents) ? data.residents : [])
                )
                .catch(() => setAddMemberResidentMatches([]));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [addMemberResidentSearch, selectedAddMemberResident]);

    useEffect(() => {
        if (transferHouseholdSearch.trim().length < 2) {
            setTransferHouseholdMatches([]);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/admin/residents/households/search?q=${encodeURIComponent(transferHouseholdSearch.trim())}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject()))
                .then((data: { data?: Household[] }) =>
                    setTransferHouseholdMatches(Array.isArray(data.data) ? data.data : [])
                )
                .catch(() => setTransferHouseholdMatches([]));
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [transferHouseholdSearch]);

    const openViewHousehold = async (householdId: number) => {
        setLoadingHouseholdId(householdId);
        try {
            const res = await fetch(`/admin/households/${householdId}`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const payload = await res.json();
                setViewingHousehold(payload.data);
            }
        } catch {
            // failed
        } finally {
            setLoadingHouseholdId(null);
        }
    };

    const openEditHousehold = async (householdId: number) => {
        setLoadingHouseholdId(householdId);
        try {
            const res = await fetch(`/admin/households/${householdId}`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const payload = await res.json();
                const data: HouseholdFullDetails = payload.data;
                setEditingHousehold(data);
                editHouseholdForm.setData({
                    purok_id: String(data.purok_id),
                    head_resident_id: data.head_resident_id ? String(data.head_resident_id) : '',
                });
                // Start each household with a clean add-member selection.
                addMemberForm.reset();
                setAddMemberResidentSearch('');
                setAddMemberResidentMatches([]);
                setSelectedAddMemberResident(null);
            }
        } catch {
            // failed
        } finally {
            setLoadingHouseholdId(null);
        }
    };

    const submitCreateHousehold = (e: React.FormEvent) => {
        e.preventDefault();
        createHouseholdForm.post('/admin/households', {
            preserveScroll: true,
            onSuccess: () => {
                setShowCreateHousehold(false);
                createHouseholdForm.reset();
                setCreateHouseholdResidentSearch('');
                router.reload({ only: ['households', 'stats'] });
            },
        });
    };

    const submitEditHousehold = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingHousehold) return;
        editHouseholdForm.put(`/admin/households/${editingHousehold.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingHousehold(null);
                router.reload({ only: ['households', 'stats'] });
            },
        });
    };

    const submitAddMember = (e: React.FormEvent) => {
        e.preventDefault();
        // A resident must be chosen from the autocomplete results; free text
        // alone can never be submitted as an id.
        if (
            !editingHousehold ||
            !selectedAddMemberResident ||
            !addMemberForm.data.resident_id
        ) {
            return;
        }
        addMemberForm.post(`/admin/households/${editingHousehold.id}/members`, {
            preserveScroll: true,
            onSuccess: () => {
                addMemberForm.reset();
                setAddMemberResidentSearch('');
                setSelectedAddMemberResident(null);
                openEditHousehold(editingHousehold.id);
                router.reload({ only: ['households', 'stats'] });
            },
        });
    };

    const handleRemoveMember = (householdId: number, residentId: number) => {
        if (!confirm('Are you sure you want to remove this resident from the household? The resident record will remain intact.')) {
            return;
        }
        router.delete(`/admin/households/${householdId}/members/${residentId}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (editingHousehold) {
                    openEditHousehold(householdId);
                }
                router.reload({ only: ['households', 'stats'] });
            },
        });
    };

    const submitTransferMember = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editingHousehold || !transferringMember) return;
        transferMemberForm.post(`/admin/households/${editingHousehold.id}/members/${transferringMember.id}/transfer`, {
            preserveScroll: true,
            onSuccess: () => {
                setTransferringMember(null);
                transferMemberForm.reset();
                setTransferHouseholdSearch('');
                openEditHousehold(editingHousehold.id);
                router.reload({ only: ['households', 'stats'] });
            },
        });
    };

    const navigateResidents = (url: string) => {
        const tabProps: Record<string, string> = {
            all: 'allResidents',
            added_by_admin: 'addedByAdmin',
            households: 'households',
            pending: 'pendingRegistrations',
            needs_correction: 'needsCorrectionRegistrations',
            rejected: 'rejectedRegistrations',
        };
        router.get(
            url,
            {},
            {
                only: tabProps[activeTab]
                    ? [tabProps[activeTab], 'filters']
                    : RESIDENT_PARTIAL_PROPS,
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
            household_page: 1,
            pending_page: 1,
            needs_correction_page: 1,
            rejected_page: 1,
        });
    };

    const openResidentDetails = (residentId: number) => {
        loadDetails('resident', residentId);
    };

    const openEditResident = async (residentId: number) => {
        setLoadingEditResidentId(residentId);
        setResidentActionError('');

        try {
            const response = await fetch(`/admin/residents/${residentId}/details`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Could not load resident details (HTTP ${response.status}).`);
            }

            const payload = await response.json();
            const resident: ResidentDetails = payload.data;

            editResidentForm.setData({
                purok_id: resident.purok_id ? String(resident.purok_id) : '',
                first_name: resident.first_name || '',
                middle_name: resident.middle_name || '',
                last_name: resident.last_name || '',
                suffix: resident.suffix || '',
                birthdate: resident.birthdate || '',
                sex: resident.sex || '',
                civil_status: resident.civil_status || '',
                address_line: resident.address_line || '',
                mobile: resident.mobile || '',
                email: resident.email || '',
                household_mode: resident.household ? 'preserve' : 'none',
                household_id: resident.household ? String(resident.household.id) : '',
                household_relationship: resident.household?.relationship || '',
            });

            if (resident.household) {
                setEditHouseholdSearch(`${resident.household.code} · ${resident.household.head || 'No head'}`);
            } else {
                setEditHouseholdSearch('');
            }
            setEditHouseholdMatches([]);
            setEditingResident(resident);
        } catch (err: unknown) {
            setResidentActionError(
                err instanceof Error ? err.message : 'Unable to load resident for editing.',
            );
        } finally {
            setLoadingEditResidentId(null);
        }
    };

    const submitEditResident = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!editingResident) {
            return;
        }

        editResidentForm.put(`/admin/residents/${editingResident.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingResident(null);
                editResidentForm.reset();
            },
        });
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
                    throw new Error(
                        `Could not load these details (HTTP ${response.status}).`,
                    );
                }

                const payload = await response.json();
                if (
                    !payload ||
                    payload.type !== type ||
                    !payload.data ||
                    typeof payload.data !== 'object'
                ) {
                    throw new Error(
                        'The server returned details in an unexpected format.',
                    );
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
                    error:
                        error instanceof Error
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

    const toggleAccountStatus = (resident: Resident | { id: number; fullName: string; accountActive: boolean | null }) => {
        const next = !Boolean(resident.accountActive);

        const promptText = next
            ? `Enable the login account for ${resident.fullName}? They will be able to sign in.`
            : `Disable the login account for ${resident.fullName}? They will not be able to sign in.`;

        if (!window.confirm(promptText)) {
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
        setEmailSafeRejectionReason('');
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
                        (typeof errors.resident_id === 'string' &&
                            errors.resident_id) ||
                        (typeof errors.approval === 'string' &&
                            errors.approval) ||
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
        const emailSafeReason = emailSafeRejectionReason.trim();

        if (reason.length < 5) {
            setRejectError('Please enter a clear rejection reason.');
            return;
        }

        setProcessingReject(true);
        setRejectError('');

        router.post(
            `/admin/residents/${rejectingApplication.id}/reject`,
            { reason, email_safe_reason: emailSafeReason || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectingApplication(null);
                    setRejectionReason('');
                    setEmailSafeRejectionReason('');
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

    const retryDecisionNotification = (notificationId: number) => {
        router.post(
            `/admin/resident-registration-notifications/${notificationId}/retry`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (details?.resident) {
                        openResidentDetails(details.resident.id);
                    }
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
                        <h1 className="text-2xl font-semibold text-ink-strong">
                            Resident Management
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Manage verified online accounts and official
                            Resident records
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setShowCreateResident(true)}
                            className="inline-flex min-h-10 items-center justify-center rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-deep"
                        >
                            Add Resident
                        </button>
                    </div>
                </div>

                <Deferred
                    data={['stats', 'puroks', 'tabCounts']}
                    fallback={
                        <AdminPageSkeleton
                            metrics={2}
                            rows={7}
                            columns={4}
                            showTabs
                        />
                    }
                >
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SummaryCard
                            title="Total Residents"
                            value={stats.totalResidents}
                        />
                        <SummaryCard
                            title="Households"
                            value={stats.totalHouseholds}
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

                    <div className="mb-5 flex flex-col gap-4 border-b border-line-soft sm:flex-row sm:items-end sm:justify-between">
                        <div className="flex overflow-x-auto">
                            <TabButton
                                label={`All Residents (${allResidentsTotal})`}
                                active={activeTab === 'all'}
                                onClick={() => selectTab('all')}
                            />
                            <TabButton
                                label={`Households (${householdTotal})`}
                                active={activeTab === 'households'}
                                onClick={() => selectTab('households')}
                            />
                            <TabButton
                                label={`Added by Admin (${addedByAdminTotal})`}
                                active={activeTab === 'added_by_admin'}
                                onClick={() => selectTab('added_by_admin')}
                            />
                            <TabButton
                                label={`Pending Accounts (${pendingTotal})`}
                                active={activeTab === 'pending'}
                                onClick={() => selectTab('pending')}
                            />
                            <TabButton
                                label={`Needs Correction (${needsCorrectionTotal})`}
                                active={activeTab === 'needs_correction'}
                                onClick={() => selectTab('needs_correction')}
                            />
                            <TabButton
                                label={`Rejected (${rejectedTotal})`}
                                active={activeTab === 'rejected'}
                                onClick={() => selectTab('rejected')}
                            />
                        </div>

                        <div className="pb-3">
                            <input
                                type="text"
                                value={
                                    activeTab === 'all' ||
                                    activeTab === 'added_by_admin'
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
                                        setRegistrationSearch(
                                            event.target.value,
                                        );
                                    }
                                }}
                                placeholder={
                                    activeTab === 'all' ||
                                    activeTab === 'added_by_admin'
                                        ? 'Search resident...'
                                        : 'Search applicant...'
                                }
                                className="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink-strong outline-none placeholder:text-ink-faint focus:border-brand focus:ring-1 focus:ring-brand sm:w-72"
                            />
                        </div>
                    </div>

                    {flash?.success && (
                        <div className="mb-4 rounded-lg border border-brand-muted bg-brand-soft px-4 py-3">
                            <p className="text-sm font-medium text-brand">
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
                        <Deferred
                            data="allResidents"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
                            <AllResidentsTable
                                paginator={allPaginator}
                                onNavigate={navigateResidents}
                                onViewResident={openResidentDetails}
                                onEditResident={openEditResident}
                                onToggleAccount={toggleAccountStatus}
                                loadingEditResidentId={loadingEditResidentId}
                            />
                        </Deferred>
                    )}

                    {activeTab === 'added_by_admin' && (
                        <Deferred
                            data="addedByAdmin"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
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
                        </Deferred>
                    )}
                    {activeTab === 'households' && (
                        <Deferred
                            data="households"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
                            <div className="space-y-4">
                                <div className="flex justify-end">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            createHouseholdForm.reset();
                                            setShowCreateHousehold(true);
                                        }}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white shadow-card hover:bg-brand-hover"
                                    >
                                        + Create Household
                                    </button>
                                </div>
                                <HouseholdsTable
                                    paginator={householdPaginator}
                                    onNavigate={navigateResidents}
                                    onViewHousehold={openViewHousehold}
                                    onEditHousehold={openEditHousehold}
                                    loadingHouseholdId={loadingHouseholdId}
                                />
                            </div>
                        </Deferred>
                    )}

                    {activeTab === 'pending' &&
                        (livePending.error ||
                            selectedPurokId !== null ||
                            registrationSearch.trim() !== '' ||
                            pendingPaginator.current_page > 1) && (
                            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-ink">
                                <div>
                                    {livePending.error && (
                                        <p
                                            role="alert"
                                            className="mt-1 text-red-700"
                                        >
                                            {livePending.error}
                                        </p>
                                    )}
                                    {(selectedPurokId !== null ||
                                        registrationSearch.trim() !== '') && (
                                        <p className="mt-1">
                                            Filters are active: showing{' '}
                                            {pendingPaginator.total} of{' '}
                                            {livePending.total ??
                                                stats.pendingRegistrations}{' '}
                                            pending applications in your
                                            barangay.
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-3">
                                    {(selectedPurokId !== null ||
                                        registrationSearch.trim() !== '' ||
                                        pendingPaginator.current_page > 1) && (
                                        <button
                                            type="button"
                                            className="text-sm font-semibold text-brand-deep underline"
                                            onClick={() => {
                                                setRegistrationSearch('');
                                                lastQueriedRegistration.current =
                                                    '';
                                                navigateResidentsWith({
                                                    purok: undefined,
                                                    registration_search:
                                                        undefined,
                                                    pending_page: 1,
                                                });
                                            }}
                                        >
                                            View all pending accounts
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}

                    {activeTab === 'pending' && (
                        <Deferred
                            data="pendingRegistrations"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
                            <PendingRegistrationsTable
                                applications={pendingRows}
                                paginator={pendingPaginator}
                                onNavigate={navigateResidents}
                                searchActive={
                                    registrationSearch.trim() !== ''
                                }
                                onView={openApplicationReview}
                                onReject={openReject}
                                onApprove={openApplicationReview}
                                processingApproveId={processingApproveId}
                                onRetryNotification={
                                    retryDecisionNotification
                                }
                            />
                        </Deferred>
                    )}

                    {activeTab === 'needs_correction' && (
                        <Deferred
                            data="needsCorrectionRegistrations"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
                            <PendingRegistrationsTable
                                applications={needsCorrectionRows}
                                paginator={needsCorrectionPaginator}
                                onNavigate={navigateResidents}
                                searchActive={
                                    registrationSearch.trim() !== ''
                                }
                                onView={openApplicationReview}
                                onReject={openReject}
                                onApprove={openApplicationReview}
                                processingApproveId={processingApproveId}
                                onRetryNotification={
                                    retryDecisionNotification
                                }
                            />
                        </Deferred>
                    )}

                    {activeTab === 'rejected' && (
                        <Deferred
                            data="rejectedRegistrations"
                            fallback={
                                <AdminPageSkeleton rows={7} columns={4} />
                            }
                        >
                            <RejectedRegistrationsTable
                                applications={rejectedRows}
                                paginator={rejectedPaginator}
                                onNavigate={navigateResidents}
                                searchActive={
                                    registrationSearch.trim() !== ''
                                }
                                onView={(application) =>
                                    openRegistrationDetails(application.id)
                                }
                                onRetryNotification={
                                    retryDecisionNotification
                                }
                            />
                        </Deferred>
                    )}
                </Deferred>

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
                        emailSafeReason={emailSafeRejectionReason}
                        setEmailSafeReason={setEmailSafeRejectionReason}
                        error={rejectError}
                        processing={processingReject}
                        onCancel={() => {
                            if (processingReject) {
                                return;
                            }

                            setRejectingApplication(null);
                            setRejectionReason('');
                            setEmailSafeRejectionReason('');
                            setRejectError('');
                        }}
                        onSubmit={rejectRegistration}
                    />
                )}

                {showCreateResident && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
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
                                    <CreateField
                                        label="Purok"
                                        error={
                                            createResidentForm.errors.purok_id
                                        }
                                    >
                                        <select
                                            value={
                                                createResidentForm.data.purok_id
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'purok_id',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">
                                                Select purok
                                            </option>
                                            {safePuroks.map((purok) => (
                                                <option
                                                    key={purok.id}
                                                    value={purok.id}
                                                >
                                                    {purok.name}
                                                </option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Household"
                                        error={
                                            createResidentForm.errors
                                                .household_mode
                                        }
                                    >
                                        <select
                                            value={
                                                createResidentForm.data
                                                    .household_mode
                                            }
                                            onChange={(event) => {
                                                createResidentForm.setData(
                                                    'household_mode',
                                                    event.target.value,
                                                );
                                                createResidentForm.setData(
                                                    'household_id',
                                                    '',
                                                );
                                            }}
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        >
                                            <option value="none">
                                                No household assignment
                                            </option>
                                            <option value="new">
                                                Create household, set as head
                                            </option>
                                            <option value="existing">
                                                Attach to existing household
                                            </option>
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="First Name"
                                        error={
                                            createResidentForm.errors.first_name
                                        }
                                    >
                                        <input
                                            value={
                                                createResidentForm.data
                                                    .first_name
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'first_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Middle Name"
                                        error={
                                            createResidentForm.errors
                                                .middle_name
                                        }
                                    >
                                        <input
                                            value={
                                                createResidentForm.data
                                                    .middle_name
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'middle_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Last Name"
                                        error={
                                            createResidentForm.errors.last_name
                                        }
                                    >
                                        <input
                                            value={
                                                createResidentForm.data
                                                    .last_name
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'last_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Suffix"
                                        error={createResidentForm.errors.suffix}
                                    >
                                        <input
                                            value={
                                                createResidentForm.data.suffix
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'suffix',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Birthdate"
                                        error={
                                            createResidentForm.errors.birthdate
                                        }
                                    >
                                        <BirthdateInput
                                            value={createResidentForm.data.birthdate}
                                            onChange={(value) => createResidentForm.setData('birthdate', value)}
                                            className="mt-1"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Sex"
                                        error={createResidentForm.errors.sex}
                                    >
                                        <select
                                            value={createResidentForm.data.sex}
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'sex',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select sex</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">
                                                Female
                                            </option>
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Civil Status"
                                        error={
                                            createResidentForm.errors
                                                .civil_status
                                        }
                                    >
                                        <select
                                            value={
                                                createResidentForm.data
                                                    .civil_status
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'civil_status',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">
                                                Select civil status
                                            </option>
                                            <option value="Single">
                                                Single
                                            </option>
                                            <option value="Married">
                                                Married
                                            </option>
                                            <option value="Widowed">
                                                Widowed
                                            </option>
                                            <option value="Separated">
                                                Separated
                                            </option>
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Mobile Number"
                                        error={createResidentForm.errors.mobile}
                                    >
                                        <input
                                            value={
                                                createResidentForm.data.mobile
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'mobile',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="09XXXXXXXXX"
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Email Address (optional)"
                                        error={createResidentForm.errors.email}
                                    >
                                        <input
                                            type="email"
                                            value={
                                                createResidentForm.data.email
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'email',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>
                                </div>

                                {createResidentForm.data.household_mode ===
                                    'existing' && (
                                    <div className="rounded-lg border border-line-soft p-3">
                                        <CreateField
                                            label="Search household head or code"
                                            error={
                                                createResidentForm.errors
                                                    .household_id
                                            }
                                        >
                                            <input
                                                value={householdSearch}
                                                onChange={(event) => {
                                                    setHouseholdSearch(
                                                        event.target.value,
                                                    );
                                                    createResidentForm.setData(
                                                        'household_id',
                                                        '',
                                                    );
                                                }}
                                                placeholder="Name or HH code"
                                                className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            />
                                        </CreateField>
                                        <div className="mt-2 space-y-1">
                                            {householdMatches.map(
                                                (household) => (
                                                    <button
                                                        type="button"
                                                        key={household.id}
                                                        onClick={() => {
                                                            createResidentForm.setData(
                                                                'household_id',
                                                                String(
                                                                    household.id,
                                                                ),
                                                            );
                                                            setHouseholdSearch(
                                                                `${household.code} · ${household.head}`,
                                                            );
                                                            setHouseholdMatches(
                                                                [],
                                                            );
                                                        }}
                                                        className="block w-full rounded border border-line-soft px-3 py-2 text-left text-xs hover:bg-canvas"
                                                    >
                                                        {household.code} ·{' '}
                                                        {household.head} ·{' '}
                                                        {
                                                            household.members_count
                                                        }{' '}
                                                        member(s)
                                                    </button>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                                {createResidentForm.data.household_mode !==
                                    'none' && (
                                    <CreateField
                                        label="Relationship to household head"
                                        error={
                                            createResidentForm.errors
                                                .household_relationship
                                        }
                                    >
                                        <input
                                            value={
                                                createResidentForm.data
                                                    .household_relationship
                                            }
                                            onChange={(event) =>
                                                createResidentForm.setData(
                                                    'household_relationship',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder={
                                                createResidentForm.data
                                                    .household_mode === 'new'
                                                    ? 'Head'
                                                    : 'e.g. Spouse, Child'
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>
                                )}

                                <CreateField
                                    label="Address"
                                    error={
                                        createResidentForm.errors.address_line
                                    }
                                >
                                    <textarea
                                        value={
                                            createResidentForm.data.address_line
                                        }
                                        onChange={(event) =>
                                            createResidentForm.setData(
                                                'address_line',
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                        className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        required
                                    />
                                </CreateField>

                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                                    An invitation is sent when an email address
                                    is provided. The QR code is not emailed;
                                    deliver it securely from the Resident
                                    details view.
                                </p>

                                <div className="flex justify-end gap-2 border-t border-line-soft pt-4">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowCreateResident(false)
                                        }
                                        className="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink-muted hover:bg-canvas"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={createResidentForm.processing}
                                        className="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-deep disabled:opacity-60"
                                    >
                                        {createResidentForm.processing
                                            ? 'Creating...'
                                            : 'Create Resident'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </ModalContainer>
                )}

                {editingResident && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
                            <ModalHeader
                                title={`Edit Resident · ${editingResident.resident_number}`}
                                description="Update resident profile and household assignment."
                                onClose={() => {
                                    if (!editResidentForm.processing) {
                                        setEditingResident(null);
                                    }
                                }}
                            />

                            <form
                                onSubmit={submitEditResident}
                                className="max-h-[70vh] space-y-4 overflow-y-auto px-5 py-5"
                            >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField
                                        label="Purok"
                                        error={editResidentForm.errors.purok_id}
                                    >
                                        <select
                                            value={editResidentForm.data.purok_id}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'purok_id',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select purok</option>
                                            {safePuroks.map((purok) => (
                                                <option key={purok.id} value={purok.id}>
                                                    {purok.name}
                                                </option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Household"
                                        error={editResidentForm.errors.household_mode}
                                    >
                                        <select
                                            value={editResidentForm.data.household_mode}
                                            onChange={(event) => {
                                                const mode = event.target.value;
                                                editResidentForm.setData('household_mode', mode);
                                                if (mode === 'none') {
                                                    editResidentForm.setData('household_id', '');
                                                    editResidentForm.setData('household_relationship', '');
                                                } else if (mode === 'new') {
                                                    editResidentForm.setData('household_id', '');
                                                    editResidentForm.setData('household_relationship', 'Head');
                                                }
                                            }}
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        >
                                            {editingResident.household && (
                                                <option value="preserve">
                                                    Current ({editingResident.household.code})
                                                </option>
                                            )}
                                            <option value="none">
                                                No household assignment
                                            </option>
                                            <option value="existing">
                                                Assign to existing household
                                            </option>
                                            <option value="new">
                                                Create household, set as head
                                            </option>
                                        </select>
                                    </CreateField>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField
                                        label="First name"
                                        error={editResidentForm.errors.first_name}
                                    >
                                        <input
                                            value={editResidentForm.data.first_name}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'first_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Middle name"
                                        error={editResidentForm.errors.middle_name}
                                    >
                                        <input
                                            value={editResidentForm.data.middle_name}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'middle_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField
                                        label="Last name"
                                        error={editResidentForm.errors.last_name}
                                    >
                                        <input
                                            value={editResidentForm.data.last_name}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'last_name',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Suffix"
                                        error={editResidentForm.errors.suffix}
                                    >
                                        <input
                                            value={editResidentForm.data.suffix}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'suffix',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Jr., Sr., III"
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <CreateField
                                        label="Birthdate"
                                        error={editResidentForm.errors.birthdate}
                                    >
                                        <BirthdateInput
                                            value={editResidentForm.data.birthdate}
                                            onChange={(val) =>
                                                editResidentForm.setData('birthdate', val)
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Sex"
                                        error={editResidentForm.errors.sex}
                                    >
                                        <select
                                            value={editResidentForm.data.sex}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'sex',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select sex</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Civil status"
                                        error={editResidentForm.errors.civil_status}
                                    >
                                        <select
                                            value={editResidentForm.data.civil_status}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'civil_status',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select status</option>
                                            <option value="Single">Single</option>
                                            <option value="Married">Married</option>
                                            <option value="Widowed">Widowed</option>
                                            <option value="Separated">Separated</option>
                                        </select>
                                    </CreateField>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField
                                        label="Mobile number"
                                        error={editResidentForm.errors.mobile}
                                    >
                                        <input
                                            value={editResidentForm.data.mobile}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'mobile',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="09XXXXXXXXX"
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>

                                    <CreateField
                                        label="Email"
                                        error={editResidentForm.errors.email}
                                    >
                                        <input
                                            type="email"
                                            value={editResidentForm.data.email}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'email',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        />
                                    </CreateField>
                                </div>

                                {editResidentForm.data.household_mode === 'existing' && (
                                    <div className="rounded-lg border border-line-soft p-3">
                                        <CreateField
                                            label="Search household head or code"
                                            error={editResidentForm.errors.household_id}
                                        >
                                            <input
                                                value={editHouseholdSearch}
                                                onChange={(event) => {
                                                    setEditHouseholdSearch(event.target.value);
                                                    editResidentForm.setData('household_id', '');
                                                }}
                                                placeholder="Name or HH code"
                                                className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            />
                                        </CreateField>
                                        <div className="mt-2 space-y-1">
                                            {editHouseholdMatches.map((household) => (
                                                <button
                                                    type="button"
                                                    key={household.id}
                                                    onClick={() => {
                                                        editResidentForm.setData(
                                                            'household_id',
                                                            String(household.id),
                                                        );
                                                        setEditHouseholdSearch(
                                                            `${household.code} · ${household.head}`,
                                                        );
                                                        setEditHouseholdMatches([]);
                                                    }}
                                                    className="block w-full rounded border border-line-soft px-3 py-2 text-left text-xs hover:bg-canvas"
                                                >
                                                    {household.code} · {household.head} ·{' '}
                                                    {household.members_count} member(s)
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {editResidentForm.data.household_mode !== 'none' && (
                                    <CreateField
                                        label="Relationship to household head"
                                        error={editResidentForm.errors.household_relationship}
                                    >
                                        <input
                                            value={editResidentForm.data.household_relationship}
                                            onChange={(event) =>
                                                editResidentForm.setData(
                                                    'household_relationship',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder={
                                                editResidentForm.data.household_mode === 'new'
                                                    ? 'Head'
                                                    : 'e.g. Spouse, Child'
                                            }
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        />
                                    </CreateField>
                                )}

                                <CreateField
                                    label="Address"
                                    error={editResidentForm.errors.address_line}
                                >
                                    <textarea
                                        value={editResidentForm.data.address_line}
                                        onChange={(event) =>
                                            editResidentForm.setData(
                                                'address_line',
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                        className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        required
                                    />
                                </CreateField>

                                <div className="flex justify-end gap-2 border-t border-line-soft pt-4">
                                    <button
                                        type="button"
                                        onClick={() => setEditingResident(null)}
                                        className="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink-muted hover:bg-canvas"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editResidentForm.processing}
                                        className="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-deep disabled:opacity-60"
                                    >
                                        {editResidentForm.processing
                                            ? 'Saving...'
                                            : 'Save Changes'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </ModalContainer>
                )}

                {details && (
                    <ResidentDetailsModal
                        details={details}
                        onClose={closeDetails}
                        onRetryNotification={retryDecisionNotification}
                    />
                )}

                {showCreateHousehold && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
                            <ModalHeader
                                title="Create Household"
                                description="Establish a new household, select purok, and optionally attach initial members."
                                onClose={() => {
                                    if (!createHouseholdForm.processing) {
                                        setShowCreateHousehold(false);
                                    }
                                }}
                            />
                            <form
                                onSubmit={submitCreateHousehold}
                                className="max-h-[75vh] space-y-4 overflow-y-auto px-5 py-5"
                            >
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <CreateField
                                        label="Purok"
                                        error={createHouseholdForm.errors.purok_id}
                                    >
                                        <select
                                            value={createHouseholdForm.data.purok_id}
                                            onChange={(e) => createHouseholdForm.setData('purok_id', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="">Select purok</option>
                                            {safePuroks.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <CreateField
                                        label="Household Code (optional)"
                                        error={createHouseholdForm.errors.household_code}
                                    >
                                        <input
                                            type="text"
                                            value={createHouseholdForm.data.household_code}
                                            onChange={(e) => createHouseholdForm.setData('household_code', e.target.value)}
                                            placeholder="Leave blank to auto-generate"
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm uppercase"
                                        />
                                    </CreateField>
                                </div>

                                <div className="rounded-lg border border-line-soft bg-canvas p-4">
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink">
                                        Household Members (optional)
                                    </h4>
                                    <p className="mt-1 text-xs text-ink-muted">
                                        Search and select residents to attach to this household.
                                    </p>

                                    <div className="relative mt-3">
                                        <input
                                            type="text"
                                            value={createHouseholdResidentSearch}
                                            onChange={(e) => setCreateHouseholdResidentSearch(e.target.value)}
                                            placeholder="Search resident by name..."
                                            className="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm"
                                        />
                                        {createHouseholdResidentMatches.length > 0 && (
                                            <div className="absolute z-10 mt-1 max-h-40 w-full overflow-y-auto rounded-md border border-line-soft bg-surface shadow-lg">
                                                {createHouseholdResidentMatches.map((res) => {
                                                    const alreadyAdded = createHouseholdForm.data.members.some(
                                                        (m) => m.resident_id === res.id
                                                    );
                                                    return (
                                                        <button
                                                            key={res.id}
                                                            type="button"
                                                            disabled={alreadyAdded}
                                                            onClick={() => {
                                                                createHouseholdForm.setData('members', [
                                                                    ...createHouseholdForm.data.members,
                                                                    {
                                                                        resident_id: res.id,
                                                                        resident_name: res.full_name,
                                                                        household_relationship: createHouseholdForm.data.members.length === 0 ? 'Head' : 'Member',
                                                                    },
                                                                ]);
                                                                if (createHouseholdForm.data.members.length === 0) {
                                                                    createHouseholdForm.setData('head_resident_id', String(res.id));
                                                                }
                                                                setCreateHouseholdResidentSearch('');
                                                                setCreateHouseholdResidentMatches([]);
                                                            }}
                                                            className="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-canvas disabled:opacity-50"
                                                        >
                                                            <span className="font-medium text-ink-strong">{res.full_name}</span>
                                                            <span className="text-ink-faint">{res.purok || 'Resident'}</span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>

                                    {createHouseholdForm.data.members.length > 0 && (
                                        <div className="mt-3 space-y-2">
                                            {createHouseholdForm.data.members.map((member, idx) => (
                                                <div
                                                    key={member.resident_id}
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded border border-line-soft bg-surface p-2.5 text-xs"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="radio"
                                                            name="create_head_radio"
                                                            checked={createHouseholdForm.data.head_resident_id === String(member.resident_id)}
                                                            onChange={() => createHouseholdForm.setData('head_resident_id', String(member.resident_id))}
                                                            title="Designate as head"
                                                        />
                                                        <span className="font-medium text-ink-strong">{member.resident_name}</span>
                                                        {createHouseholdForm.data.head_resident_id === String(member.resident_id) && (
                                                            <span className="rounded bg-brand-soft px-1.5 py-0.5 text-[10px] font-semibold text-brand-deep">
                                                                Head
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="text"
                                                            value={member.household_relationship}
                                                            onChange={(e) => {
                                                                const updated = [...createHouseholdForm.data.members];
                                                                updated[idx].household_relationship = e.target.value;
                                                                createHouseholdForm.setData('members', updated);
                                                            }}
                                                            placeholder="Relationship"
                                                            className="w-28 rounded border border-line px-2 py-1 text-xs"
                                                            required
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                const updated = createHouseholdForm.data.members.filter((_, i) => i !== idx);
                                                                createHouseholdForm.setData('members', updated);
                                                                if (createHouseholdForm.data.head_resident_id === String(member.resident_id)) {
                                                                    createHouseholdForm.setData('head_resident_id', '');
                                                                }
                                                            }}
                                                            className="text-red-600 hover:text-red-800"
                                                        >
                                                            &times;
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3 pt-3">
                                    <button
                                        type="button"
                                        onClick={() => setShowCreateHousehold(false)}
                                        className="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink-muted hover:bg-canvas"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={createHouseholdForm.processing}
                                        className="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-deep disabled:opacity-60"
                                    >
                                        {createHouseholdForm.processing ? 'Creating...' : 'Create Household'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </ModalContainer>
                )}

                {viewingHousehold && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
                            <ModalHeader
                                title={`Household ${viewingHousehold.code}`}
                                description={`Purok: ${viewingHousehold.purok_name || 'Unassigned'} · Created: ${viewingHousehold.created_at || 'N/A'}`}
                                onClose={() => setViewingHousehold(null)}
                            />
                            <div className="max-h-[75vh] space-y-5 overflow-y-auto px-5 py-5 text-sm">
                                <div className="grid grid-cols-2 gap-4 rounded-lg bg-canvas p-4">
                                    <div>
                                        <p className="text-xs font-semibold uppercase text-ink-muted">Family Head</p>
                                        <p className="mt-1 font-medium text-ink-strong">
                                            {viewingHousehold.head?.name || 'No Head Assigned'}
                                        </p>
                                        {viewingHousehold.head?.mobile && (
                                            <p className="text-xs text-ink-muted">{viewingHousehold.head.mobile}</p>
                                        )}
                                    </div>
                                    <div>
                                        <p className="text-xs font-semibold uppercase text-ink-muted">Total Members</p>
                                        <p className="mt-1 font-medium text-ink-strong">{viewingHousehold.members_count}</p>
                                    </div>
                                </div>

                                <div>
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink">
                                        Members Roster
                                    </h4>
                                    <div className="mt-2 overflow-hidden rounded-lg border border-line-soft">
                                        <table className="w-full text-left text-xs">
                                            <thead className="border-b border-line-soft bg-canvas font-semibold text-ink-muted">
                                                <tr>
                                                    <th className="px-3 py-2">Name</th>
                                                    <th className="px-3 py-2">Relationship</th>
                                                    <th className="px-3 py-2">Sex</th>
                                                    <th className="px-3 py-2">Birthdate</th>
                                                    <th className="px-3 py-2 text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line-soft">
                                                {viewingHousehold.members.map((m) => (
                                                    <tr key={m.id}>
                                                        <td className="px-3 py-2.5 font-medium text-ink-strong">
                                                            {m.name}
                                                            {m.is_head && (
                                                                <span className="ml-2 inline-flex items-center rounded bg-brand-soft px-1.5 py-0.5 text-[10px] font-semibold text-brand-deep">
                                                                    Head
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-ink-muted">{m.relationship || 'Member'}</td>
                                                        <td className="px-3 py-2.5 text-ink-muted">{m.sex || '—'}</td>
                                                        <td className="px-3 py-2.5 text-ink-muted">{m.birthdate || '—'}</td>
                                                        <td className="px-3 py-2.5 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setViewingHousehold(null);
                                                                    openResidentDetails(m.id);
                                                                }}
                                                                className="font-medium text-brand hover:underline"
                                                            >
                                                                View
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {viewingHousehold.members.length === 0 && (
                                                    <tr>
                                                        <td colSpan={5} className="px-3 py-6 text-center text-ink-faint">
                                                            No members currently assigned to this household.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="flex justify-end pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setViewingHousehold(null)}
                                        className="rounded-md border border-line px-4 py-2 text-xs font-medium text-ink hover:bg-canvas"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </ModalContainer>
                )}

                {editingHousehold && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
                            <ModalHeader
                                title={`Edit Household — ${editingHousehold.code}`}
                                description="Update household details, manage member roster, or transfer members."
                                onClose={() => {
                                    if (!editHouseholdForm.processing) {
                                        setEditingHousehold(null);
                                    }
                                }}
                            />
                            <div className="max-h-[75vh] space-y-5 overflow-y-auto px-5 py-5 text-sm">
                                <form onSubmit={submitEditHousehold} className="space-y-4">
                                    <CreateField label="Purok" error={editHouseholdForm.errors.purok_id}>
                                        <select
                                            value={editHouseholdForm.data.purok_id}
                                            onChange={(e) => editHouseholdForm.setData('purok_id', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                            required
                                        >
                                            {safePuroks.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <CreateField label="Designated Head" error={editHouseholdForm.errors.head_resident_id}>
                                        <select
                                            value={editHouseholdForm.data.head_resident_id}
                                            onChange={(e) => editHouseholdForm.setData('head_resident_id', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm"
                                        >
                                            <option value="">No head assigned</option>
                                            {editingHousehold.members.map((m) => (
                                                <option key={m.id} value={m.id}>
                                                    {m.name} ({m.relationship || 'Member'})
                                                </option>
                                            ))}
                                        </select>
                                    </CreateField>

                                    <div className="flex justify-end">
                                        <button
                                            type="submit"
                                            disabled={editHouseholdForm.processing}
                                            className="rounded-md bg-brand px-4 py-2 text-xs font-semibold text-white hover:bg-brand-deep disabled:opacity-60"
                                        >
                                            {editHouseholdForm.processing ? 'Saving...' : 'Update Details'}
                                        </button>
                                    </div>
                                </form>

                                <div className="border-t border-line-soft pt-4">
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink">
                                        Current Members ({editingHousehold.members.length})
                                    </h4>

                                    <div className="mt-2 divide-y divide-line-soft rounded-lg border border-line-soft">
                                        {editingHousehold.members.map((m) => (
                                            <div key={m.id} className="flex items-center justify-between p-3 text-xs">
                                                <div>
                                                    <span className="font-medium text-ink-strong">{m.name}</span>
                                                    {m.is_head && (
                                                        <span className="ml-2 rounded bg-brand-soft px-1.5 py-0.5 text-[10px] font-semibold text-brand-deep">
                                                            Head
                                                        </span>
                                                    )}
                                                    <p className="text-ink-muted">{m.relationship || 'Member'}</p>
                                                </div>
                                                <div className="flex gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setTransferringMember(m);
                                                            transferMemberForm.setData({
                                                                target_household_id: '',
                                                                household_relationship: m.relationship || 'Member',
                                                                is_head: false,
                                                            });
                                                        }}
                                                        className="rounded border border-line px-2 py-1 text-ink-muted hover:bg-canvas"
                                                    >
                                                        Transfer
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleRemoveMember(editingHousehold.id, m.id)}
                                                        className="rounded border border-red-200 px-2 py-1 text-red-600 hover:bg-red-50"
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                        {editingHousehold.members.length === 0 && (
                                            <p className="p-4 text-center text-xs text-ink-faint">
                                                No members in this household.
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="rounded-lg border border-line-soft bg-canvas p-4">
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink">
                                        Add Member to Household
                                    </h4>
                                    <div className="mt-3 space-y-3">
                                        <div className="relative">
                                            <input
                                                type="text"
                                                value={addMemberResidentSearch}
                                                onChange={(e) => {
                                                    setAddMemberResidentSearch(e.target.value);
                                                    // Typing always invalidates a prior pick, so
                                                    // free text can never be submitted as an id.
                                                    setSelectedAddMemberResident(null);
                                                    addMemberForm.setData('resident_id', '');
                                                    addMemberForm.clearErrors('resident_id');
                                                }}
                                                placeholder="Search resident by full name..."
                                                className="w-full rounded-md border border-line bg-surface px-3 py-2 text-xs"
                                                autoComplete="off"
                                            />
                                            {addMemberResidentMatches.length > 0 && (
                                                <div className="absolute z-10 mt-1 max-h-36 w-full overflow-y-auto rounded-md border border-line-soft bg-surface shadow-lg">
                                                    {addMemberResidentMatches.map((res) => (
                                                        <button
                                                            key={res.id}
                                                            type="button"
                                                            onClick={() => {
                                                                addMemberForm.setData('resident_id', String(res.id));
                                                                setSelectedAddMemberResident({
                                                                    id: res.id,
                                                                    full_name: res.full_name,
                                                                });
                                                                setAddMemberResidentSearch(res.full_name);
                                                                setAddMemberResidentMatches([]);
                                                            }}
                                                            className="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-canvas"
                                                        >
                                                            <span className="font-medium text-ink-strong">{res.full_name}</span>
                                                            <span className="text-ink-faint">{res.purok}</span>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {addMemberForm.errors.resident_id && (
                                            <p className="text-[11px] text-red-600">
                                                {addMemberForm.errors.resident_id}
                                            </p>
                                        )}

                                        {selectedAddMemberResident && (
                                            <div className="flex items-center justify-between rounded-md border border-line-soft bg-surface px-3 py-2 text-xs">
                                                <span className="text-ink">
                                                    Selected:{' '}
                                                    <span className="font-semibold text-ink-strong">
                                                        {selectedAddMemberResident.full_name}
                                                    </span>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedAddMemberResident(null);
                                                        setAddMemberResidentSearch('');
                                                        addMemberForm.setData('resident_id', '');
                                                    }}
                                                    className="font-medium text-brand hover:underline"
                                                >
                                                    Change
                                                </button>
                                            </div>
                                        )}

                                        {selectedAddMemberResident && (
                                            <form onSubmit={submitAddMember} className="flex flex-wrap items-center gap-3">
                                                <input
                                                    type="text"
                                                    value={addMemberForm.data.household_relationship}
                                                    onChange={(e) => addMemberForm.setData('household_relationship', e.target.value)}
                                                    placeholder="Relationship (e.g. Spouse, Child)"
                                                    className="w-48 rounded border border-line px-3 py-1.5 text-xs"
                                                    required
                                                />
                                                <label className="flex items-center gap-1.5 text-xs text-ink">
                                                    <input
                                                        type="checkbox"
                                                        checked={addMemberForm.data.is_head}
                                                        onChange={(e) => addMemberForm.setData('is_head', e.target.checked)}
                                                    />
                                                    Designate as Head
                                                </label>
                                                <button
                                                    type="submit"
                                                    disabled={addMemberForm.processing || !addMemberForm.data.resident_id}
                                                    className="rounded bg-brand px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-hover disabled:opacity-50"
                                                >
                                                    {addMemberForm.processing ? 'Adding...' : 'Add Member'}
                                                </button>
                                            </form>
                                        )}
                                    </div>
                                </div>

                                <div className="flex justify-end pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditingHousehold(null)}
                                        className="rounded-md border border-line px-4 py-2 text-xs font-medium text-ink hover:bg-canvas"
                                    >
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </ModalContainer>
                )}

                {transferringMember && editingHousehold && (
                    <ModalContainer>
                        <div className="my-auto w-full max-w-md overflow-hidden rounded-xl bg-surface shadow-xl">
                            <ModalHeader
                                title={`Transfer ${transferringMember.name}`}
                                description={`Transfer out of ${editingHousehold.code} to another household.`}
                                onClose={() => setTransferringMember(null)}
                            />
                            <form onSubmit={submitTransferMember} className="space-y-4 px-5 py-5 text-xs">
                                <div>
                                    <label className="font-semibold text-ink">Target Household</label>
                                    <div className="relative mt-1">
                                        <input
                                            type="text"
                                            value={transferHouseholdSearch}
                                            onChange={(e) => setTransferHouseholdSearch(e.target.value)}
                                            placeholder="Search target household code or head..."
                                            className="w-full rounded-md border border-line px-3 py-2 text-xs"
                                        />
                                        {transferHouseholdMatches.length > 0 && (
                                            <div className="absolute z-10 mt-1 max-h-36 w-full overflow-y-auto rounded-md border border-line-soft bg-surface shadow-lg">
                                                {transferHouseholdMatches
                                                    .filter((h) => h.id !== editingHousehold.id)
                                                    .map((h) => (
                                                        <button
                                                            key={h.id}
                                                            type="button"
                                                            onClick={() => {
                                                                transferMemberForm.setData('target_household_id', String(h.id));
                                                                setTransferHouseholdSearch(`${h.code} (${h.head})`);
                                                                setTransferHouseholdMatches([]);
                                                            }}
                                                            className="flex w-full items-center justify-between px-3 py-2 text-left text-xs hover:bg-canvas"
                                                        >
                                                            <span className="font-mono font-medium text-brand">{h.code}</span>
                                                            <span className="text-ink-muted">{h.head}</span>
                                                        </button>
                                                    ))}
                                            </div>
                                        )}
                                    </div>
                                    {transferMemberForm.errors.target_household_id && (
                                        <p className="mt-1 text-red-600">{transferMemberForm.errors.target_household_id}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="font-semibold text-ink">Relationship in New Household</label>
                                    <input
                                        type="text"
                                        value={transferMemberForm.data.household_relationship}
                                        onChange={(e) => transferMemberForm.setData('household_relationship', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-line px-3 py-2 text-xs"
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="flex items-center gap-2 text-ink">
                                        <input
                                            type="checkbox"
                                            checked={transferMemberForm.data.is_head}
                                            onChange={(e) => transferMemberForm.setData('is_head', e.target.checked)}
                                        />
                                        Designate as head in target household
                                    </label>
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setTransferringMember(null)}
                                        className="rounded border border-line px-3 py-1.5 text-ink-muted hover:bg-canvas"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={transferMemberForm.processing || !transferMemberForm.data.target_household_id}
                                        className="rounded bg-brand px-3 py-1.5 font-medium text-white hover:bg-brand-hover disabled:opacity-50"
                                    >
                                        {transferMemberForm.processing ? 'Transferring...' : 'Confirm Transfer'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </ModalContainer>
                )}
            </AdminLayout>
        </>
    );
}

function HouseholdsTable({
    paginator,
    onNavigate,
    onViewHousehold,
    onEditHousehold,
    loadingHouseholdId,
}: {
    paginator: Paginator<HouseholdRow>;
    onNavigate: (url: string) => void;
    onViewHousehold: (householdId: number) => void;
    onEditHousehold: (householdId: number) => void;
    loadingHouseholdId: number | null;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-line-soft bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[640px]">
                    <thead className="border-b border-line-soft bg-canvas">
                        <tr>
                            <TableHead>Household Code</TableHead>
                            <TableHead>Family Head</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Members</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {paginator.data.map((household) => (
                            <tr
                                key={household.id}
                                className="border-b border-line-soft"
                            >
                                <td className="px-5 py-4 font-mono text-xs font-semibold text-brand">
                                    {household.code}
                                </td>
                                <td className="px-5 py-4 text-sm text-ink-strong">
                                    {household.head}
                                </td>
                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {household.purok}
                                </td>
                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {household.members_count}
                                </td>
                                <td className="px-5 py-4">
                                    <div className="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() => onViewHousehold(household.id)}
                                            disabled={loadingHouseholdId === household.id}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink-muted hover:bg-canvas disabled:opacity-50"
                                        >
                                            {loadingHouseholdId === household.id ? 'Loading...' : 'View'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => onEditHousehold(household.id)}
                                            disabled={loadingHouseholdId === household.id}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink hover:bg-canvas disabled:opacity-50"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {paginator.data.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-5 py-12 text-center text-sm text-ink-muted"
                                >
                                    No households match this filter.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination
                paginator={paginator}
                onNavigate={onNavigate}
                label="households"
            />
        </div>
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
        <div className="overflow-hidden rounded-lg border border-line-soft bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1120px]">
                    <thead className="border-b border-line-soft bg-canvas">
                        <tr>
                            <TableHead>Resident ID</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Resident Status</TableHead>
                            <TableHead>Login Account</TableHead>
                            <TableHead>QR Code</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold tracking-wide text-ink-faint uppercase">
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
                                        ? 'border-b border-line-soft'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4 font-mono text-xs font-semibold text-brand">
                                    <button
                                        type="button"
                                        onClick={() => onView(resident)}
                                        className="text-left hover:underline focus-visible:rounded focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                        aria-label={`View details for Resident ID ${resident.residentId}`}
                                    >
                                        {resident.residentId}
                                    </button>
                                </td>
                                <td className="px-5 py-4">
                                    <button
                                        type="button"
                                        onClick={() => onView(resident)}
                                        className="text-left text-sm font-medium text-ink-strong hover:text-brand hover:underline"
                                    >
                                        {resident.fullName}
                                    </button>
                                    <p className="mt-0.5 text-xs text-ink-faint">
                                        {resident.sex}
                                    </p>
                                </td>
                                <td className="px-5 py-4 text-sm text-ink-muted">
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
                                            className="text-xs font-medium text-brand hover:underline"
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
                                            className="text-xs font-medium text-brand hover:underline"
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
                                            className="text-xs font-medium text-brand hover:underline"
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
                                    <p className="text-sm font-medium text-ink-muted">
                                        No Residents added by Admin yet
                                    </p>
                                    <p className="mt-1 text-xs text-ink-faint">
                                        Approved resident accounts will appear
                                        here.
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
                    ? 'bg-brand-soft text-brand'
                    : 'bg-secondary text-ink-muted'
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
        <div className="flex flex-col gap-3 border-t border-line-soft px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-ink-muted">
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
                    className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink-muted hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Previous
                </button>

                <span className="text-xs text-ink-muted">
                    Page {paginator.current_page} of {paginator.last_page}
                </span>

                <button
                    type="button"
                    disabled={!paginator.next_page_url}
                    onClick={() =>
                        paginator.next_page_url &&
                        onNavigate(paginator.next_page_url)
                    }
                    className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink-muted hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-50"
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
    onRetryNotification,
}: {
    applications: PendingRegistration[];
    paginator: RegistrationPaginator;
    onNavigate: (url: string) => void;
    searchActive: boolean;
    onView: (application: PendingRegistration) => void;
    onReject: (application: PendingRegistration) => void;
    onApprove: (application: PendingRegistration) => void;
    processingApproveId: number | null;
    onRetryNotification: (notificationId: number) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-line-soft bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[950px]">
                    <thead className="border-b border-line-soft bg-canvas">
                        <tr>
                            <TableHead>Applicant</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Submitted</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold tracking-wide text-ink-faint uppercase">
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
                                        ? 'border-b border-line-soft'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-ink-strong">
                                        {getApplicantName(application)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-ink-faint">
                                        {application.mobile}
                                    </p>
                                    {application.decision_notification && (
                                        <p className="mt-1 text-xs text-ink-muted">
                                            Decision email:{' '}
                                            {
                                                application
                                                    .decision_notification
                                                    .status
                                            }
                                        </p>
                                    )}
                                </td>

                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {application.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges
                                        sectors={application.sectors}
                                    />
                                </td>

                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {application.submitted_at || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            onClick={() => onView(application)}
                                            className="rounded-md border border-line px-3 py-2 text-xs font-medium text-ink-muted hover:bg-canvas"
                                        >
                                            View
                                        </button>

                                        {application.decision_notification
                                            ?.status === 'failed' && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onRetryNotification(
                                                        application
                                                            .decision_notification!
                                                            .id,
                                                    )
                                                }
                                                className="rounded-md border border-amber-300 px-3 py-2 text-xs font-medium text-amber-800 hover:bg-amber-50"
                                            >
                                                Retry email
                                            </button>
                                        )}

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onReject(application)
                                            }
                                            className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50"
                                        >
                                            Reject
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onApprove(application)
                                            }
                                            disabled={
                                                processingApproveId !== null
                                            }
                                            className="rounded-md bg-brand px-3 py-2 text-xs font-medium text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {processingApproveId ===
                                            application.id
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
                                    <p className="text-sm font-medium text-ink-muted">
                                        {searchActive
                                            ? 'No matching registrations'
                                            : 'No pending registrations'}
                                    </p>
                                    <p className="mt-1 text-xs text-ink-faint">
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
    onEditResident,
    onToggleAccount,
    loadingEditResidentId,
}: {
    paginator: AllResidentsPaginator;
    onNavigate: (url: string) => void;
    onViewResident: (id: number) => void;
    onEditResident: (id: number) => void;
    onToggleAccount: (resident: { id: number; fullName: string; accountActive: boolean | null }) => void;
    loadingEditResidentId?: number | null;
}) {
    const rows = Array.isArray(paginator.data) ? paginator.data : [];

    return (
        <div className="overflow-hidden rounded-lg border border-line-soft bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1080px]">
                    <thead className="border-b border-line-soft bg-canvas">
                        <tr>
                            <TableHead>Resident ID</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead>Resident Status</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold tracking-wide text-ink-faint uppercase">
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
                                        ? 'border-b border-line-soft'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4 font-mono text-xs font-semibold text-ink-muted">
                                    {row.reference}
                                </td>

                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-ink-strong">
                                        {row.fullName}
                                    </p>
                                    <p className="mt-0.5 text-xs text-ink-faint">
                                        {row.sex || '—'}
                                    </p>
                                </td>

                                <td className="px-5 py-4 text-sm text-ink-muted">
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
                                    <div className="flex justify-end gap-3">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                onViewResident(row.id)
                                            }
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink-muted hover:bg-canvas"
                                        >
                                            View
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onEditResident(row.id)
                                            }
                                            disabled={loadingEditResidentId === row.id}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-medium text-ink hover:bg-canvas disabled:opacity-50"
                                        >
                                            {loadingEditResidentId === row.id ? 'Loading...' : 'Edit'}
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                onToggleAccount(row)
                                            }
                                            className="text-xs font-medium text-brand hover:underline"
                                        >
                                            {row.accountActive
                                                ? 'Disable'
                                                : 'Enable'}
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
                                    <p className="text-sm font-medium text-ink-muted">
                                        No residents found
                                    </p>
                                    <p className="mt-1 text-xs text-ink-faint">
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
    onRetryNotification,
}: {
    applications: PendingRegistration[];
    paginator: RegistrationPaginator;
    onNavigate: (url: string) => void;
    searchActive: boolean;
    onView: (application: PendingRegistration) => void;
    onRetryNotification: (notificationId: number) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-line-soft bg-surface">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[950px]">
                    <thead className="border-b border-line-soft bg-canvas">
                        <tr>
                            <TableHead>Applicant</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Reviewed</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold tracking-wide text-ink-faint uppercase">
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
                                        ? 'border-b border-line-soft'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-ink-strong">
                                        {getApplicantName(application)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-ink-faint">
                                        {application.mobile}
                                    </p>
                                    {application.decision_notification && (
                                        <p className="mt-1 text-xs text-ink-muted">
                                            Decision email:{' '}
                                            {
                                                application
                                                    .decision_notification
                                                    .status
                                            }
                                        </p>
                                    )}
                                </td>

                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {application.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges
                                        sectors={application.sectors}
                                    />
                                </td>

                                <td className="px-5 py-4 text-sm text-ink-muted">
                                    {application.reviewed_at ||
                                        application.submitted_at ||
                                        '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end">
                                        {application.decision_notification
                                            ?.status === 'failed' && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onRetryNotification(
                                                        application
                                                            .decision_notification!
                                                            .id,
                                                    )
                                                }
                                                className="mr-2 rounded-md border border-amber-300 px-3 py-2 text-xs font-medium text-amber-800 hover:bg-amber-50"
                                            >
                                                Retry email
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => onView(application)}
                                            className="rounded-md border border-line px-3 py-2 text-xs font-medium text-ink-muted hover:bg-canvas"
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
                                    <p className="text-sm font-medium text-ink-muted">
                                        {searchActive
                                            ? 'No matching rejected registrations'
                                            : 'No rejected registrations'}
                                    </p>
                                    <p className="mt-1 text-xs text-ink-faint">
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
        active: 'bg-brand-soft text-brand',
        approved: 'bg-brand-soft text-brand',
        pending: 'bg-warning-soft text-warning',
        needs_correction: 'bg-danger-soft text-destructive',
        rejected: 'bg-red-50 text-red-600',
        inactive: 'bg-secondary text-ink-muted',
    };

    return (
        <span
            className={`inline-flex rounded-full px-3 py-1 text-xs font-medium capitalize ${
                styles[normalized] ?? 'bg-secondary text-ink-muted'
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
    return [
        person.first_name,
        person.middle_name,
        person.last_name,
        person.suffix,
    ]
        .filter(Boolean)
        .join(' ');
}

function ResidentDetailsBody({
    resident,
    onRetryNotification,
}: {
    resident: ResidentDetails;
    onRetryNotification: (notificationId: number) => void;
}) {
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
                <Info
                    label="Email Verification"
                    value={resident.email_verified ? 'Verified' : 'Unverified'}
                />
                <Info label="Address" value={resident.address_line || '—'} />
                <Info label="Barangay" value={resident.barangay || '—'} />
                <Info label="Purok" value={resident.purok || '—'} />
                <Info label="Resident Status" value={resident.status || '—'} />
                <Info
                    label="Source"
                    value={
                        resident.source === 'admin'
                            ? 'Admin-created'
                            : 'Registration'
                    }
                />
                <Info label="Approved At" value={resident.approved_at || '—'} />
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

            {resident.decision_notification && (
                <div className="flex items-center justify-between rounded-lg border border-line-soft px-3 py-2">
                    <p className="text-xs text-ink-muted">
                        Registration email:{' '}
                        {resident.decision_notification.status} ·{' '}
                        {resident.decision_notification.attempts} attempt(s)
                    </p>
                    {resident.decision_notification.status === 'failed' && (
                        <button
                            type="button"
                            onClick={() =>
                                onRetryNotification(
                                    resident.decision_notification!.id,
                                )
                            }
                            className="rounded-md border border-amber-300 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-50"
                        >
                            Retry email
                        </button>
                    )}
                </div>
            )}

            <ResidentQrPanel residentId={resident.id} />

            <div className="rounded-lg border border-line-soft p-4">
                <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                    Family / Household
                </p>
                {resident.household ? (
                    <>
                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Info
                                label="Household code"
                                value={resident.household.code}
                            />
                            <Info
                                label="Family head"
                                value={
                                    resident.household.head || 'Not assigned'
                                }
                            />
                            <Info
                                label="Relationship"
                                value={
                                    resident.household.relationship ||
                                    'Not recorded'
                                }
                            />
                        </div>
                        <div className="mt-4 border-t border-line-soft pt-3">
                            <p className="text-xs font-medium text-ink-muted">
                                Members
                            </p>
                            <div className="mt-2 space-y-1 text-xs text-ink-muted">
                                {resident.household.members.map((member) => (
                                    <p key={member.id}>
                                        {member.full_name}
                                        {member.relationship
                                            ? ` · ${member.relationship}`
                                            : ''}
                                    </p>
                                ))}
                            </div>
                        </div>
                    </>
                ) : (
                    <p className="mt-2 text-sm text-ink-muted">
                        No household assignment.
                    </p>
                )}
            </div>

            <div>
                <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                    Sectors
                </p>
                <div className="mt-2">
                    <SectorBadges sectors={resident.sectors} />
                </div>
            </div>

            {resident.designations && resident.designations.length > 0 && (
                <div>
                    <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                        Designations
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {resident.designations.map((designation, idx) => (
                            <span
                                key={idx}
                                className="inline-flex items-center rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-medium text-brand-deep"
                            >
                                {designation}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {resident.staff_assignments && resident.staff_assignments.length > 0 && (
                <div className="rounded-lg border border-line-soft p-4">
                    <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                        Active Staff Assignments
                    </p>
                    <div className="mt-3 space-y-2">
                        {resident.staff_assignments.map((assignment, idx) => (
                            <div
                                key={idx}
                                className="flex flex-col text-xs sm:flex-row sm:items-center sm:justify-between"
                            >
                                <span className="font-medium text-ink-strong">
                                    {assignment.position || 'Staff Member'}
                                    {assignment.department ? ` · ${assignment.department}` : ''}
                                </span>
                                <span className="text-ink-muted">
                                    {assignment.barangay || 'Barangay'}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
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
        <div className="rounded-lg border border-line-soft bg-canvas p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                        QR Credential Delivery
                    </p>
                    <p className="mt-1 text-xs leading-5 text-ink-muted">
                        Reveal only when handing the credential to the Resident
                        in person or through an approved secure channel. This
                        action is audited.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={reveal}
                    disabled={loading}
                    className="rounded-md border border-brand px-3 py-2 text-xs font-semibold text-brand hover:bg-brand-soft disabled:opacity-60"
                >
                    {loading
                        ? 'Loading...'
                        : credential
                          ? 'Refresh QR'
                          : 'Reveal QR'}
                </button>
            </div>

            {error && (
                <p className="mt-3 text-xs font-medium text-red-600">{error}</p>
            )}

            {credential && (
                <div className="mt-4 flex flex-col items-center gap-3 rounded-lg bg-surface p-4 sm:flex-row">
                    <QRCodeSVG value={credential.verification_url} size={148} />
                    <div className="min-w-0 text-center sm:text-left">
                        <p className="text-sm font-semibold text-ink-strong">
                            {credential.full_name}
                        </p>
                        <p className="mt-1 font-mono text-xs text-ink-muted">
                            {credential.resident_number}
                        </p>
                        <p className="mt-2 text-[10px] leading-4 break-all text-ink-faint">
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
            <label className="block text-xs font-semibold text-ink">
                {label}
            </label>
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
                <span className="font-mono text-xs text-ink-muted">
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
                <Info
                    label="Address"
                    value={registration.address_line || '—'}
                />
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
                <div className="rounded-lg border border-line-soft bg-canvas p-4">
                    <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                        Review Notes
                    </p>
                    <p className="mt-1 text-sm whitespace-pre-line text-ink">
                        {registration.review_notes}
                    </p>
                </div>
            )}

            {registration.matched_resident && (
                <section className="rounded-lg border border-brand-muted bg-brand-soft p-4">
                    <p className="text-[10px] font-semibold tracking-wide text-brand uppercase">
                        Linked Official Resident
                    </p>
                    <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Info
                            label="Resident ID"
                            value={
                                registration.matched_resident.resident_number
                            }
                        />
                        <Info
                            label="Name"
                            value={registration.matched_resident.full_name}
                        />
                        <Info
                            label="Birthdate"
                            value={
                                registration.matched_resident.birthdate || '—'
                            }
                        />
                        <Info
                            label="Purok"
                            value={registration.matched_resident.purok || '—'}
                        />
                        <Info
                            label="Resident status"
                            value={registration.matched_resident.status}
                        />
                        <Info
                            label="Account"
                            value={
                                registration.matched_resident.account_exists
                                    ? registration.matched_resident
                                          .account_active
                                        ? 'Linked and enabled'
                                        : 'Linked and disabled'
                                    : 'No account linked'
                            }
                        />
                    </div>
                    {registration.matched_at && (
                        <p className="mt-2 text-xs text-ink-muted">
                            Linked at {registration.matched_at}
                        </p>
                    )}
                </section>
            )}

            <div>
                <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                    Sectors
                </p>
                <div className="mt-2">
                    <SectorBadges sectors={registration.sectors} />
                </div>
            </div>

            <div>
                <p className="mb-2 text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                    Documents
                </p>
                <ApplicationDocuments documents={registration.documents} />
            </div>

            {(registration.correction_cycles?.length ?? 0) > 0 && (
                <section className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p className="text-[10px] font-semibold tracking-wide text-amber-900 uppercase">
                        Correction history
                    </p>
                    {registration.correction_cycles?.map((cycle) => (
                        <div
                            key={cycle.cycle_number}
                            className="border-t border-amber-200 pt-3 first:border-0 first:pt-0"
                        >
                            <p className="text-xs font-semibold text-ink-strong">
                                Cycle {cycle.cycle_number} ·{' '}
                                {cycle.requested_at ||
                                    'Request time unavailable'}
                                {cycle.resubmitted_at
                                    ? ` · Resubmitted ${cycle.resubmitted_at}`
                                    : ' · Awaiting applicant'}
                            </p>
                            <p className="mt-1 text-sm whitespace-pre-line text-ink">
                                {cycle.instructions}
                            </p>
                            <p className="mt-1 text-xs text-ink-muted">
                                Reviewer: {cycle.reviewer || '—'}
                            </p>
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
    onRetryNotification,
}: {
    details: DetailsState;
    onClose: () => void;
    onRetryNotification: (notificationId: number) => void;
}) {
    const isResident = details.type === 'resident';

    return (
        <ModalContainer>
            <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-surface shadow-xl">
                <ModalHeader
                    title={
                        isResident ? 'Resident Details' : 'Registration Details'
                    }
                    description={
                        isResident
                            ? 'Read-only summary of this resident record.'
                            : 'Read-only summary of this registration record.'
                    }
                    onClose={onClose}
                />

                <div className="max-h-[70vh] overflow-y-auto px-5 py-5">
                    {details.loading && (
                        <p className="text-sm text-ink-muted">Loading…</p>
                    )}

                    {!details.loading && details.error && (
                        <p className="text-sm text-red-600">{details.error}</p>
                    )}

                    {!details.loading && !details.error && details.resident && (
                        <ResidentDetailsBody
                            resident={details.resident}
                            onRetryNotification={onRetryNotification}
                        />
                    )}

                    {!details.loading &&
                        !details.error &&
                        details.registration && (
                            <RegistrationDetailsBody
                                registration={details.registration}
                            />
                        )}

                    {!details.loading &&
                        !details.error &&
                        !details.resident &&
                        !details.registration && (
                            <p className="text-sm text-ink-muted">
                                No details are available for this record.
                            </p>
                        )}
                </div>

                <div className="flex justify-end border-t border-line-soft px-5 py-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink-muted hover:bg-canvas"
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
    const [selectedCandidateId, setSelectedCandidateId] = useState<
        number | null
    >(null);
    const [loadingCandidates, setLoadingCandidates] = useState(false);
    const [candidateError, setCandidateError] = useState('');
    const [candidateRefresh, setCandidateRefresh] = useState(0);

    useEffect(() => {
        const query = candidateSearch.trim();
        if (query.length < 2) {
            setCandidates([]);
            setLoadingCandidates(false);
            setCandidateError(
                "Enter at least two characters to search this barangay's Admin-added Resident records.",
            );
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
                    return (await response.json()) as {
                        data?: ResidentCandidate[];
                    };
                })
                .then((payload) =>
                    setCandidates(
                        Array.isArray(payload.data) ? payload.data : [],
                    ),
                )
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                    setCandidates([]);
                    setCandidateError(
                        'Unable to load candidates. Refresh the review and try again.',
                    );
                })
                .finally(() => setLoadingCandidates(false));
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [application.id, candidateSearch, candidateRefresh]);

    const selectedCandidate = candidates.find(
        (candidate) => candidate.id === selectedCandidateId,
    );

    return (
        <ModalContainer>
            <div className="w-full max-w-3xl overflow-hidden rounded-xl bg-surface shadow-xl">
                <ModalHeader
                    title="Review Registration"
                    description="Verify the resident information before taking action"
                    onClose={onClose}
                />

                <div className="max-h-[75vh] overflow-y-auto p-6">
                    <div className="mb-5 flex flex-wrap gap-2">
                        <span className="rounded-full bg-warning-soft px-3 py-1 text-xs font-medium text-warning capitalize">
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
                        <Info label="Purok" value={application.purok || '—'} />

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

                    <section className="mt-6 rounded-xl border border-line-soft bg-brand-soft p-4">
                        <div className="mb-3">
                            <h2 className="text-sm font-semibold text-ink-strong">
                                Select the official Barangay Resident
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-ink-muted">
                                Search suggestions do not link automatically.
                                Review the official record and explicitly select
                                one. If no correct record exists, leave this
                                application pending; approval will not create a
                                Resident.
                            </p>
                            <p className="mt-2 text-xs leading-5 text-blue-800">
                                A QR reference, when valid, adds a suggestion
                                only. It does not establish identity or select a
                                candidate automatically.
                            </p>
                        </div>

                        <label
                            htmlFor="resident-candidate-search"
                            className="mb-1 block text-xs font-medium text-ink-muted"
                        >
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
                            className="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink-strong outline-none focus:border-brand focus:ring-2 focus:ring-brand-muted"
                            placeholder="Resident number or name"
                            disabled={processingApprove}
                        />

                        {loadingCandidates && (
                            <p className="mt-3 text-xs text-ink-muted">
                                Searching this barangay…
                            </p>
                        )}
                        {!loadingCandidates && candidateError && (
                            <p className="mt-3 text-xs text-red-600">
                                {candidateError}
                            </p>
                        )}
                        <div className="mt-3 flex items-center justify-between gap-3">
                            <p className="text-xs text-ink-muted">
                                Bagong Admin-added residents ay inactive muna
                                hanggang ma-link at ma-approve ang account.
                            </p>
                            <button
                                type="button"
                                disabled={
                                    loadingCandidates || processingApprove
                                }
                                onClick={() =>
                                    setCandidateRefresh((value) => value + 1)
                                }
                                className="shrink-0 rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-50"
                            >
                                {loadingCandidates
                                    ? 'Refreshing…'
                                    : 'Refresh matches'}
                            </button>
                        </div>
                        {!loadingCandidates &&
                            !candidateError &&
                            candidates.length === 0 && (
                                <p className="mt-3 rounded-md border border-line-soft bg-surface p-3 text-xs text-ink-muted">
                                    No Admin-added Resident matches this search.
                                    Add the official resident record first, then
                                    refresh the matches. The application remains
                                    pending.
                                </p>
                            )}

                        {candidates.length > 0 && (
                            <fieldset
                                className="mt-3 space-y-2"
                                disabled={processingApprove}
                            >
                                <legend className="sr-only">
                                    Official Resident candidate
                                </legend>
                                {candidates.map((candidate) => (
                                    <label
                                        key={candidate.id}
                                        className={`flex cursor-pointer items-start gap-3 rounded-lg border bg-surface p-3 ${selectedCandidateId === candidate.id ? 'border-brand ring-1 ring-brand' : 'border-line-soft'}`}
                                    >
                                        <input
                                            type="radio"
                                            name="resident-candidate"
                                            value={candidate.id}
                                            checked={
                                                selectedCandidateId ===
                                                candidate.id
                                            }
                                            onChange={() =>
                                                setSelectedCandidateId(
                                                    candidate.id,
                                                )
                                            }
                                            className="mt-1 accent-brand"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium text-ink-strong">
                                                {candidate.full_name}
                                            </span>
                                            {candidate.qr_suggested && (
                                                <span className="mt-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-800">
                                                    Suggested by QR reference —
                                                    select explicitly to
                                                    continue
                                                </span>
                                            )}
                                            <span className="mt-0.5 block text-xs text-ink-muted">
                                                {candidate.resident_number} ·
                                                Born{' '}
                                                {candidate.birthdate || '—'} ·{' '}
                                                {candidate.purok ||
                                                    'Purok not recorded'}
                                            </span>
                                            {candidate.account_exists && (
                                                <span className="mt-1 block text-xs font-medium text-amber-700">
                                                    An account is already
                                                    linked. This candidate
                                                    cannot receive another
                                                    account.
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                        )}

                        {selectedCandidate && (
                            <div className="mt-3 rounded-lg border border-line-soft bg-surface p-4">
                                <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                                    Official record selected
                                </p>
                                <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <Info
                                        label="Resident ID"
                                        value={
                                            selectedCandidate.resident_number
                                        }
                                    />
                                    <Info
                                        label="Official Name"
                                        value={selectedCandidate.full_name}
                                    />
                                    <Info
                                        label="Birthdate"
                                        value={
                                            selectedCandidate.birthdate || '—'
                                        }
                                    />
                                    <Info
                                        label="Email on record"
                                        value={selectedCandidate.email || '—'}
                                    />
                                    <Info
                                        label="Account linkage"
                                        value={
                                            selectedCandidate.account_exists
                                                ? 'Already linked'
                                                : 'No account'
                                        }
                                    />
                                    <Info
                                        label="Resident status"
                                        value={selectedCandidate.status}
                                    />
                                </div>
                            </div>
                        )}
                    </section>

                    {approveError && (
                        <p
                            role="alert"
                            className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                        >
                            {approveError}
                        </p>
                    )}

                    <div className="mt-6">
                        <div className="mb-3">
                            <p className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                                Identity Verification
                            </p>
                            <p className="mt-1 text-xs text-ink-faint">
                                Private registration documents. Only authorized
                                barangay administrators can open these files.
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

                <div className="flex flex-col-reverse gap-2 border-t border-line-soft px-6 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processingApprove}
                        className="rounded-md border border-line px-4 py-2.5 text-sm font-medium text-ink-muted hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-50"
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
                        onClick={() =>
                            selectedCandidate && onApprove(selectedCandidate.id)
                        }
                        disabled={
                            processingApprove ||
                            !selectedCandidate ||
                            selectedCandidate.account_exists
                        }
                        className="rounded-md bg-brand px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-50"
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
        (field) =>
            Object.prototype.hasOwnProperty.call(
                CORRECTION_FIELD_LABELS,
                field,
            ),
    );
    const availableDocuments = (
        application.correction_document_options ?? []
    ).filter((document) =>
        Object.prototype.hasOwnProperty.call(
            CORRECTION_DOCUMENT_LABELS,
            document,
        ),
    );
    const formErrors = form.errors as Record<string, string | undefined>;

    const toggle = (
        key: string,
        current: string[],
        setter: (value: string[]) => void,
    ) => {
        setter(
            current.includes(key)
                ? current.filter((item) => item !== key)
                : [...current, key],
        );
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
            <div className="my-auto w-full max-w-xl overflow-hidden rounded-xl bg-surface shadow-xl">
                <ModalHeader
                    title="Request Applicant Correction"
                    description={`Select only the information or documents this applicant should correct (${application.reference}).`}
                    onClose={() => {
                        if (!form.processing) onClose();
                    }}
                />
                <form onSubmit={submit} className="space-y-5 px-5 py-5">
                    <div>
                        <label
                            htmlFor="correction-instructions"
                            className="mb-1 block text-sm font-medium text-ink"
                        >
                            Instructions for applicant
                        </label>
                        <textarea
                            id="correction-instructions"
                            value={form.data.instructions}
                            onChange={(event) =>
                                form.setData('instructions', event.target.value)
                            }
                            rows={4}
                            required
                            className="w-full rounded-lg border border-line px-3 py-2 text-sm"
                        />
                        {form.errors.instructions && (
                            <p
                                role="alert"
                                className="mt-1 text-xs text-red-700"
                            >
                                {form.errors.instructions}
                            </p>
                        )}
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-semibold text-ink-strong">
                            Applicant fields
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {availableFields.map((field) => (
                                <label
                                    key={field}
                                    className="flex items-center gap-2 text-sm text-ink"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data.field_targets.includes(
                                            field,
                                        )}
                                        onChange={() =>
                                            toggle(
                                                field,
                                                form.data.field_targets,
                                                (value) =>
                                                    form.setData(
                                                        'field_targets',
                                                        value,
                                                    ),
                                            )
                                        }
                                    />
                                    {CORRECTION_FIELD_LABELS[field]}
                                </label>
                            ))}
                        </div>
                        {form.errors.field_targets && (
                            <p role="alert" className="text-xs text-red-700">
                                {form.errors.field_targets}
                            </p>
                        )}
                        {form.errors['field_targets.0'] && (
                            <p role="alert" className="text-xs text-red-700">
                                {form.errors['field_targets.0']}
                            </p>
                        )}
                    </fieldset>

                    {availableDocuments.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-semibold text-ink-strong">
                                Existing documents
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {availableDocuments.map((document) => (
                                    <label
                                        key={document}
                                        className="flex items-center gap-2 text-sm text-ink"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.document_targets.includes(
                                                document,
                                            )}
                                            onChange={() =>
                                                toggle(
                                                    document,
                                                    form.data.document_targets,
                                                    (value) =>
                                                        form.setData(
                                                            'document_targets',
                                                            value,
                                                        ),
                                                )
                                            }
                                        />
                                        {CORRECTION_DOCUMENT_LABELS[document]}
                                    </label>
                                ))}
                            </div>
                            {form.errors.document_targets && (
                                <p
                                    role="alert"
                                    className="text-xs text-red-700"
                                >
                                    {form.errors.document_targets}
                                </p>
                            )}
                            {form.errors['document_targets.0'] && (
                                <p
                                    role="alert"
                                    className="text-xs text-red-700"
                                >
                                    {form.errors['document_targets.0']}
                                </p>
                            )}
                        </fieldset>
                    )}

                    {formErrors.correction && (
                        <p role="alert" className="text-sm text-red-700">
                            {formErrors.correction}
                        </p>
                    )}
                    <p className="text-xs leading-5 text-ink-muted">
                        Sectors, Resident identity, account details, QR
                        credentials, and application status cannot be changed
                        through this request.
                    </p>
                    <div className="flex justify-end gap-2 border-t border-line-soft pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={form.processing}
                            className="rounded-md border border-line px-4 py-2 text-sm text-ink disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Send correction request'}
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
    const [failedImages, setFailedImages] = useState<Record<number, boolean>>(
        {},
    );
    if (documents.length === 0) {
        return (
            <div className="rounded-lg border border-line-soft bg-canvas p-4">
                <p className="text-sm font-medium text-ink-muted">
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
                    className="overflow-hidden rounded-lg border border-line-soft bg-surface"
                >
                    <div className="border-b border-line-soft bg-canvas px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-ink-strong">
                                    {document.label}
                                </p>
                                <p className="mt-0.5 text-[11px] text-ink-faint">
                                    {document.mime_type || 'Document'}
                                    {document.file_size
                                        ? ` • ${formatFileSize(document.file_size)}`
                                        : ''}
                                </p>
                                {document.is_superseded && (
                                    <p className="mt-1 text-[11px] font-semibold text-amber-800">
                                        Superseded
                                        {document.superseded_at
                                            ? ` on ${document.superseded_at}`
                                            : ''}
                                    </p>
                                )}
                            </div>

                            <span className="rounded-full bg-warning-soft px-2.5 py-1 text-[10px] font-medium text-warning capitalize">
                                {document.verification_status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>

                    {document.is_image && failedImages[document.id] ? (
                        <div
                            role="alert"
                            className="flex h-56 items-center justify-center bg-amber-50 p-4 text-center text-sm text-amber-900"
                        >
                            Hindi ma-load ang image. Buksan ang full image para
                            subukan ulit. Kung unavailable pa rin, mag-request
                            ng correction para ma-upload muli ang file.
                        </div>
                    ) : document.is_image ? (
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="block bg-canvas"
                            title={`Open ${document.label}`}
                        >
                            <img
                                src={document.url}
                                alt={document.label}
                                loading="lazy"
                                decoding="async"
                                onError={() =>
                                    setFailedImages((current) => ({
                                        ...current,
                                        [document.id]: true,
                                    }))
                                }
                                className="h-56 w-full object-contain p-3"
                            />
                        </a>
                    ) : (
                        <div className="flex h-40 items-center justify-center bg-canvas p-4 text-center">
                            <div>
                                <p className="text-sm font-medium text-ink-muted">
                                    Document preview
                                </p>
                                <p className="mt-1 text-xs text-ink-faint">
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
                            className="rounded-md border border-brand-muted px-3 py-2 text-xs font-medium text-brand transition hover:bg-brand-soft"
                        >
                            {document.is_image
                                ? 'View Full Image'
                                : 'Open Document'}
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
    emailSafeReason,
    setEmailSafeReason,
    error,
    processing,
    onCancel,
    onSubmit,
}: {
    application: PendingRegistration;
    reason: string;
    setReason: (value: string) => void;
    emailSafeReason: string;
    setEmailSafeReason: (value: string) => void;
    error: string;
    processing: boolean;
    onCancel: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <ModalContainer>
            <div className="w-full max-w-md overflow-hidden rounded-xl bg-surface shadow-xl">
                <ModalHeader
                    title="Reject Registration"
                    description={getApplicantName(application)}
                    onClose={onCancel}
                />

                <form onSubmit={onSubmit}>
                    <div className="p-5">
                        <div className="mb-4 rounded-lg border border-red-100 bg-red-50 p-3">
                            <p className="text-xs leading-5 text-red-700">
                                The internal review note is required and visible
                                to authorized staff. The separate
                                resident-facing explanation is optional and is
                                the only reason text included in the email.
                            </p>
                        </div>

                        <label className="mb-1.5 block text-xs font-medium text-ink">
                            Internal review note
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
                            <p className="shrink-0 text-[11px] text-ink-faint">
                                {reason.length}/1000
                            </p>
                        </div>

                        <label className="mt-4 mb-1.5 block text-xs font-medium text-ink">
                            Resident-facing explanation (optional)
                        </label>
                        <textarea
                            value={emailSafeReason}
                            onChange={(event) =>
                                setEmailSafeReason(event.target.value)
                            }
                            rows={3}
                            maxLength={1000}
                            placeholder="Write only the explanation appropriate to send to the applicant."
                            className={[inputClass, 'resize-none'].join(' ')}
                        />
                        <p className="mt-1 text-[11px] text-ink-muted">
                            If omitted, the rejection decision is still saved,
                            but no rejection email is sent.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-line-soft px-5 py-4">
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={processing}
                            className="rounded-md border border-line px-4 py-2.5 text-sm font-medium text-ink-muted hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-50"
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
    'w-full rounded-md border border-line bg-surface px-3 py-2.5 text-sm text-ink-strong outline-none transition placeholder:text-ink-faint focus:border-brand focus:ring-1 focus:ring-brand';

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
                    ? 'border-brand-muted bg-brand-soft'
                    : 'border-line-soft bg-surface'
            }`}
        >
            <p
                className={`text-xs font-medium tracking-wide uppercase ${
                    highlight ? 'text-brand' : 'text-ink-faint'
                }`}
            >
                {title}
            </p>
            <p className="mt-3 text-3xl font-semibold text-ink-strong">{value}</p>
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
            className={`border-b-2 px-4 py-3 text-sm font-medium whitespace-nowrap ${
                active
                    ? 'border-brand text-brand'
                    : 'border-transparent text-ink-muted hover:text-ink'
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
            <p className="mb-2 text-xs font-semibold tracking-wide text-ink-faint uppercase">
                Filter by Purok
            </p>

            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => onSelect(null)}
                    className={`rounded-full border px-3.5 py-1.5 text-xs font-medium transition ${
                        selected === null
                            ? 'border-brand bg-brand text-white'
                            : 'border-line bg-surface text-ink-muted hover:bg-canvas'
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
                                ? 'border-brand bg-brand text-white'
                                : 'border-line bg-surface text-ink-muted hover:bg-canvas'
                        }`}
                    >
                        {purok.name} ({purok.resident_count} residents,{' '}
                        {purok.household_count} households)
                    </button>
                ))}

                {puroks.length === 0 && (
                    <span className="text-xs text-ink-faint">
                        No puroks are configured for this barangay.
                    </span>
                )}
            </div>
        </div>
    );
}

function TableHead({ children }: { children: ReactNode }) {
    return (
        <th className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-ink-faint uppercase">
            {children}
        </th>
    );
}

function SectorBadges({ sectors }: { sectors: Sector[] }) {
    const safeSectors = Array.isArray(sectors) ? sectors : [];

    if (safeSectors.length === 0) {
        return <span className="text-xs text-ink-faint">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {safeSectors.map((sector) => {
                if (sector.slug === 'senior') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-medium text-brand"
                        >
                            Senior
                        </span>
                    );
                }

                if (sector.slug === 'pwd') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-info-soft px-2.5 py-1 text-xs font-medium text-info"
                        >
                            PWD
                        </span>
                    );
                }

                return (
                    <span
                        key={sector.id}
                        className="rounded-full bg-secondary px-2.5 py-1 text-xs font-medium text-ink-muted"
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
            <p className="text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm font-medium break-words text-ink-strong">
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
        <div className="flex items-center justify-between border-b border-line-soft px-5 py-4">
            <div>
                <h2 className="text-lg font-semibold text-ink-strong">{title}</h2>
                <p className="mt-1 text-xs text-ink-muted">{description}</p>
            </div>

            <button
                type="button"
                onClick={onClose}
                className="flex h-8 w-8 items-center justify-center rounded-full text-ink-muted hover:bg-secondary"
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
