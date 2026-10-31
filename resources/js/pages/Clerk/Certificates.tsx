import { Head, router, useForm } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';
import CertificateDocumentPreview from '@/components/certificates/CertificateDocumentPreview';
import { printCertificateDocument } from '@/lib/certificateDocument';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    staff_id: string;
    department: string;
}

interface CertificateTypeOption {
    value: string;
    available: boolean;
}

interface SignatoryOption {
    name: string;
    title: string;
}

const signatoryOptionValue = (name: string, title: string) =>
    JSON.stringify([name, title]);

interface RequestRow {
    id: number;
    reference: string;
    certificate: string;
    resident: {
        name: string;
        resident_number: string | null;
    };
    status: string;
    submitted_at: string;
}

interface RequestDetailRow {
    label: string;
    value: string;
}

interface RequestDetailField {
    key: string;
    label: string;
    type: 'text' | 'number';
    kind: 'text' | 'integer' | 'numeric';
    required: boolean;
    min?: number;
    max?: number;
    default?: string;
}

interface RequestDetails {
    id: number;
    reference: string;
    certificate: string;
    certificate_type: string | null;
    resident: {
        id?: number;
        name: string;
        resident_number: string | null;
        purok?: string;
    };
    purpose: string;
    fee: string;
    business_name: string | null;
    business_address: string | null;
    notes: string | null;
    original_notes: string | null;
    details: RequestDetailRow[];
    original_details: RequestDetailRow[];
    details_fields: RequestDetailField[];
    detail_values: Record<string, string>;
    status: string;
    admin_note: string | null;
    template_available: boolean;
    allowed_statuses: string[];
    submitted_at: string;
    reviewed_at: string | null;
    issuance?: {
        id: number;
        reference: string;
        issued_at: string;
    } | null;
}

function requestErrorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;

        if (errors && typeof errors === 'object') {
            const first = Object.values(errors)[0];

            if (Array.isArray(first) && typeof first[0] === 'string') {
                return first[0];
            }
        }

        const message = error.response?.data?.message;

        if (typeof message === 'string' && message.trim() !== '') {
            return message;
        }
    }

    return fallback;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface CertificateOption {
    id: number;
    name: string;
}

interface IssuanceRow {
    id: number;
    reference: string;
    certificate_type: string;
    resident: {
        name: string;
        resident_number: string | null;
    };
    purok: string | null;
    issued_at: string;
    issued_by: string;
}

type AllRecordType = 'request' | 'issuance';

interface AllRecordRow {
    record_type: AllRecordType;
    id: number;
    reference: string;
    certificate: string;
    resident: {
        name: string;
        resident_number: string | null;
    };
    purok: string | null;
    status: string | null;
    occurred_at: string;
}

interface RequestFilters {
    search: string;
    status: string;
    certificate_id: string;
    date_from: string;
    date_to: string;
    per_page: number;
}

interface IssuanceFilters {
    search: string;
    type: string;
    date_from: string;
    date_to: string;
    per_page: number;
}

interface AllRecordFilters {
    search: string;
    type: string;
    date_from: string;
    date_to: string;
    per_page: number;
}

const EMPTY_REQUESTS: Paginated<RequestRow> = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
};

const EMPTY_FILTERS: RequestFilters = {
    search: '',
    status: '',
    certificate_id: '',
    date_from: '',
    date_to: '',
    per_page: 10,
};

const EMPTY_ISSUANCES: Paginated<IssuanceRow> = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
};

const EMPTY_ISSUED_FILTERS: IssuanceFilters = {
    search: '',
    type: '',
    date_from: '',
    date_to: '',
    per_page: 10,
};

const EMPTY_ALL_RECORDS: Paginated<AllRecordRow> = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
    prev_page_url: null,
    next_page_url: null,
};

const EMPTY_ALL_FILTERS: AllRecordFilters = {
    search: '',
    type: '',
    date_from: '',
    date_to: '',
    per_page: 10,
};

const STATUS_STYLES: Record<string, string> = {
    Pending: 'bg-amber-100 text-amber-800',
    'Needs Action': 'bg-orange-100 text-orange-800',
    'For Payment': 'bg-blue-100 text-blue-800',
    Processing: 'bg-indigo-100 text-indigo-800',
    'Ready for Claim': 'bg-teal-100 text-teal-800',
    Released: 'bg-green-100 text-green-800',
    Rejected: 'bg-red-100 text-red-800',
};

type CertificateTab = 'requests' | 'issued' | 'all';

const CERTIFICATE_FEES: Record<string, string> = {
    Residency: '100',
    Indigency: '50',
    'Delayed PSA Registration': '50',
    'Marriage Cohabitation': '100',
    'Scholarship Certificate': '50',
    Tricycle: '300',
    'Sari-sari Store': '100',
};

const DELAYED_PSA_TYPE = 'Delayed PSA Registration';

// The Delayed PSA Registration template states its own purpose and carries
// its own Punong Barangay / Officer on Duty signature blocks, so the generic
// purpose and signatory inputs are not shown for this certificate type.
const DELAYED_PSA_PURPOSE = 'DELAYED REGISTRATION OF BIRTH';

// The Marriage Cohabitation template states its own purpose and carries its
// own Punong Barangay / Officer on Duty signature blocks as well, so the
// generic purpose and signatory inputs are not shown for it either.
const MARRIAGE_TYPE = 'Marriage Cohabitation';

const MARRIAGE_PURPOSE = 'Marriage Purposes';

const MARRIAGE_PARTNER_AGE_MIN = 1;
const MARRIAGE_PARTNER_AGE_MAX = 120;
const MARRIAGE_YEARS_MIN = 0;
const MARRIAGE_YEARS_MAX = 120;

// The Residency certificate template states its own purpose
// ("PROOF OF RESIDENCY."), carries its own Punong Barangay / Officer on Duty
// signature blocks, and prints no control number, so the generic purpose,
// signatory, and control number inputs are not shown for it.
const RESIDENCY_TYPE = 'Residency';

const RESIDENCY_PURPOSE = 'PROOF OF RESIDENCY.';

const RESIDENCY_YEARS_MIN = 0;
const RESIDENCY_YEARS_MAX = 120;

function createControlNumber(): string {
    return `CTRL-${new Date().getFullYear()}-${Math.floor(1000 + Math.random() * 9000)}`;
}

const DELAYED_PSA_DEFAULTS = {
    nationality: 'FILIPINO',
    birth_place: 'PUERTO PRINCESA CITY',
};

const SEX_OPTIONS = ['MALE', 'FEMALE'];

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

function ordinalSuffix(day: number): string {
    const remainder = day % 100;

    if (remainder >= 11 && remainder <= 13) {
        return 'th';
    }

    switch (day % 10) {
        case 1:
            return 'st';
        case 2:
            return 'nd';
        case 3:
            return 'rd';
        default:
            return 'th';
    }
}

// Mirrors the server-side "Nth day of Month YYYY" long form that the
// certificate uses for both the date of birth and its own issue date.
function formatLongDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

    if (!match) {
        return '';
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);

    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return '';
    }

    const monthName = MONTH_NAMES[month - 1];

    if (!monthName) {
        return '';
    }

    return `${day}${ordinalSuffix(day)} day of ${monthName} ${year}`;
}

function formatIssueDate(): string {
    const today = new Date();
    const day = today.getDate();

    return `${day}${ordinalSuffix(day)} day of ${MONTH_NAMES[today.getMonth()]} ${today.getFullYear()}`;
}

function normalizeSexValue(value: string | null | undefined): string {
    const normalized = (value ?? '').trim().toUpperCase();

    return SEX_OPTIONS.includes(normalized) ? normalized : '';
}

/*
|--------------------------------------------------------------------------
| Marriage partner inputs
|--------------------------------------------------------------------------
|
| The partner details are whole numbers inside a fixed range. A fractional,
| negative, or malformed value is rejected here for immediate feedback; the
| server validates the same values and stays authoritative.
|
*/

function parseWholeNumber(value: string): number | null {
    const trimmed = value.trim();

    if (!/^\d+$/.test(trimmed)) {
        return null;
    }

    const parsed = Number(trimmed);

    return Number.isSafeInteger(parsed) ? parsed : null;
}

function calculateAge(birthdate: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthdate.trim());

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);

    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return null;
    }

    const today = new Date();
    let age = today.getFullYear() - year;
    const monthDifference = today.getMonth() + 1 - month;

    if (
        monthDifference < 0 ||
        (monthDifference === 0 && today.getDate() < day)
    ) {
        age -= 1;
    }

    return age >= 0 ? age : null;
}

function QrScanInput({
    onScanned,
    onEmpty,
    loading,
    focusSignal,
}: {
    onScanned: (code: string) => void;
    onEmpty: () => void;
    loading: boolean;
    focusSignal: number;
}) {
    const [code, setCode] = useState('');
    const inputRef = useRef<HTMLInputElement | null>(null);

    /*
    |--------------------------------------------------------------------------
    | Scanner focus
    |--------------------------------------------------------------------------
    |
    | A handheld 2D scanner types into whatever has focus, so the scan field
    | has to hold the focus again after every scan attempt. The signal is a
    | counter that only the QR workflow advances, so repeated identical
    | results still restore the focus and ordinary renders never steal it.
    | preventScroll keeps the page from jumping to the field.
    |
    */

    useEffect(() => {
        inputRef.current?.focus({ preventScroll: true });
    }, [focusSignal]);

    const submit = () => {
        if (loading) {
            return;
        }

        const trimmed = code.trim();

        if (trimmed === '') {
            onEmpty();

            return;
        }

        setCode('');
        onScanned(trimmed);
    };

    return (
        <div className="mx-auto flex max-w-sm flex-col gap-2 sm:flex-row">
            <input
                ref={inputRef}
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        submit();
                    }
                }}
                onPaste={(e) => {
                    e.preventDefault();
                }}
                placeholder="Scan QR code here…"
                aria-label="Scan resident QR code"
                className="w-full flex-1 rounded-md border px-3 py-2 text-sm"
                autoFocus
                autoComplete="off"
            />
            <button
                type="button"
                onClick={submit}
                disabled={loading}
                className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm text-white disabled:opacity-50"
            >
                {loading ? 'Looking up...' : 'Look up resident'}
            </button>
        </div>
    );
}

export default withDeferredPage(Dashboard, ['requests', 'issuances', 'allRecords', 'certificateOptions', 'signatories', 'issuedFilters', 'allFilters'], (props) => (
    <ClerkLayout active="certificates" user={props.auth.user}><PortalPageSkeleton showTabs /></ClerkLayout>
));

function Dashboard({
    auth,
    certificateTypes = [],
    signatories = [],
    requests = EMPTY_REQUESTS,
    issuances = EMPTY_ISSUANCES,
    statuses = [],
    certificateOptions = [],
    filters = EMPTY_FILTERS,
    issuedFilters = EMPTY_ISSUED_FILTERS,
    allRecords = EMPTY_ALL_RECORDS,
    allFilters = EMPTY_ALL_FILTERS,
}: {
    auth: { user: User };
    certificateTypes?: CertificateTypeOption[];
    signatories?: SignatoryOption[];
    requests?: Paginated<RequestRow>;
    issuances?: Paginated<IssuanceRow>;
    allRecords?: Paginated<AllRecordRow>;
    statuses?: string[];
    certificateOptions?: CertificateOption[];
    filters?: RequestFilters;
    issuedFilters?: IssuanceFilters;
    allFilters?: AllRecordFilters;
}) {
    const [activeTab, setActiveTab] = useState<CertificateTab>(() =>
        typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab') === 'issued' ? 'issued' : 'requests',
    );
    const [searchQuery, setSearchQuery] = useState(filters.search);
    const [statusQuery, setStatusQuery] = useState(filters.status);
    const [certificateQuery, setCertificateQuery] = useState(
        filters.certificate_id,
    );
    const [dateFromQuery, setDateFromQuery] = useState(filters.date_from);
    const [dateToQuery, setDateToQuery] = useState(filters.date_to);
    const [issuedSearchQuery, setIssuedSearchQuery] = useState(
        issuedFilters.search,
    );
    const [issuedTypeQuery, setIssuedTypeQuery] = useState(issuedFilters.type);
    const [issuedDateFromQuery, setIssuedDateFromQuery] = useState(
        issuedFilters.date_from,
    );
    const [issuedDateToQuery, setIssuedDateToQuery] = useState(
        issuedFilters.date_to,
    );
    const [allSearchQuery, setAllSearchQuery] = useState(allFilters.search);
    const [allTypeQuery, setAllTypeQuery] = useState(allFilters.type);
    const [allDateFromQuery, setAllDateFromQuery] = useState(
        allFilters.date_from,
    );
    const [allDateToQuery, setAllDateToQuery] = useState(allFilters.date_to);
    const [listLoading, setListLoading] = useState(false);
    const [popoverOpen, setPopoverOpen] = useState(false);
    const lastQueried = useRef(filters.search.trim());
    const lastIssuedQueried = useRef(issuedFilters.search.trim());
    const lastAllQueried = useRef(allFilters.search.trim());
    const popoverRef = useRef<HTMLDivElement | null>(null);
    const popoverButtonRef = useRef<HTMLButtonElement | null>(null);

    useEffect(() => setListLoading(false), [requests, issuances, allRecords]);

    // Close the filter popover on outside click or Escape.
    useEffect(() => {
        if (!popoverOpen) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;
            if (
                popoverRef.current &&
                !popoverRef.current.contains(target) &&
                popoverButtonRef.current &&
                !popoverButtonRef.current.contains(target)
            ) {
                setPopoverOpen(false);
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setPopoverOpen(false);
                popoverButtonRef.current?.focus();
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [popoverOpen]);

    const reload = (params: Record<string, string | number>) => {
        const query: Record<string, string | number> = {
            search: searchQuery,
            status: statusQuery,
            certificate_id: certificateQuery,
            date_from: dateFromQuery,
            date_to: dateToQuery,
            per_page: filters.per_page,
            ...params,
        };

        Object.keys(query).forEach((key) => {
            if (query[key] === '' || query[key] === null) {
                delete query[key];
            }
        });

        setListLoading(true);
        router.get('/clerk/certificates', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['requests', 'filters'],
        });
    };

    const reloadIssued = (params: Record<string, string | number>) => {
        const query: Record<string, string | number> = {
            issued_search: issuedSearchQuery,
            issued_type: issuedTypeQuery,
            issued_date_from: issuedDateFromQuery,
            issued_date_to: issuedDateToQuery,
            issued_per_page: issuedFilters.per_page,
            ...params,
        };

        Object.keys(query).forEach((key) => {
            if (query[key] === '' || query[key] === null) {
                delete query[key];
            }
        });

        setListLoading(true);
        router.get('/clerk/certificates', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['issuances', 'issuedFilters'],
        });
    };

    const reloadAll = (params: Record<string, string | number>) => {
        const query: Record<string, string | number> = {
            all_search: allSearchQuery,
            all_type: allTypeQuery,
            all_date_from: allDateFromQuery,
            all_date_to: allDateToQuery,
            all_per_page: allFilters.per_page,
            ...params,
        };

        Object.keys(query).forEach((key) => {
            if (query[key] === '' || query[key] === null) {
                delete query[key];
            }
        });

        setListLoading(true);
        router.get('/clerk/certificates', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['allRecords', 'allFilters'],
        });
    };

    const applySearch = () => {
        if (activeTab === 'issued') {
            const trimmed = issuedSearchQuery.trim();
            if (
                trimmed === lastIssuedQueried.current &&
                trimmed === issuedFilters.search
            ) {
                return;
            }
            lastIssuedQueried.current = trimmed;
            reloadIssued({ issued_search: trimmed, issued_page: 1 });
            return;
        }

        if (activeTab === 'all') {
            const trimmed = allSearchQuery.trim();
            if (
                trimmed === lastAllQueried.current &&
                trimmed === allFilters.search
            ) {
                return;
            }
            lastAllQueried.current = trimmed;
            reloadAll({ all_search: trimmed, all_page: 1 });
            return;
        }

        const trimmed = searchQuery.trim();
        if (trimmed === lastQueried.current && trimmed === filters.search) {
            return;
        }
        lastQueried.current = trimmed;
        reload({ search: trimmed, page: 1 });
    };

    const applyFilters = () => {
        if (activeTab === 'issued') {
            reloadIssued({
                issued_type: issuedTypeQuery,
                issued_date_from: issuedDateFromQuery,
                issued_date_to: issuedDateToQuery,
                issued_page: 1,
            });
            setPopoverOpen(false);
            return;
        }

        if (activeTab === 'all') {
            reloadAll({
                all_type: allTypeQuery,
                all_date_from: allDateFromQuery,
                all_date_to: allDateToQuery,
                all_page: 1,
            });
            setPopoverOpen(false);
            return;
        }

        reload({
            status: statusQuery,
            certificate_id: certificateQuery,
            date_from: dateFromQuery,
            date_to: dateToQuery,
            page: 1,
        });
        setPopoverOpen(false);
    };

    const clearFilters = () => {
        if (activeTab === 'issued') {
            setIssuedTypeQuery('');
            setIssuedDateFromQuery('');
            setIssuedDateToQuery('');
            reloadIssued({
                issued_type: '',
                issued_date_from: '',
                issued_date_to: '',
                issued_page: 1,
            });
            setPopoverOpen(false);
            return;
        }

        if (activeTab === 'all') {
            setAllTypeQuery('');
            setAllDateFromQuery('');
            setAllDateToQuery('');
            reloadAll({
                all_type: '',
                all_date_from: '',
                all_date_to: '',
                all_page: 1,
            });
            setPopoverOpen(false);
            return;
        }

        setStatusQuery('');
        setCertificateQuery('');
        setDateFromQuery('');
        setDateToQuery('');
        reload({
            status: '',
            certificate_id: '',
            date_from: '',
            date_to: '',
            page: 1,
        });
        setPopoverOpen(false);
    };

    const changePerPage = (value: number) => {
        if (activeTab === 'issued') {
            reloadIssued({ issued_per_page: value, issued_page: 1 });
            return;
        }
        if (activeTab === 'all') {
            reloadAll({ all_per_page: value, all_page: 1 });
            return;
        }
        reload({ per_page: value, page: 1 });
    };

    const goToPage = (url: string | null) => {
        if (!url) {
            return;
        }
        setListLoading(true);
        router.get(
            url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only:
                    activeTab === 'issued'
                        ? ['issuances', 'issuedFilters']
                        : activeTab === 'all'
                          ? ['allRecords', 'allFilters']
                          : ['requests', 'filters'],
            },
        );
    };

    const activeFilterCount =
        activeTab === 'issued'
            ? [
                  issuedTypeQuery !== '',
                  issuedDateFromQuery !== '',
                  issuedDateToQuery !== '',
              ].filter(Boolean).length
            : activeTab === 'all'
              ? [
                    allTypeQuery !== '',
                    allDateFromQuery !== '',
                    allDateToQuery !== '',
                ].filter(Boolean).length
              : [
                    statusQuery !== '',
                    certificateQuery !== '',
                    dateFromQuery !== '',
                    dateToQuery !== '',
                ].filter(Boolean).length;

    const hasFilters =
        activeTab === 'issued'
            ? issuedSearchQuery.trim() !== '' || activeFilterCount > 0
            : activeTab === 'all'
              ? allSearchQuery.trim() !== '' || activeFilterCount > 0
              : searchQuery.trim() !== '' || activeFilterCount > 0;

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [requestDetailsOpen, setRequestDetailsOpen] = useState(false);
    const [requestDetails, setRequestDetails] = useState<RequestDetails | null>(
        null,
    );
    const [requestDetailsLoading, setRequestDetailsLoading] = useState(false);
    const [requestDetailsError, setRequestDetailsError] = useState<
        string | null
    >(null);
    const [detailDraft, setDetailDraft] = useState<Record<string, string>>({});
    const [statusDraft, setStatusDraft] = useState('');
    const [adminNoteDraft, setAdminNoteDraft] = useState('');
    const [savingDetails, setSavingDetails] = useState(false);
    const [savingStatus, setSavingStatus] = useState(false);
    const [requestActionError, setRequestActionError] = useState<string | null>(
        null,
    );
    const [requestActionSuccess, setRequestActionSuccess] = useState<
        string | null
    >(null);
    const [actionLoading, setActionLoading] = useState(false);
    const [showReleaseConfirm, setShowReleaseConfirm] = useState(false);
    const [modalMode, setModalMode] = useState<'manual' | 'qr'>('qr');
    const [scanLoading, setScanLoading] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);
    const [residentSource, setResidentSource] = useState<
        'none' | 'qr' | 'manual'
    >('none');
    const [lastScannedToken, setLastScannedToken] = useState<string | null>(
        null,
    );
    const [formStep, setFormStep] = useState<1 | 2>(1);
    const [generationLoading, setGenerationLoading] = useState(false);
    const [generationError, setGenerationError] = useState<string | null>(null);
    const [generationOutcomeUnknown, setGenerationOutcomeUnknown] = useState(false);
    const generationInFlightRef = useRef(false);
    const generationOutcomeUnknownRef = useRef(false);
    // Advances only on QR workflow events, so the scan field can be focused
    // again after a scan without refocusing on ordinary renders.
    const [qrFocusSignal, setQrFocusSignal] = useState(0);

    const openRequestDetails = async (id: number) => {
        setRequestDetailsOpen(true);
        setRequestDetails(null);
        setRequestDetailsError(null);
        setRequestDetailsLoading(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.get<RequestDetails>(
                `/clerk/certificates/requests/${id}`,
            );
            setRequestDetails(response.data);
            setDetailDraft(response.data.detail_values);
            setStatusDraft(response.data.status);
            setAdminNoteDraft(response.data.admin_note ?? '');
        } catch {
            setRequestDetailsError('Unable to load request details right now.');
        } finally {
            setRequestDetailsLoading(false);
        }
    };

    const closeRequestDetails = () => {
        setRequestDetailsOpen(false);
        setRequestDetails(null);
        setRequestDetailsError(null);
        setRequestDetailsLoading(false);
        setDetailDraft({});
        setStatusDraft('');
        setAdminNoteDraft('');
        setRequestActionError(null);
        setRequestActionSuccess(null);
    };

    const saveRequestDetails = async () => {
        if (!requestDetails || savingDetails) return;

        setSavingDetails(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.patch<{
                message: string;
                details: RequestDetailRow[];
                original_details: RequestDetailRow[];
                detail_values: Record<string, string>;
                notes: string | null;
                original_notes: string | null;
            }>(
                `/clerk/certificates/requests/${requestDetails.id}`,
                { details: detailDraft },
            );

            setRequestDetails((current) =>
                current
                    ? {
                          ...current,
                          details: response.data.details,
                          original_details: response.data.original_details,
                          detail_values: response.data.detail_values,
                          notes: response.data.notes,
                          original_notes: response.data.original_notes,
                      }
                    : current,
            );
            setDetailDraft(response.data.detail_values);
            setRequestActionSuccess(response.data.message);
        } catch (error) {
            setRequestActionError(
                requestErrorMessage(
                    error,
                    'Unable to save the reviewed details right now.',
                ),
            );
        } finally {
            setSavingDetails(false);
        }
    };

    const saveRequestStatus = async () => {
        if (!requestDetails || savingStatus || statusDraft === '') return;

        setSavingStatus(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.patch<{
                message: string;
                status: string;
                allowed_statuses: string[];
                admin_note: string | null;
            }>(
                `/clerk/certificates/requests/${requestDetails.id}/status`,
                {
                    status: statusDraft,
                    admin_note: adminNoteDraft.trim() || null,
                },
            );

            setRequestDetails((current) =>
                current
                    ? {
                          ...current,
                          status: response.data.status,
                          allowed_statuses: response.data.allowed_statuses,
                      }
                    : current,
            );
            setStatusDraft(response.data.status);
            setAdminNoteDraft(response.data.admin_note ?? '');
            setRequestActionSuccess(response.data.message);
            router.reload({ only: ['requests'] });
        } catch (error) {
            setRequestActionError(
                requestErrorMessage(
                    error,
                    'Unable to update the request status right now.',
                ),
            );
        } finally {
            setSavingStatus(false);
        }
    };

    const requestDetailsWereRevised =
        requestDetails !== null &&
        JSON.stringify(requestDetails.original_details) !==
            JSON.stringify(requestDetails.details);

    const defaultCertificateType =
        certificateTypes.find((type) => type.available)?.value ?? '';
    const defaultFee = CERTIFICATE_FEES[defaultCertificateType] ?? '0';

    const { data, setData, processing, reset } = useForm({
        certificate_request_id: '',
        control_no: createControlNumber(),
        resident_id: '',
        resident_name: '',
        purok: '',
        certificate_type: defaultCertificateType,
        fee: defaultFee,
        purpose: '',
        signatory_name: '',
        signatory_title: '',
        franchise_no: '',
        business_name: '',
        business_address: '',
        school_institution: '',
        father_name: '',
        father_occupation: '',
        father_monthly_income: '',
        mother_name: '',
        mother_occupation: '',
        mother_monthly_income: '',
        date_of_birth: '',
        sex: '',
        nationality: DELAYED_PSA_DEFAULTS.nationality,
        birth_place: DELAYED_PSA_DEFAULTS.birth_place,
        mother_maiden_name: '',
        parents_marriage_details: '',
        residency_start_year: '',
        partner_full_name: '',
        partner_age: '',
        years_living_together: '',
        years_of_residency: '',
    });

    // The Delayed PSA detail review is a separate confirmation so that it is
    // never carried over from another certificate type, and so that any edit
    // to the reviewed details invalidates the confirmation.
    const [detailsConfirmed, setDetailsConfirmed] = useState(false);
    const [profileLoading, setProfileLoading] = useState(false);
    const [profileError, setProfileError] = useState<string | null>(null);
    const [documentPreviewReady, setDocumentPreviewReady] = useState(false);

    const isDelayedPsa = data.certificate_type === DELAYED_PSA_TYPE;
    const isMarriage = data.certificate_type === MARRIAGE_TYPE;
    const isResidency = data.certificate_type === RESIDENCY_TYPE;

    // Editing any reviewed detail invalidates the Clerk's confirmation.
    useEffect(() => {
        setDetailsConfirmed(false);
    }, [
        data.date_of_birth,
        data.sex,
        data.nationality,
        data.birth_place,
        data.purok,
        data.father_name,
        data.mother_maiden_name,
        data.parents_marriage_details,
        data.residency_start_year,
    ]);

    /**
     * Load the certificate-relevant values of a linked resident.
     *
     * The resident profile is authoritative for the date of birth, sex, and
     * purok; anything the profile does not carry is left for the Clerk to
     * enter manually. A failed lookup is reported instead of silently
     * ignored, because the server still requires the complete detail set.
     */
    const applyResidentProfile = async (residentId: string) => {
        if (!residentId) {
            return;
        }

        setProfileLoading(true);
        setProfileError(null);

        try {
            const response = await axios.get(
                `/clerk/certificates/resident-details/${residentId}`,
            );
            const profile = response.data?.resident;

            if (!profile) {
                setProfileError(
                    'The resident record could not be loaded. Please enter the birth date, sex, and purok manually.',
                );
                return;
            }

            setData((current) => ({
                ...current,
                date_of_birth: profile.birthdate || current.date_of_birth,
                sex: normalizeSexValue(profile.sex) || current.sex,
                purok: profile.purok || current.purok,
            }));
        } catch {
            setProfileError(
                'The resident record could not be loaded. Please enter the birth date, sex, and purok manually.',
            );
        } finally {
            setProfileLoading(false);
        }
    };

    const handleStartProcessing = async () => {
        if (!requestDetails || actionLoading) return;
        setActionLoading(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.patch<{
                message: string;
                status: string;
                allowed_statuses: string[];
                admin_note: string | null;
            }>(`/clerk/certificates/requests/${requestDetails.id}/status`, {
                status: 'Processing',
                admin_note: adminNoteDraft.trim() || null,
            });

            setRequestDetails((current) =>
                current
                    ? {
                          ...current,
                          status: response.data.status,
                          allowed_statuses: response.data.allowed_statuses,
                      }
                    : current,
            );
            setStatusDraft(response.data.status);
            setRequestActionSuccess('Request is now in processing.');
            router.reload({ only: ['requests'] });
        } catch (error) {
            setRequestActionError(
                requestErrorMessage(error, 'Unable to start processing.'),
            );
        } finally {
            setActionLoading(false);
        }
    };

    const handleInitiateIssuance = (req: RequestDetails) => {
        const certType = req.certificate_type || defaultCertificateType;
        const fee = req.fee || CERTIFICATE_FEES[certType] || '0';

        setData((current) => ({
            ...current,
            certificate_request_id: String(req.id),
            certificate_type: certType,
            resident_id: req.resident.id ? String(req.resident.id) : '',
            resident_name: req.resident.name,
            purok: req.resident.purok || current.purok,
            fee: fee,
            purpose: req.purpose || current.purpose,
            business_name: req.business_name || current.business_name,
            business_address: req.business_address || current.business_address,
            franchise_no: req.detail_values?.franchise_no || current.franchise_no,
            school_institution: req.detail_values?.school_institution || current.school_institution,
            father_name: req.detail_values?.father_name || current.father_name,
            father_occupation: req.detail_values?.father_occupation || current.father_occupation,
            father_monthly_income: req.detail_values?.father_monthly_income || current.father_monthly_income,
            mother_name: req.detail_values?.mother_name || current.mother_name,
            mother_occupation: req.detail_values?.mother_occupation || current.mother_occupation,
            mother_monthly_income: req.detail_values?.mother_monthly_income || current.mother_monthly_income,
            date_of_birth: req.detail_values?.date_of_birth || current.date_of_birth,
            sex: req.detail_values?.sex || current.sex,
            nationality: req.detail_values?.nationality || current.nationality,
            birth_place: req.detail_values?.birth_place || current.birth_place,
            mother_maiden_name: req.detail_values?.mother_maiden_name || current.mother_maiden_name,
            parents_marriage_details: req.detail_values?.parents_marriage_details || current.parents_marriage_details,
            residency_start_year: req.detail_values?.residency_start_year || current.residency_start_year,
            partner_full_name: req.detail_values?.partner_full_name || current.partner_full_name,
            partner_age: req.detail_values?.partner_age || current.partner_age,
            years_living_together: req.detail_values?.years_living_together || current.years_living_together,
            years_of_residency: req.detail_values?.years_of_residency || current.years_of_residency,
        }));

        if (req.resident.id) {
            void applyResidentProfile(String(req.resident.id));
        }

        setRequestDetailsOpen(false);
        setFormStep(1);
        setModalMode('qr');
        setIsModalOpen(true);
    };

    const handleConfirmPayment = async () => {
        if (!requestDetails || actionLoading) return;
        setActionLoading(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.post<{
                message: string;
                status: string;
                allowed_statuses: string[];
                admin_note: string | null;
            }>(`/clerk/certificates/requests/${requestDetails.id}/confirm-payment`, {
                confirm_payment: true,
                admin_note: adminNoteDraft.trim() || null,
            });

            setRequestDetails((current) =>
                current
                    ? {
                          ...current,
                          status: response.data.status,
                          allowed_statuses: response.data.allowed_statuses,
                      }
                    : current,
            );
            setStatusDraft(response.data.status);
            setRequestActionSuccess(response.data.message);
            router.reload({ only: ['requests'] });
        } catch (error) {
            setRequestActionError(
                requestErrorMessage(error, 'Unable to confirm payment.'),
            );
        } finally {
            setActionLoading(false);
        }
    };

    const handleConfirmRelease = async () => {
        if (!requestDetails || actionLoading) return;
        setActionLoading(true);
        setRequestActionError(null);
        setRequestActionSuccess(null);

        try {
            const response = await axios.post<{
                message: string;
                status: string;
                allowed_statuses: string[];
                admin_note: string | null;
            }>(`/clerk/certificates/requests/${requestDetails.id}/release`, {
                confirm_handover: true,
                admin_note: adminNoteDraft.trim() || null,
            });

            setRequestDetails((current) =>
                current
                    ? {
                          ...current,
                          status: response.data.status,
                          allowed_statuses: response.data.allowed_statuses,
                      }
                    : current,
            );
            setStatusDraft(response.data.status);
            setShowReleaseConfirm(false);
            setRequestActionSuccess(response.data.message);
            router.reload({ only: ['requests'] });
        } catch (error) {
            setRequestActionError(
                requestErrorMessage(error, 'Unable to release certificate.'),
            );
        } finally {
            setActionLoading(false);
        }
    };

    const handleCertificateTypeChange = (type: string) => {
        const delayedPsa = type === DELAYED_PSA_TYPE;
        const marriage = type === MARRIAGE_TYPE;
        const residency = type === RESIDENCY_TYPE;

        setData((current) => ({
            ...current,
            certificate_type: type,
            fee: CERTIFICATE_FEES[type] || '0',
            franchise_no: '',
            business_name: '',
            business_address: '',
            school_institution: '',
            father_name: '',
            father_occupation: '',
            father_monthly_income: '',
            mother_name: '',
            mother_occupation: '',
            mother_monthly_income: '',
            date_of_birth: '',
            sex: '',
            nationality: delayedPsa ? DELAYED_PSA_DEFAULTS.nationality : '',
            birth_place: delayedPsa ? DELAYED_PSA_DEFAULTS.birth_place : '',
            mother_maiden_name: '',
            parents_marriage_details: '',
            residency_start_year: '',
            partner_full_name: '',
            partner_age: '',
            years_living_together: '',
            years_of_residency: '',
        }));

        setDetailsConfirmed(false);
        setProfileError(null);

        // A resident linked before the type was chosen still supplies the
        // date of birth, sex, and purok once this certificate is selected.
        // The Marriage Cohabitation certificate reads the resident's age and
        // purok from the same profile. The Residency certificate names the
        // resident and reads the purok from the same profile.
        if (data.resident_id) {
            void applyResidentProfile(data.resident_id);
        }
    };

    const handleSignatoryChange = (selectedValue: string) => {
        const signatory = signatories.find(
            (sig) => signatoryOptionValue(sig.name, sig.title) === selectedValue,
        );
        setData((data) => ({
            ...data,
            signatory_name: signatory?.name ?? '',
            signatory_title: signatory ? signatory.title : '',
        }));
    };

    const clearResidentIdentity = () => {
        setData((data) => ({
            ...data,
            resident_id: '',
            resident_name: '',
            // The linked resident's own values are dropped with the link, so
            // a later certificate can never reuse them.
            date_of_birth: '',
            sex: '',
            purok: '',
        }));
        setResidentSource('none');
        setLastScannedToken(null);
        setDetailsConfirmed(false);
        setProfileError(null);
    };

    const switchMode = (mode: 'manual' | 'qr') => {
        if (mode === modalMode) {
            return;
        }
        setModalMode(mode);
        setScanError(null);
        clearResidentIdentity();
        setFormStep(1);

        if (mode === 'qr') {
            setQrFocusSignal((signal) => signal + 1);
        }
    };

    const handleEmptyScan = () => {
        setScanError('Please scan a resident QR code first.');
        setQrFocusSignal((signal) => signal + 1);
    };

    const handleQRCodeScanned = async (scannedCode: string) => {
        if (scanLoading) {
            return;
        }
        if (lastScannedToken !== null && lastScannedToken === scannedCode) {
            setScanError(
                'This QR code was already scanned. Scan a different code or switch to manual entry.',
            );
            setQrFocusSignal((signal) => signal + 1);
            return;
        }

        setScanLoading(true);
        setScanError(null);

        try {
            const response = await axios.get('/api/qr/verify-token', {
                params: { token: scannedCode },
            });

            const resident = response.data?.resident;

            if (!resident?.id || !resident?.full_name) {
                clearResidentIdentity();
                setScanError(
                    'Resident not found. Please scan the correct resident QR code.',
                );
                return;
            }

            setLastScannedToken(scannedCode);
            setResidentSource('qr');
            setData((data) => ({
                ...data,
                resident_id: String(resident.id),
                resident_name: resident.full_name,
            }));

            // The Delayed PSA certificate needs the linked resident's date of
            // birth, sex, and purok. The Marriage Cohabitation certificate
            // reads the resident's age and purok from the same profile. The
            // Residency certificate reads the resident's purok from it.
            if (data.certificate_type !== '') {
                void applyResidentProfile(String(resident.id));
            }
        } catch (error) {
            clearResidentIdentity();

            // A 400 (missing token) or 404 (unknown/inactive resident) means the
            // scanned value is not a valid, resolvable resident QR. Anything else
            // (network drop, 5xx, timeout) is a transient/server problem and must
            // not be mislabelled as an invalid QR code.
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;

            if (status === 400 || status === 404) {
                setScanError(
                    'Invalid QR code. Please scan a valid resident QR code.',
                );
            } else {
                setScanError(
                    'Unable to verify the QR code right now. Please check your connection and try again.',
                );
            }
        } finally {
            setScanLoading(false);
            // The lookup is over (resident found, invalid QR, or a network
            // failure), so the scan field takes the focus back for the next
            // scan without the clerk clicking into it again.
            setQrFocusSignal((signal) => signal + 1);
        }
    };

    // ITO ANG MAGLILIPAT SA STEP 2 AT PIPIGIL SA AUTO-DOWNLOAD
    const handleProceedToReview = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault(); // Pinipigilan nito ang anumang form submission behavior
        if (data.resident_name.trim() === '') {
            return;
        }
        setFormStep(2);
    };

    // ITO LANG ANG MAY KARAPATANG MAG-DOWNLOAD
    const refreshIssuedCertificates = () => {
        setActiveTab('issued');
        setIssuedSearchQuery('');
        setIssuedTypeQuery('');
        setIssuedDateFromQuery('');
        setIssuedDateToQuery('');
        lastIssuedQueried.current = '';
        setListLoading(true);
        router.get('/clerk/certificates', {
            ...Object.fromEntries(new URL(window.location.href).searchParams),
            tab: 'issued', issued_page: 1,
            issued_search: '', issued_type: '', issued_date_from: '', issued_date_to: '',
        }, {
            preserveState: true, preserveScroll: true, replace: true,
            only: ['requests', 'issuances', 'allRecords', 'filters', 'issuedFilters', 'allFilters'],
        });
    };

    const handleFinalSubmit = async (action: 'download' | 'print' = 'download') => {
        if (generationInFlightRef.current || generationOutcomeUnknownRef.current) {
            return;
        }

        generationInFlightRef.current = true;
        setGenerationLoading(true);
        setGenerationError(null);

        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const payload = new URLSearchParams();
        Object.entries(data).forEach(([key, value]) => payload.set(key, String(value ?? '')));
        payload.set(
            'details_confirmed',
            data.certificate_type === DELAYED_PSA_TYPE && detailsConfirmed ? '1' : '',
        );

        try {
            const response = await fetch('/clerk/generate-certificate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CSRF-TOKEN': token,
                },
                body: payload.toString(),
            });

            if (response.status === 422) {
                const result = await response.json().catch(() => null);
                const firstError = result?.errors
                    ? Object.values(result.errors).flat().find((message) => typeof message === 'string')
                    : null;
                setGenerationError(typeof firstError === 'string'
                    ? firstError
                    : 'The certificate could not be generated. Review the required details and try again.');
                return;
            }

            if (response.status >= 400 && response.status < 500) {
                setGenerationError('The server rejected the certificate request. Review the details and try again.');
                return;
            }

            // The controller redirects back for a missing/unavailable template
            // or another handled generation failure. Those responses are not
            // attachments and can be retried after the issue is corrected.
            if (response.redirected) {
                setGenerationError('The certificate could not be generated. Check the certificate details and template availability, then try again.');
                return;
            }

            const disposition = response.headers.get('Content-Disposition') || '';
            const contentType = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
            if (!response.ok || response.headers.get('X-Certificate-Issuance-Status') !== 'issued' || !/attachment/i.test(disposition) || ![
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/octet-stream',
            ].includes(contentType || '')) {
                throw new Error('The issuance response did not contain a DOCX attachment.');
            }

            const documentBlob = await response.blob();
            const signature = new Uint8Array(await documentBlob.slice(0, 4).arrayBuffer());
            if (signature[0] !== 0x50 || signature[1] !== 0x4b) {
                throw new Error('The downloaded document is not a valid DOCX file.');
            }

            const encodedFilename = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
            const plainFilename = disposition.match(/filename="?([^";]+)"?/i)?.[1];
            const filename = encodedFilename
                ? decodeURIComponent(encodedFilename)
                : plainFilename || `Certificate_${data.control_no}.docx`;
            if (action === 'print') {
                await printCertificateDocument(documentBlob, `Certificate ${data.control_no}`);
            } else {
                const objectUrl = URL.createObjectURL(documentBlob);
                const downloadLink = document.createElement('a');
                downloadLink.href = objectUrl;
                downloadLink.download = filename;
                document.body.appendChild(downloadLink);
                downloadLink.click();
                downloadLink.remove();
                window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            }

            refreshIssuedCertificates();
            setIsModalOpen(false);
            setFormStep(1);
            setResidentSource('none');
            setLastScannedToken(null);
            setDetailsConfirmed(false);
            setProfileError(null);
            reset();
            setData('control_no', createControlNumber());
        } catch {
            // A connection can fail after the server commits issuance. Block
            // another submit in this page session to avoid issuing a duplicate.
            generationOutcomeUnknownRef.current = true;
            setGenerationOutcomeUnknown(true);
            setGenerationError('The certificate may have been issued, but its download or print could not be confirmed. Check Issued / Completed before attempting another issuance.');
            refreshIssuedCertificates();
        } finally {
            generationInFlightRef.current = false;
            setGenerationLoading(false);
        }
    };

    // Every reviewed detail must be present before the certificate may be
    // issued; the server enforces the same set of values.
    const delayedPsaDetailsComplete =
        data.date_of_birth.trim() !== '' &&
        data.sex.trim() !== '' &&
        data.nationality.trim() !== '' &&
        data.birth_place.trim() !== '' &&
        data.purok.trim() !== '' &&
        data.father_name.trim() !== '' &&
        data.mother_maiden_name.trim() !== '' &&
        data.parents_marriage_details.trim() !== '' &&
        data.residency_start_year.trim() !== '';

    // The Marriage Cohabitation certificate names the resident from the
    // linked resident profile, so only the partner details are collected.
    // A linked resident is required: the server reads the resident name, age,
    // and purok from the resident record and never trusts the form.
    const marriagePartnerAge = parseWholeNumber(data.partner_age);
    const marriageYearsLivingTogether = parseWholeNumber(
        data.years_living_together,
    );

    const marriageDetailsComplete =
        data.resident_id.trim() !== '' &&
        data.partner_full_name.trim() !== '' &&
        marriagePartnerAge !== null &&
        marriagePartnerAge >= MARRIAGE_PARTNER_AGE_MIN &&
        marriagePartnerAge <= MARRIAGE_PARTNER_AGE_MAX &&
        marriageYearsLivingTogether !== null &&
        marriageYearsLivingTogether >= MARRIAGE_YEARS_MIN &&
        marriageYearsLivingTogether <= MARRIAGE_YEARS_MAX;

    // The Residency certificate names the resident from the linked resident
    // profile, so only the years of residency are collected from the Clerk.
    // A linked resident is required: the server reads the resident name and
    // purok from the resident record and never trusts the form.
    const residencyYears = parseWholeNumber(data.years_of_residency);

    const residencyDetailsComplete =
        data.resident_id.trim() !== '' &&
        residencyYears !== null &&
        residencyYears >= RESIDENCY_YEARS_MIN &&
        residencyYears <= RESIDENCY_YEARS_MAX;

    const canIssueCertificate = isDelayedPsa
        ? delayedPsaDetailsComplete && detailsConfirmed
        : isMarriage
          ? marriageDetailsComplete
          : isResidency
            ? residencyDetailsComplete
            : true;

    const renderDelayedPsaFields = () => (
        <div className="space-y-3 rounded-lg border border-sky-100 bg-sky-50 p-4">
            <h4 className="text-xs font-bold text-sky-800 uppercase">
                Delayed PSA Registration Details
            </h4>

            {profileLoading && (
                <p className="text-xs text-sky-800">
                    Loading the linked resident's details…
                </p>
            )}

            {profileError && (
                <div
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    role="alert"
                >
                    {profileError}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Date of Birth
                    </label>
                    <input
                        type="date"
                        value={data.date_of_birth}
                        onChange={(e) =>
                            setData('date_of_birth', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Sex
                    </label>
                    <select
                        value={data.sex}
                        onChange={(e) => setData('sex', e.target.value)}
                        required
                        className="w-full rounded-md border bg-white px-3 py-2 text-sm"
                    >
                        <option value="">Select Sex...</option>
                        {SEX_OPTIONS.map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Nationality
                    </label>
                    <input
                        type="text"
                        value={data.nationality}
                        onChange={(e) => setData('nationality', e.target.value)}
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Birth Place
                    </label>
                    <input
                        type="text"
                        value={data.birth_place}
                        onChange={(e) => setData('birth_place', e.target.value)}
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Purok
                    </label>
                    <input
                        type="text"
                        value={data.purok}
                        onChange={(e) => setData('purok', e.target.value)}
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Residency Start Year
                    </label>
                    <input
                        type="number"
                        min="1583"
                        max={new Date().getFullYear()}
                        step="1"
                        inputMode="numeric"
                        value={data.residency_start_year}
                        onChange={(e) =>
                            setData('residency_start_year', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Full Name of the Father
                    </label>
                    <input
                        type="text"
                        value={data.father_name}
                        onChange={(e) => setData('father_name', e.target.value)}
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Maiden Name of the Mother
                    </label>
                    <input
                        type="text"
                        value={data.mother_maiden_name}
                        onChange={(e) =>
                            setData('mother_maiden_name', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Date and Place of Marriage of Parents
                    </label>
                    <input
                        type="text"
                        value={data.parents_marriage_details}
                        onChange={(e) =>
                            setData('parents_marriage_details', e.target.value)
                        }
                        required
                        placeholder="e.g. 12 June 1985, Puerto Princesa City"
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <label className="flex items-start gap-2 text-xs text-gray-700">
                <input
                    type="checkbox"
                    checked={detailsConfirmed}
                    onChange={(e) => setDetailsConfirmed(e.target.checked)}
                    className="mt-0.5"
                />
                <span>
                    I have reviewed the resident details above and confirm that
                    they are correct.
                </span>
            </label>
        </div>
    );

    const renderMarriageFields = () => (
        <div className="space-y-3 rounded-lg border border-rose-100 bg-rose-50 p-4">
            <h4 className="text-xs font-bold text-rose-800 uppercase">
                Partner Details
            </h4>

            {profileLoading && (
                <p className="text-xs text-rose-800">
                    Loading the linked resident's details…
                </p>
            )}

            {profileError && (
                <div
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    role="alert"
                >
                    The resident record could not be loaded. The age and purok
                    on this certificate come from the resident record, so the
                    certificate can only be issued against a linked resident.
                </div>
            )}

            {data.resident_id.trim() === '' && (
                <div
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    role="alert"
                >
                    This certificate names the resident from the resident
                    record. Scan the resident's QR code to link the resident
                    before issuing it.
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Partner Full Name
                    </label>
                    <input
                        type="text"
                        value={data.partner_full_name}
                        onChange={(e) =>
                            setData('partner_full_name', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Partner Age ({MARRIAGE_PARTNER_AGE_MIN}-
                        {MARRIAGE_PARTNER_AGE_MAX})
                    </label>
                    <input
                        type="number"
                        min={MARRIAGE_PARTNER_AGE_MIN}
                        max={MARRIAGE_PARTNER_AGE_MAX}
                        step="1"
                        inputMode="numeric"
                        value={data.partner_age}
                        onChange={(e) => setData('partner_age', e.target.value)}
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Years Living Together ({MARRIAGE_YEARS_MIN}-
                        {MARRIAGE_YEARS_MAX})
                    </label>
                    <input
                        type="number"
                        min={MARRIAGE_YEARS_MIN}
                        max={MARRIAGE_YEARS_MAX}
                        step="1"
                        inputMode="numeric"
                        value={data.years_living_together}
                        onChange={(e) =>
                            setData('years_living_together', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
            </div>
        </div>
    );

    const renderResidencyFields = () => (
        <div className="space-y-3 rounded-lg border border-teal-100 bg-teal-50 p-4">
            <h4 className="text-xs font-bold text-teal-800 uppercase">
                Residency Details
            </h4>

            {profileLoading && (
                <p className="text-xs text-teal-800">
                    Loading the linked resident's details…
                </p>
            )}

            {profileError && (
                <div
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    role="alert"
                >
                    The resident record could not be loaded. The name and purok
                    on this certificate come from the resident record, so the
                    certificate can only be issued against a linked resident.
                </div>
            )}

            {data.resident_id.trim() === '' && (
                <div
                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    role="alert"
                >
                    This certificate names the resident from the resident
                    record. Scan the resident's QR code to link the resident
                    before issuing it.
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                        Years of Residency ({RESIDENCY_YEARS_MIN}-
                        {RESIDENCY_YEARS_MAX})
                    </label>
                    <input
                        type="number"
                        min={RESIDENCY_YEARS_MIN}
                        max={RESIDENCY_YEARS_MAX}
                        step="1"
                        inputMode="numeric"
                        value={data.years_of_residency}
                        onChange={(e) =>
                            setData('years_of_residency', e.target.value)
                        }
                        required
                        className="w-full rounded-md border px-3 py-2 text-sm"
                    />
                </div>
            </div>

            <p className="text-xs text-gray-600">
                The name and purok are taken from the linked resident record.
                The number of years is recorded on the certificate only.
            </p>
        </div>
    );

    const renderDocumentPreview = () => {
        if (data.certificate_type === DELAYED_PSA_TYPE) {
            const residentName =
                data.resident_name.toUpperCase() || '___________________';
            const purok = data.purok.toUpperCase() || '__________';
            const dateOfBirth =
                formatLongDate(data.date_of_birth) || '__________';
            const sex = data.sex || '__________';
            const nationality = data.nationality.toUpperCase() || '__________';
            const birthPlace = data.birth_place.toUpperCase() || '__________';
            const fatherName =
                data.father_name.toUpperCase() || '___________________';
            const motherName =
                data.mother_maiden_name.toUpperCase() || '___________________';
            const marriageDetails =
                data.parents_marriage_details.toUpperCase() ||
                '___________________';
            const residencyYear = data.residency_start_year.trim();
            const residencyPeriod =
                residencyYear !== ''
                    ? `${residencyYear} up to present`
                    : '__________';

            return (
                <div className="space-y-4 text-left">
                    <p className="text-center text-xs tracking-wide uppercase">
                        Republic of the Philippines City of Puerto Princesa
                        BARANGAY SAN JOSE
                    </p>
                    <p className="text-center text-xs tracking-wide uppercase">
                        Office of the Punong Barangay
                    </p>
                    <p className="text-center text-sm font-bold tracking-[0.2em]">
                        C E R T I F I C A T I O N
                    </p>

                    <p>TO WHOM IT MAY CONCERN;</p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This is to certify that the person whose name, photo,
                        signature/thumbmark appears herein is bona fide resident
                        of {purok} , Barangay San Jose, Puerto Princesa City,
                        Palawan.
                    </p>

                    <div className="mt-4 space-y-1">
                        <p>
                            NAME:{' '}
                            <strong className="underline">
                                {residentName}
                            </strong>
                        </p>
                        <p>SEX: {sex}</p>
                        <p>NATIONALITY: {nationality}</p>
                        <p>BIRTHDAY: {dateOfBirth}</p>
                        <p>BIRTH PLACE: {birthPlace}</p>
                        <p>BARANGAY ADDRESS: PUROK {purok}, BGY.SAN JOSE PPC</p>
                    </div>

                    <p className="mt-4">
                        FULL NAME OF THE FATHER; <strong>{fatherName}</strong>
                    </p>
                    <p className="mt-2">
                        MAIDEN NAME OF THE MOTHER: <strong>{motherName}</strong>
                    </p>
                    <p className="mt-2">
                        DATE AND PLACE OF MARRIAGE OF PARENTS: {marriageDetails}
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This is to certify further that the person named above
                        has been a resident of this Barangay from{' '}
                        {residencyPeriod}.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This certification is issued upon the request of the
                        above-named person for the purpose of
                    </p>
                    <p className="text-center font-bold">
                        {DELAYED_PSA_PURPOSE}.
                    </p>

                    <div className="mt-8">
                        <p className="text-sm font-bold">{residentName}</p>
                        <p className="text-xs italic">
                            Name and signature/thumbmark of the resident
                        </p>
                    </div>

                    <p className="mt-4 text-justify leading-relaxed">
                        Issued on this {formatIssueDate()} at Barangay Hall,
                        Barangay San Jose, City of Puerto Princesa.
                    </p>

                    <div className="mt-8 text-right">
                        <p className="text-sm font-bold">
                            HON. JENNIFER CALINGAO TRINIDAD
                        </p>
                        <p className="text-xs italic">Punong Barangay</p>
                    </div>

                    <div className="mt-6">
                        <p className="text-sm">
                            By the Authority of the Punong Barangay,
                        </p>
                        <div className="mt-6 text-right">
                            <p className="text-sm font-bold">
                            {data.signatory_name || '___________________'}
                            </p>
                            <p className="text-xs italic">Officer on duty</p>
                        </div>
                    </div>

                    <p className="mt-6 text-xs italic">
                        Note: This Certification is valid only for Three (3)
                        Months from the day this was issued.
                    </p>
                    <p className="text-xs italic">
                        Do not accept if a photocopy of this Certification is
                        not a Certified true Copy, with erasures or Alterations.
                    </p>
                </div>
            );
        }

        if (data.certificate_type === MARRIAGE_TYPE) {
            const residentName =
                data.resident_name.trim().toUpperCase() ||
                '___________________';
            const residentAge = calculateAge(data.date_of_birth);
            const partnerName =
                data.partner_full_name.trim().toUpperCase() ||
                '___________________';
            const partnerAge = parseWholeNumber(data.partner_age);
            const purokName = data.purok.trim().toUpperCase();
            const purok =
                purokName !== '' ? `PUROK ${purokName}` : '__________';
            const yearsLivingTogether = parseWholeNumber(
                data.years_living_together,
            );

            return (
                <div className="space-y-4 text-left">
                    <p className="text-center text-xs tracking-wide uppercase">
                        Republic of the Philippines City of Puerto Princesa
                        BARANGAY SAN JOSE
                    </p>
                    <p className="text-center text-xs tracking-wide uppercase">
                        Office of the Punong Barangay
                    </p>
                    <p className="text-center text-sm font-bold tracking-[0.2em]">
                        C E R T I F I C A T I O N
                    </p>

                    <p>To Whom It May Concern:</p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This is to certify that{' '}
                        <strong className="underline">{residentName}</strong>,{' '}
                        {residentAge !== null ? residentAge : '____'} and{' '}
                        <strong className="underline">{partnerName}</strong>,{' '}
                        {partnerAge !== null ? partnerAge : '____'}, both
                        resident of {purok}, Barangay San Jose, City of Puerto
                        Princesa.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        That the above mentioned individuals are living together
                        for{' '}
                        {yearsLivingTogether !== null
                            ? yearsLivingTogether
                            : '____'}
                        .
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This certification is being issued upon the request of{' '}
                        <strong className="underline">{residentName}</strong>{' '}
                        for {MARRIAGE_PURPOSE}.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        Issued on this {formatIssueDate()} at Barangay Hall,
                        Barangay San Jose, City of Puerto Princesa.
                    </p>

                    <div className="mt-10 text-right">
                        <p className="text-sm font-bold">
                            HON. JENNIFER CALINGAO TRINIDAD
                        </p>
                        <p className="text-xs italic">Punong Barangay</p>
                    </div>

                    <div className="mt-6">
                        <p className="text-sm">
                            By the Authority of the Punong Barangay,
                        </p>
                        <div className="mt-6 text-right">
                            <p className="text-sm font-bold">
                            {data.signatory_name || '___________________'}
                            </p>
                            <p className="text-xs italic">{data.signatory_title || 'Officer on duty'}</p>
                        </div>
                    </div>

                    <p className="mt-6 text-xs italic">
                        Note: This Certification is valid only for Three (3)
                        Months from the day this was issued.
                    </p>
                    <p className="text-xs italic">
                        Do not accept if a photocopy of this Certification is
                        not a Certified true Copy, with erasures or Alterations.
                    </p>
                </div>
            );
        }

        if (data.certificate_type === RESIDENCY_TYPE) {
            const residentName =
                data.resident_name.trim().toUpperCase() ||
                '___________________';
            const purokName = data.purok.trim().toUpperCase();
            const purok =
                purokName !== '' ? `PUROK ${purokName}` : '__________';
            const yearsOfResidency = parseWholeNumber(data.years_of_residency);

            return (
                <div className="space-y-4 text-left">
                    <p className="text-center text-xs tracking-wide uppercase">
                        Republic of the Philippines City of Puerto Princesa
                        BARANGAY SAN JOSE
                    </p>
                    <p className="text-center text-xs tracking-wide uppercase">
                        Office of the Punong Barangay
                    </p>
                    <p className="text-center text-sm font-bold tracking-[0.2em]">
                        C E R T I F I C A T I O N
                    </p>

                    <p>This is to certify that</p>
                    <p className="font-bold underline">{residentName}</p>
                    <p>A bona fide resident of</p>
                    <p>{purok}</p>
                    <p>Brgy. San Jose, Puerto Princesa City.</p>

                    <p className="mt-4 text-justify leading-relaxed">
                        That the above-mentioned individual is a law abiding
                        citizen, with good moral character and has not been
                        convicted nor involved in any unlawful activity.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This further certifies that {residentName}, is a
                        resident of this barangay for{' '}
                        {yearsOfResidency !== null ? yearsOfResidency : '____'}
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        Issued this {formatIssueDate()} at the Barangay Hall of
                        Barangay San Jose, Puerto Princesa City.
                    </p>

                    <div className="mt-6 border-l-4 border-blue-500 bg-blue-100 p-2 text-left font-sans text-xs">
                        <span className="font-bold text-blue-800 uppercase">
                            Purpose:{' '}
                        </span>
                        {RESIDENCY_PURPOSE}
                    </div>

                    <div className="mt-8 text-right">
                        <p className="text-sm font-bold">
                            HON. JENNIFER CALINGAO TRINIDAD
                        </p>
                        <p className="text-xs italic">Punong Barangay</p>
                    </div>

                    <div className="mt-6">
                        <p className="text-sm">
                            By the Authority of the Punong Barangay,
                        </p>
                        <div className="mt-6 text-right">
                            <p className="text-sm font-bold">
                            {data.signatory_name || '___________________'}
                            </p>
                            <p className="text-xs italic">{data.signatory_title || 'Officer on duty'}</p>
                        </div>
                    </div>

                    <p className="mt-6 text-xs italic">
                        Note: This Certification is valid only for Three (3)
                        Months from the day this was issued.
                    </p>
                    <p className="text-xs italic">
                        Do not accept if a photocopy of this Certification is
                        not a Certified true Copy, with erasures or Alterations.
                    </p>
                </div>
            );
        }

        if (data.certificate_type === 'Tricycle') {
            return (
                <div className="space-y-4">
                    <p>This is to certify that</p>
                    <p className="text-lg font-bold underline">
                        {data.resident_name.toUpperCase() ||
                            '___________________'}
                    </p>
                    <p>Operator/Driver of</p>
                    <p className="font-bold">
                        ONE(1) UNIT TRICYCLE (FRANCHISE #{' '}
                        {data.franchise_no.toUpperCase() || '_______'})
                    </p>
                    <p>With a Business/Trade name</p>
                    <p className="font-bold">
                        {data.business_name.toUpperCase() ||
                            '___________________'}
                    </p>
                    <p>is lawfully operating at</p>
                    <p className="font-bold">
                        {data.business_address.toUpperCase() ||
                            '___________________'}
                    </p>

                    <div className="mt-6 border-l-4 border-blue-500 bg-blue-100 p-2 text-left font-sans text-xs">
                        <span className="font-bold text-blue-800 uppercase">
                            Purpose:{' '}
                        </span>
                        {data.purpose.toUpperCase() || '___________________'}
                    </div>

                    <div className="mt-8 text-right">
                        <p className="text-sm font-bold">
                            {data.signatory_name || '___________________'}
                        </p>
                        <p className="text-xs italic">
                            {data.signatory_title || 'Signatory Title'}
                        </p>
                    </div>
                </div>
            );
        }

        if (data.certificate_type === 'Scholarship Certificate') {
            return (
                <div className="space-y-4 text-left">
                    <p className="text-center text-xs tracking-wide uppercase">
                        {'Republic of the Philippines'} · City of Puerto
                        Princesa · Barangay San Jose
                    </p>
                    <p className="text-center text-xs tracking-wide uppercase">
                        Office of the Punong Barangay
                    </p>
                    <p className="text-center text-sm font-bold tracking-[0.2em]">
                        INDIGENT CERTIFICATE
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This is to certify that{' '}
                        <strong className="underline">
                            {data.resident_name.toUpperCase() ||
                                '___________________'}
                        </strong>
                        , is a resident of {data.purok || '__________'} Barangay
                        San Jose, City of Puerto Princesa. A{' '}
                        {data.school_institution.toUpperCase() ||
                            '___________________'}
                        .
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This further certifies that he/she is a child of{' '}
                        <strong>
                            {data.father_name.toUpperCase() ||
                                '___________________'}
                        </strong>{' '}
                        (
                        <em>
                            {data.father_occupation.toUpperCase() ||
                                '____________'}
                        </em>
                        ) With Monthly Income (
                        {data.father_monthly_income || '__________'}) and{' '}
                        <strong>
                            {data.mother_name.toUpperCase() ||
                                '___________________'}
                        </strong>{' '}
                        (
                        <em>
                            {data.mother_occupation.toUpperCase() ||
                                '____________'}
                        </em>
                        ) With Monthly Income (
                        {data.mother_monthly_income || '__________'})
                        who&lsquo;s monthly family income does not exceed to the
                        government&rsquo;s stipulated poverty threshold level.
                        Thus, is exempted from filing income tax.
                    </p>

                    <p className="mt-4 text-center font-bold">
                        THIS FURTHER CERTIFIES THAT THEY BELONG TO THE INDIGENT
                        FAMILY.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This certification is issued for the authentication of
                        the above-named person for declaration of his/her
                        residency and family income status./ for{' '}
                        {data.purpose.toUpperCase() || '___________________'}
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        Issued on this{' '}
                        {new Date().toLocaleDateString('en-US', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        })}{' '}
                        at Barangay Hall, Barangay San Jose, City of Puerto
                        Princesa.
                    </p>

                    <div className="mt-10 text-right">
                        <p className="text-sm font-bold">
                            JENNIFER CALINGAO TRINIDAD
                        </p>
                        <p className="text-xs italic">Punong Barangay</p>
                    </div>

                    <div className="mt-8">
                        <p className="text-sm">
                            By the Authority of the Punong Barangay,
                        </p>
                        <div className="mt-8 text-right">
                            <p className="text-sm font-bold">
                                {data.signatory_name || '___________________'}
                            </p>
                            <p className="text-xs italic">
                                {data.signatory_title || 'Officer on duty'}
                            </p>
                        </div>
                    </div>

                    <p className="mt-6 text-xs italic">
                        Note: This Certification is valid only for Three (3)
                        Months from the day this was issued.
                    </p>
                    <p className="text-xs italic">
                        Do not accept if a photocopy of this Certification is
                        not a Certified true Copy, with erasures or Alterations.
                    </p>
                </div>
            );
        }

        if (data.certificate_type === 'Indigency') {
            return (
                <div className="space-y-4 text-left">
                    <p className="text-center text-xs tracking-wide uppercase">
                        Republic of the Philippines City of Puerto Princesa
                        BARANGAY SAN JOSE
                    </p>
                    <p className="text-center text-xs tracking-wide uppercase">
                        Office of the Punong Barangay
                    </p>
                    <p className="text-center text-sm font-bold tracking-[0.2em]">
                        C E R T I F I C A T I O N
                    </p>
                    <p className="text-center text-sm italic">
                        (Indigent Certificate)
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This is to certify that{' '}
                        <strong className="underline">
                            {data.resident_name.toUpperCase() ||
                                '___________________'}
                        </strong>
                        , is a bonafide resident of {data.purok || '__________'}{' '}
                        Barangay San Jose, City of Puerto Princesa. That she/he
                        belongs to the indigent family. That their
                        Family&rsquo;s Monthly Income is not sufficient for
                        their other Needs.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        This certification is being issued upon the request of
                        the above-named person for the purpose of{' '}
                        {data.purpose.toUpperCase() || '___________________'}.
                    </p>

                    <p className="mt-4 text-justify leading-relaxed">
                        Issued on this{' '}
                        {new Date().toLocaleDateString('en-US', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        })}{' '}
                        at Barangay Hall, Barangay San Jose, City of Puerto
                        Princesa.
                    </p>

                    <div className="mt-10 text-right">
                        <p className="text-sm font-bold">
                            HON. JENNIFER CALINGAO TRINIDAD
                        </p>
                        <p className="text-xs italic">Punong Barangay</p>
                    </div>

                    <div className="mt-8">
                        <p className="text-sm">
                            By the Authority of the Punong Barangay,
                        </p>
                        <div className="mt-8 text-right">
                            <p className="text-sm font-bold">
                                {data.signatory_name || '___________________'}
                            </p>
                            <p className="text-xs italic">Officer on duty</p>
                        </div>
                    </div>

                    <p className="mt-6 text-xs italic">
                        Note: This Certification is valid only for Three (3)
                        Months from the day this was issued.
                    </p>
                    <p className="text-xs italic">
                        Do not accept if a photocopy of this Certification is
                        not a Certified true Copy, with erasures or Alterations.
                    </p>
                </div>
            );
        }

        return (
            <div className="space-y-4">
                <p>TO WHOM IT MAY CONCERN:</p>
                <p className="mt-4 text-justify leading-relaxed">
                    This is to certify that{' '}
                    <strong className="underline">
                        {data.resident_name.toUpperCase() ||
                            '___________________'}
                    </strong>{' '}
                    is a bona fide resident of Purok{' '}
                    {data.purok || '___________'}, Barangay San Jose, Puerto
                    Princesa City.
                </p>
                <p className="mt-4 text-justify leading-relaxed">
                    This certification is being issued upon the request of the
                    above-named person for whatever legal purpose it may serve.
                </p>

                <div className="mt-6 border-l-4 border-blue-500 bg-blue-100 p-2 text-left font-sans text-xs">
                    <span className="font-bold text-blue-800 uppercase">
                        Purpose:{' '}
                    </span>
                    {data.purpose.toUpperCase() || '___________________'}
                </div>

                <div className="mt-10 text-right">
                    <p className="text-sm font-bold">
                        {data.signatory_name || '___________________'}
                    </p>
                    <p className="text-xs italic">
                        {data.signatory_title || 'Signatory Title'}
                    </p>
                </div>
            </div>
        );
    };

    return (
        <ClerkLayout user={auth.user} active="certificates">
            <Head title="Certificate Issuance" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="text-xl font-bold text-gray-800 md:text-2xl">
                            Certificate Issuance
                        </h1>
                    </div>
                    <button
                        onClick={() => {
                            setModalMode('qr');
                            setIsModalOpen(true);
                            setFormStep(1);
                            setScanError(null);
                            if (!generationOutcomeUnknownRef.current) {
                                setGenerationError(null);
                            }
                            setResidentSource('none');
                            setLastScannedToken(null);
                            reset();
                            setData('control_no', createControlNumber());
                        }}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#315b30]"
                    >
                        + Issue New Certificate
                    </button>
                </div>

                {/* CERTIFICATE MANAGEMENT */}
                <div className="rounded-lg border border-[#e6ddcd] bg-white shadow-sm">
                    <div className="flex border-b border-[#e6ddcd] bg-[#f0ebe1]">
                        {(
                            [
                                ['requests', 'Requests'],
                                ['issued', 'Issued / Completed'],
                                ['all', 'All Records'],
                            ] as [CertificateTab, string][]
                        ).map(([tab, label]) => (
                            <button
                                key={tab}
                                type="button"
                                onClick={() => setActiveTab(tab)}
                                className={`border-b-2 px-4 py-3 text-sm font-medium transition-colors ${
                                    activeTab === tab
                                        ? 'border-[#3f6f3d] bg-white text-[#3f6f3d]'
                                        : 'border-transparent text-[#8a7f6a] hover:text-gray-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {/* SEARCH & FILTER BAR */}
                    <div className="flex flex-col gap-3 border-b border-[#e6ddcd] p-4 sm:flex-row sm:items-center">
                        <div className="relative flex-1">
                            <span
                                className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[#8a7f6a]"
                                aria-hidden="true"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    className="h-4 w-4"
                                >
                                    <path
                                        fillRule="evenodd"
                                        d="M9 3.5a5.5 5.5 0 1 0 3.4 9.83l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z"
                                        clipRule="evenodd"
                                    />
                                </svg>
                            </span>
                            <input
                                type="text"
                                value={
                                    activeTab === 'issued'
                                        ? issuedSearchQuery
                                        : activeTab === 'all'
                                          ? allSearchQuery
                                          : searchQuery
                                }
                                onChange={(e) =>
                                    activeTab === 'issued'
                                        ? setIssuedSearchQuery(e.target.value)
                                        : activeTab === 'all'
                                          ? setAllSearchQuery(e.target.value)
                                          : setSearchQuery(e.target.value)
                                }
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') applySearch();
                                }}
                                placeholder={
                                    activeTab === 'issued'
                                        ? 'Search resident, resident no., or issuance reference...'
                                        : activeTab === 'all'
                                          ? 'Search all records by resident, resident no., or reference...'
                                          : 'Search resident, resident no., or reference...'
                                }
                                aria-label={
                                    activeTab === 'issued'
                                        ? 'Search certificate issuances'
                                        : activeTab === 'all'
                                          ? 'Search all certificate records'
                                          : 'Search certificate requests'
                                }
                                className="w-full rounded-md border border-[#e6ddcd] py-2 pr-3 pl-9 text-sm focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] focus:outline-none"
                            />
                        </div>

                        <div className="relative sm:shrink-0">
                            <button
                                ref={popoverButtonRef}
                                type="button"
                                onClick={() => setPopoverOpen((open) => !open)}
                                aria-label="Open filters"
                                aria-expanded={popoverOpen}
                                aria-haspopup="true"
                                className="flex w-full items-center justify-center gap-2 rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-[#faf8f2] sm:w-auto"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    className="h-4 w-4"
                                    aria-hidden="true"
                                >
                                    <path d="M3 5.75A.75.75 0 0 1 3.75 5h12.5a.75.75 0 0 1 0 1.5H3.75A.75.75 0 0 1 3 5.75Zm2 4.25a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1-.75-.75Zm3 4.25a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Z" />
                                </svg>
                                Filters
                                {activeFilterCount > 0 && (
                                    <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#3f6f3d] px-1.5 text-xs font-semibold text-white">
                                        {activeFilterCount}
                                    </span>
                                )}
                            </button>

                            {popoverOpen && (
                                <div
                                    ref={popoverRef}
                                    role="dialog"
                                    aria-label={
                                        activeTab === 'issued'
                                            ? 'Filter certificate issuances'
                                            : activeTab === 'all'
                                              ? 'Filter all certificate records'
                                              : 'Filter certificate requests'
                                    }
                                    className="absolute right-0 z-30 mt-2 w-[min(20rem,calc(100vw-2rem))] rounded-lg border border-[#e6ddcd] bg-white p-4 shadow-lg"
                                >
                                    <div className="space-y-3">
                                        {activeTab === 'issued' ? (
                                            <>
                                                <div>
                                                    <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                        Certificate Type
                                                    </label>
                                                    <select
                                                        value={issuedTypeQuery}
                                                        onChange={(e) =>
                                                            setIssuedTypeQuery(
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                    >
                                                        <option value="">
                                                            All types
                                                        </option>
                                                        {certificateTypes.map(
                                                            (type) => (
                                                                <option
                                                                    key={
                                                                        type.value
                                                                    }
                                                                    value={
                                                                        type.value
                                                                    }
                                                                >
                                                                    {type.value}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>

                                                {activeTab === 'issued' && (
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            Record Type
                                                        </label>
                                                        <select
                                                            value={activeTab}
                                                            onChange={(e) =>
                                                                setActiveTab(
                                                                    e.target
                                                                        .value as CertificateTab,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                        >
                                                            <option value="all">
                                                                All Records
                                                            </option>
                                                            <option value="requests">
                                                                Requests
                                                            </option>
                                                            <option value="issued">
                                                                Issued /
                                                                Completed
                                                            </option>
                                                        </select>
                                                    </div>
                                                )}

                                                <div className="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            From
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={
                                                                issuedDateFromQuery
                                                            }
                                                            onChange={(e) =>
                                                                setIssuedDateFromQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            To
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={
                                                                issuedDateToQuery
                                                            }
                                                            onChange={(e) =>
                                                                setIssuedDateToQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                </div>
                                            </>
                                        ) : activeTab === 'all' ? (
                                            <>
                                                <div>
                                                    <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                        Certificate Type
                                                    </label>
                                                    <select
                                                        value={allTypeQuery}
                                                        onChange={(e) =>
                                                            setAllTypeQuery(
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                    >
                                                        <option value="">
                                                            All types
                                                        </option>
                                                        <optgroup label="Certificate Templates">
                                                            {certificateTypes.map(
                                                                (type) => (
                                                                    <option
                                                                        key={`tpl-${type.value}`}
                                                                        value={
                                                                            type.value
                                                                        }
                                                                    >
                                                                        {
                                                                            type.value
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </optgroup>
                                                        {certificateOptions.length >
                                                            0 && (
                                                            <optgroup label="Barangay Certificates">
                                                                {certificateOptions.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <option
                                                                            key={`cert-${option.id}`}
                                                                            value={String(
                                                                                option.id,
                                                                            )}
                                                                        >
                                                                            {
                                                                                option.name
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </optgroup>
                                                        )}
                                                    </select>
                                                </div>

                                                <div className="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            From
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={
                                                                allDateFromQuery
                                                            }
                                                            onChange={(e) =>
                                                                setAllDateFromQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            To
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={
                                                                allDateToQuery
                                                            }
                                                            onChange={(e) =>
                                                                setAllDateToQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div>
                                                    <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                        Certificate Type
                                                    </label>
                                                    <select
                                                        value={certificateQuery}
                                                        onChange={(e) =>
                                                            setCertificateQuery(
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                    >
                                                        <option value="">
                                                            All types
                                                        </option>
                                                        {certificateOptions.map(
                                                            (option) => (
                                                                <option
                                                                    key={
                                                                        option.id
                                                                    }
                                                                    value={String(
                                                                        option.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        option.name
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>

                                                <div>
                                                    <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                        Record Type
                                                    </label>
                                                    <select
                                                        value={activeTab}
                                                        onChange={(e) =>
                                                            setActiveTab(
                                                                e.target
                                                                    .value as CertificateTab,
                                                            )
                                                        }
                                                        className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                    >
                                                        <option value="all">
                                                            All Records
                                                        </option>
                                                        <option value="requests">
                                                            Requests
                                                        </option>
                                                        <option value="issued">
                                                            Issued / Completed
                                                        </option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                        Status
                                                    </label>
                                                    <select
                                                        value={statusQuery}
                                                        onChange={(e) =>
                                                            setStatusQuery(
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="w-full rounded-md border border-[#e6ddcd] bg-white px-3 py-2 text-sm"
                                                    >
                                                        <option value="">
                                                            All statuses
                                                        </option>
                                                        {statuses.map(
                                                            (status) => (
                                                                <option
                                                                    key={status}
                                                                    value={
                                                                        status
                                                                    }
                                                                >
                                                                    {status}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>

                                                <div className="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            From
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={
                                                                dateFromQuery
                                                            }
                                                            onChange={(e) =>
                                                                setDateFromQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="mb-1 block text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                            To
                                                        </label>
                                                        <input
                                                            type="date"
                                                            value={dateToQuery}
                                                            onChange={(e) =>
                                                                setDateToQuery(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border border-[#e6ddcd] px-3 py-2 text-sm"
                                                        />
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </div>

                                    <div className="mt-4 flex items-center justify-end gap-2 border-t border-[#e6ddcd] pt-3">
                                        <button
                                            type="button"
                                            onClick={clearFilters}
                                            className="rounded-md px-3 py-1.5 text-sm font-medium text-[#8a7f6a] hover:bg-[#faf8f2]"
                                        >
                                            Clear
                                        </button>
                                        <button
                                            type="button"
                                            onClick={applyFilters}
                                            className="rounded-md bg-[#3f6f3d] px-4 py-1.5 text-sm font-medium text-white hover:bg-[#315b30]"
                                        >
                                            Apply Filters
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {activeTab === 'all' && (
                        <div className="border-b border-[#e6ddcd] bg-blue-50 px-4 py-2 text-xs text-blue-800">
                            Combined view of{' '}
                            <span className="font-semibold">requests</span> and{' '}
                            <span className="font-semibold">
                                issued certificates
                            </span>
                            . Each row shows its record type.
                        </div>
                    )}

                    {/* TABLE */}
                    <div className="overflow-x-auto">
                        {activeTab === 'issued' ? (
                            <table className="w-full text-left text-sm">
                                <thead className="bg-[#f0ebe1] text-[10px] tracking-wide text-[#8a7f6a] uppercase">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">
                                            Reference
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Resident
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Certificate
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Purok
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Issued
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Issued By
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#f0ebe1]">
                                    {listLoading ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                Loading…
                                            </td>
                                        </tr>
                                    ) : issuances.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                {hasFilters
                                                    ? 'No issued certificates match your search or filters.'
                                                    : 'No certificates have been issued yet.'}
                                            </td>
                                        </tr>
                                    ) : (
                                        issuances.data.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="hover:bg-[#faf8f2]"
                                            >
                                                <td className="px-4 py-3 font-mono text-xs font-medium text-gray-800">
                                                    {row.reference}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-gray-800">
                                                        {row.resident.name}
                                                    </p>
                                                    <p className="text-xs text-[#8a7f6a]">
                                                        {row.resident
                                                            .resident_number ??
                                                            '—'}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {row.certificate_type}
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.purok ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.issued_at}
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.issued_by}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        ) : activeTab === 'all' ? (
                            <table className="w-full text-left text-sm">
                                <thead className="bg-[#f0ebe1] text-[10px] tracking-wide text-[#8a7f6a] uppercase">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Reference
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Resident
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Certificate
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Purok
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Date
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#f0ebe1]">
                                    {listLoading ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                Loading…
                                            </td>
                                        </tr>
                                    ) : allRecords.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                {hasFilters
                                                    ? 'No records match your search or filters.'
                                                    : 'No certificate records yet.'}
                                            </td>
                                        </tr>
                                    ) : (
                                        allRecords.data.map((row) => (
                                            <tr
                                                key={`${row.record_type}-${row.id}`}
                                                className="hover:bg-[#faf8f2]"
                                            >
                                                <td className="px-4 py-3">
                                                    {row.record_type ===
                                                    'issuance' ? (
                                                        <span className="inline-flex rounded-full border border-teal-200 bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-800">
                                                            Issued
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex rounded-full border border-[#e6ddcd] bg-[#f0ebe1] px-2 py-0.5 text-xs font-semibold text-[#8a7f6a]">
                                                            Request
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs font-medium text-gray-800">
                                                    {row.reference}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-gray-800">
                                                        {row.resident.name}
                                                    </p>
                                                    <p className="text-xs text-[#8a7f6a]">
                                                        {row.resident
                                                            .resident_number ??
                                                            '—'}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {row.certificate}
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.purok ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {row.record_type ===
                                                        'issuance' ||
                                                    row.status === null ? (
                                                        <span className="inline-flex rounded-full bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-800">
                                                            Issued
                                                        </span>
                                                    ) : (
                                                        <span
                                                            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[row.status] ?? 'bg-gray-100 text-gray-700'}`}
                                                        >
                                                            {row.status}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.occurred_at}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead className="bg-[#f0ebe1] text-[10px] tracking-wide text-[#8a7f6a] uppercase">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">
                                            Reference
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Resident
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Certificate
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Submitted
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#f0ebe1]">
                                    {listLoading ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                Loading…
                                            </td>
                                        </tr>
                                    ) : requests.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-10 text-center text-sm text-[#8a7f6a]"
                                            >
                                                {hasFilters
                                                    ? 'No requests match your search or filters.'
                                                    : 'No certificate requests yet.'}
                                            </td>
                                        </tr>
                                    ) : (
                                        requests.data.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="hover:bg-[#faf8f2]"
                                            >
                                                <td className="px-4 py-3 font-mono text-xs font-medium text-gray-800">
                                                    {row.reference}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-gray-800">
                                                        {row.resident.name}
                                                    </p>
                                                    <p className="text-xs text-[#8a7f6a]">
                                                        {row.resident
                                                            .resident_number ??
                                                            '—'}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-gray-700">
                                                    {row.certificate}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_STYLES[row.status] ?? 'bg-gray-100 text-gray-700'}`}
                                                    >
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-gray-600">
                                                    {row.submitted_at}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openRequestDetails(
                                                                row.id,
                                                            )
                                                        }
                                                        className="rounded-md border border-[#e6ddcd] px-2.5 py-1 text-xs font-medium text-[#3f6f3d] hover:bg-[#f0ebe1]"
                                                    >
                                                        Details
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>

                    {/* PAGINATION */}
                    {(() => {
                        const page =
                            activeTab === 'issued'
                                ? issuances
                                : activeTab === 'all'
                                  ? allRecords
                                  : requests;
                        // Row count is a paginator controller value, not a
                        // generated/printed/released claim about the document.
                        return (
                            <div className="flex flex-col items-center justify-between gap-3 border-t border-[#e6ddcd] px-4 py-3 text-xs text-[#8a7f6a] sm:flex-row">
                                <span>
                                    {page.total === 0
                                        ? 'No records'
                                        : `Showing ${page.from}–${page.to} of ${page.total}`}
                                </span>
                                <div className="flex items-center gap-3">
                                    <label className="flex items-center gap-1.5">
                                        <span>Rows per page</span>
                                        <select
                                            value={
                                                activeTab === 'issued'
                                                    ? issuedFilters.per_page
                                                    : filters.per_page
                                            }
                                            onChange={(e) =>
                                                changePerPage(
                                                    Number(e.target.value),
                                                )
                                            }
                                            className="rounded-md border border-[#e6ddcd] bg-white px-2 py-1 text-xs text-gray-700"
                                        >
                                            {[10, 25, 50].map((size) => (
                                                <option key={size} value={size}>
                                                    {size}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            disabled={!page.prev_page_url}
                                            onClick={() =>
                                                goToPage(page.prev_page_url)
                                            }
                                            className="rounded-md border px-3 py-1.5 font-medium text-gray-700 disabled:opacity-40"
                                        >
                                            Previous
                                        </button>
                                        <span>
                                            Page {page.current_page} of{' '}
                                            {page.last_page}
                                        </span>
                                        <button
                                            type="button"
                                            disabled={!page.next_page_url}
                                            onClick={() =>
                                                goToPage(page.next_page_url)
                                            }
                                            className="rounded-md border px-3 py-1.5 font-medium text-gray-700 disabled:opacity-40"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </div>
                        );
                    })()}
                </div>

                {/* MODAL */}
                {isModalOpen && (
                    <>
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className={`flex max-h-[90vh] w-full ${formStep === 2 ? 'max-w-6xl' : 'max-w-2xl'} flex-col overflow-hidden rounded-lg bg-white shadow-xl`}>
                            <div className="flex shrink-0 items-center justify-between bg-[#3f6f3d] px-6 py-4 text-white">
                                <h3 className="text-lg font-bold">
                                    {formStep === 1
                                        ? 'Issue New Certificate'
                                        : 'Full-Design Certificate Preview'}
                                </h3>
                                <button
                                    onClick={() => {
                                        setIsModalOpen(false);
                                        setScanError(null);
                                    }}
                                    className="text-xl font-bold text-white/80 hover:text-white"
                                >
                                    &times;
                                </button>
                            </div>

                            <div className="flex-1 overflow-y-auto">
                                {formStep === 1 ? (
                                    /* STEP 1: FORM ENTRY */
                                    <>
                                        <div className="flex border-b bg-gray-50">
                                            <button
                                                type="button"
                                                onClick={() => switchMode('qr')}
                                                className={`flex-1 border-b-2 py-3 text-sm font-medium ${modalMode === 'qr' ? 'border-[#3f6f3d] bg-white text-[#3f6f3d]' : 'border-transparent text-gray-500'}`}
                                            >
                                                Scan QR
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => switchMode('manual')}
                                                className={`flex-1 border-b-2 py-3 text-sm font-medium ${modalMode === 'manual' ? 'border-[#3f6f3d] bg-white text-[#3f6f3d]' : 'border-transparent text-gray-500'}`}
                                            >
                                                Manual Entry
                                            </button>
                                        </div>

                                        <form
                                            onSubmit={handleProceedToReview}
                                            className="space-y-4 p-6"
                                        >
                                            {modalMode === 'qr' ? (
                                                <div className="space-y-4">
                                                    <div className="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-6">
                                                        <p className="mb-1 text-center text-sm text-gray-600">
                                                            Point the handheld
                                                            2D QR scanner at the
                                                            resident's
                                                            KaBarangay QR code.
                                                            The scanner enters
                                                            the code
                                                            automatically.
                                                        </p>
                                                        <p className="mb-3 text-center text-xs text-gray-500">
                                                            Scan the QR code —
                                                            do not type or paste
                                                            into this field.
                                                        </p>
                                                        <QrScanInput
                                                            onScanned={
                                                                handleQRCodeScanned
                                                            }
                                                            onEmpty={
                                                                handleEmptyScan
                                                            }
                                                            loading={
                                                                scanLoading
                                                            }
                                                            focusSignal={
                                                                qrFocusSignal
                                                            }
                                                        />
                                                    </div>

                                                    {scanError && (
                                                        <div
                                                            className="flex items-center justify-between gap-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                                                            role="alert"
                                                        >
                                                            <span>
                                                                {scanError}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setScanError(
                                                                        null,
                                                                    );
                                                                    setQrFocusSignal(
                                                                        (
                                                                            signal,
                                                                        ) =>
                                                                            signal +
                                                                            1,
                                                                    );
                                                                }}
                                                                className="shrink-0 rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                                                            >
                                                                Try again
                                                            </button>
                                                        </div>
                                                    )}

                                                    {residentSource === 'qr' &&
                                                        data.resident_name !==
                                                            '' && (
                                                            <div className="rounded-md border border-green-200 bg-green-50 px-3 py-3 text-sm text-green-800">
                                                                <div className="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <span className="inline-flex items-center rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                                            QR
                                                                            VERIFIED
                                                                        </span>
                                                                        <p className="mt-1 font-semibold">
                                                                            {
                                                                                data.resident_name
                                                                            }
                                                                        </p>
                                                                        <p className="text-xs text-green-700">
                                                                            Resident
                                                                            ID:{' '}
                                                                            {
                                                                                data.resident_id
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => {
                                                                            setScanError(
                                                                                null,
                                                                            );
                                                                            clearResidentIdentity();
                                                                            setQrFocusSignal(
                                                                                (
                                                                                    signal,
                                                                                ) =>
                                                                                    signal +
                                                                                    1,
                                                                            );
                                                                        }}
                                                                        className="shrink-0 rounded-md border border-green-300 bg-white px-3 py-1.5 text-xs font-medium text-green-800 hover:bg-green-100"
                                                                    >
                                                                        {
                                                                            'Scan another'
                                                                        }
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        )}

                                                    <div className="grid grid-cols-2 gap-4">
                                                        {/* The Residency template prints no control number,
                                                            so the field is not shown for that certificate
                                                            type. */}
                                                        {data.certificate_type !==
                                                            RESIDENCY_TYPE && (
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                    Control No.
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.control_no
                                                                    }
                                                                    readOnly
                                                                    className="w-full rounded-md border bg-gray-100 px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                        )}
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                Resident Name
                                                            </label>
                                                            <input
                                                                type="text"
                                                                value={
                                                                    data.resident_name
                                                                }
                                                                readOnly
                                                                placeholder="Scan a resident QR code to fill this"
                                                                className="w-full rounded-md border bg-gray-100 px-3 py-2 text-sm"
                                                            />
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                            Certificate Type
                                                        </label>
                                                        <select
                                                            value={
                                                                data.certificate_type
                                                            }
                                                            onChange={(e) =>
                                                                handleCertificateTypeChange(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border bg-white px-3 py-2 text-sm"
                                                        >
                                                            {certificateTypes.length ===
                                                                0 && (
                                                                <option value="">
                                                                    No
                                                                    certificate
                                                                    templates
                                                                    available
                                                                </option>
                                                            )}
                                                            {certificateTypes.map(
                                                                (type) => (
                                                                    <option
                                                                        key={
                                                                            type.value
                                                                        }
                                                                        value={
                                                                            type.value
                                                                        }
                                                                        disabled={
                                                                            !type.available
                                                                        }
                                                                    >
                                                                        {type.available
                                                                            ? type.value
                                                                            : `${type.value} (Coming Soon)`}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE &&
                                                        renderDelayedPsaFields()}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE &&
                                                        renderMarriageFields()}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE &&
                                                        renderResidencyFields()}

                                                    {(data.certificate_type ===
                                                        'Tricycle' ||
                                                        data.certificate_type ===
                                                            'Sari-sari Store') && (
                                                        <div className="space-y-3 rounded-lg border border-orange-100 bg-orange-50 p-4">
                                                            <h4 className="text-xs font-bold text-orange-800 uppercase">
                                                                Business &
                                                                Franchise
                                                                Details
                                                            </h4>
                                                            {data.certificate_type ===
                                                                'Tricycle' && (
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Franchise
                                                                        No.
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.franchise_no
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'franchise_no',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        required
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                            )}
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                    Business
                                                                    Name
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.business_name
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setData(
                                                                            'business_name',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    required
                                                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                    Business
                                                                    Address
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.business_address
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setData(
                                                                            'business_address',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    required
                                                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        'Scholarship Certificate' && (
                                                        <div className="space-y-3 rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                                                            <h4 className="text-xs font-bold text-emerald-800 uppercase">
                                                                Scholarship &
                                                                Family Details
                                                            </h4>
                                                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                                <div className="sm:col-span-2">
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        School /
                                                                        Institution
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.school_institution
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'school_institution',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Name
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.father_name
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_name',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Occupation
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.father_occupation
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_occupation',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Monthly
                                                                        Income
                                                                    </label>
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        inputMode="decimal"
                                                                        value={
                                                                            data.father_monthly_income
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_monthly_income',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Name
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.mother_name
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_name',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Occupation
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.mother_occupation
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_occupation',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Monthly
                                                                        Income
                                                                    </label>
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        inputMode="decimal"
                                                                        value={
                                                                            data.mother_monthly_income
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_monthly_income',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}

                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                Fee (₱)
                                                            </label>
                                                            <input
                                                                type="text"
                                                                value={data.fee}
                                                                readOnly
                                                                className="w-full rounded-md border bg-gray-100 px-3 py-2 text-sm"
                                                            />
                                                        </div>
                                                        {data.certificate_type !==
                                                            DELAYED_PSA_TYPE && (
                                                            <div>
                                                                {/* The Marriage Cohabitation and the Residency
                                                                    templates state their own purpose, so the
                                                                    generic purpose input is not collected for
                                                                    those certificate types. */}
                                                                {data.certificate_type !==
                                                                    MARRIAGE_TYPE &&
                                                                    (!isResidency) && (
                                                                        <>
                                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                                Purpose
                                                                            </label>
                                                                            <input
                                                                                type="text"
                                                                                value={
                                                                                    data.purpose
                                                                                }
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    setData(
                                                                                        'purpose',
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                required
                                                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                                            />
                                                                        </>
                                                                    )}
                                                            </div>
                                                        )}
                                                    </div>

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE && (
                                                        <div className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    DELAYED_PSA_PURPOSE
                                                                }
                                                            </strong>
                                                            .
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE && (
                                                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    MARRIAGE_PURPOSE
                                                                }
                                                            </strong>
                                                            .
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE && (
                                                        <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    RESIDENCY_PURPOSE
                                                                }
                                                            </strong>
                                                        </div>
                                                    )}

                                                    {data.certificate_type !== '' && (
                                                        <div className="rounded-lg border border-blue-100 bg-blue-50 p-4">
                                                            <>
                                                                        <label className="mb-1 block text-xs font-semibold text-blue-800 uppercase">
                                                                            Signatory
                                                                        </label>
                                                                        <select
                                                                            required
                                                                            value={signatoryOptionValue(
                                                                                data.signatory_name,
                                                                                data.signatory_title,
                                                                            )}
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                handleSignatoryChange(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            className="w-full rounded-md border bg-white px-3 py-2 text-sm"
                                                                        >
                                                                            <option value="">
                                                                                Select
                                                                                Signatory...
                                                                            </option>
            {signatories.map(
                                                                                (
                                                                                    sig,
                                                                                    idx,
                                                                                ) => (
                                                                                    <option
                                                                                        key={
                                                                                            idx
                                                                                        }
                                                                                        value={signatoryOptionValue(
                                                                                            sig.name,
                                                                                            sig.title,
                                                                                        )}
                                                                                    >
                                                                                        {
                                                                                            sig.name
                                                                                        }{' '}
                                                                                        -{' '}
                                                                                        {
                                                                                            sig.title
                                                                                        }
                                                                                    </option>
                                                                                ),
                                                                            )}
                                                                        </select>
                                                            </>
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE && (
                                                        <div className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE && (
                                                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE && (
                                                        <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <div className="space-y-4">
                                                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                        <span className="inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">
                                                            MANUAL ENTRY
                                                        </span>
                                                        <span className="ml-2">
                                                            Enter the resident's
                                                            details below. This
                                                            information is not
                                                            QR-verified.
                                                        </span>
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-4">
                                                        {/* The Residency template prints no control number,
                                                            so the field is not shown for that certificate
                                                            type. */}
                                                        {data.certificate_type !==
                                                            RESIDENCY_TYPE && (
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                    Control No.
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.control_no
                                                                    }
                                                                    readOnly
                                                                    className="w-full rounded-md border bg-gray-100 px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                        )}
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                Resident Name
                                                            </label>
                                                            <input
                                                                type="text"
                                                                value={
                                                                    data.resident_name
                                                                }
                                                                onChange={(
                                                                    e,
                                                                ) => {
                                                                    setData(
                                                                        'resident_name',
                                                                        e.target
                                                                            .value,
                                                                    );
                                                                    setResidentSource(
                                                                        'manual',
                                                                    );
                                                                }}
                                                                required
                                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                            />
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                            Certificate Type
                                                        </label>
                                                        <select
                                                            value={
                                                                data.certificate_type
                                                            }
                                                            onChange={(e) =>
                                                                handleCertificateTypeChange(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="w-full rounded-md border bg-white px-3 py-2 text-sm"
                                                        >
                                                            {certificateTypes.length ===
                                                                0 && (
                                                                <option value="">
                                                                    No
                                                                    certificate
                                                                    templates
                                                                    available
                                                                </option>
                                                            )}
                                                            {certificateTypes.map(
                                                                (type) => (
                                                                    <option
                                                                        key={
                                                                            type.value
                                                                        }
                                                                        value={
                                                                            type.value
                                                                        }
                                                                        disabled={
                                                                            !type.available
                                                                        }
                                                                    >
                                                                        {type.available
                                                                            ? type.value
                                                                            : `${type.value} (Coming Soon)`}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE &&
                                                        renderDelayedPsaFields()}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE &&
                                                        renderMarriageFields()}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE &&
                                                        renderResidencyFields()}

                                                    {(data.certificate_type ===
                                                        'Tricycle' ||
                                                        data.certificate_type ===
                                                            'Sari-sari Store') && (
                                                        <div className="space-y-3 rounded-lg border border-orange-100 bg-orange-50 p-4">
                                                            <h4 className="text-xs font-bold text-orange-800 uppercase">
                                                                Business &
                                                                Franchise
                                                                Details
                                                            </h4>
                                                            {data.certificate_type ===
                                                                'Tricycle' && (
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Franchise
                                                                        No.
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.franchise_no
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'franchise_no',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        required
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                            )}
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                    Business
                                                                    Name
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.business_name
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setData(
                                                                            'business_name',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    required
                                                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                            <div>
                                                                <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                    Business
                                                                    Address
                                                                </label>
                                                                <input
                                                                    type="text"
                                                                    value={
                                                                        data.business_address
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setData(
                                                                            'business_address',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    required
                                                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        'Scholarship Certificate' && (
                                                        <div className="space-y-3 rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                                                            <h4 className="text-xs font-bold text-emerald-800 uppercase">
                                                                Scholarship &
                                                                Family Details
                                                            </h4>
                                                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                                                <div className="sm:col-span-2">
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        School /
                                                                        Institution
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.school_institution
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'school_institution',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Name
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.father_name
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_name',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Occupation
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.father_occupation
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_occupation',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Father's
                                                                        Monthly
                                                                        Income
                                                                    </label>
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        inputMode="decimal"
                                                                        value={
                                                                            data.father_monthly_income
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'father_monthly_income',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Name
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.mother_name
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_name',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Occupation
                                                                    </label>
                                                                    <input
                                                                        type="text"
                                                                        value={
                                                                            data.mother_occupation
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_occupation',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="mb-1 block text-xs font-semibold text-gray-600">
                                                                        Mother's
                                                                        Monthly
                                                                        Income
                                                                    </label>
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        inputMode="decimal"
                                                                        value={
                                                                            data.mother_monthly_income
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setData(
                                                                                'mother_monthly_income',
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="w-full rounded-md border px-3 py-2 text-sm"
                                                                    />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}

                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                Fee (₱)
                                                            </label>
                                                            <input
                                                                type="text"
                                                                value={data.fee}
                                                                readOnly
                                                                className="w-full rounded-md border bg-gray-100 px-3 py-2 text-sm"
                                                            />
                                                        </div>
                                                        {data.certificate_type !==
                                                            DELAYED_PSA_TYPE && (
                                                            <div>
                                                                {/* The Marriage Cohabitation and the Residency
                                                                    templates state their own purpose, so the
                                                                    generic purpose input is not collected for
                                                                    those certificate types. */}
                                                                {data.certificate_type !==
                                                                    MARRIAGE_TYPE &&
                                                                    (!isResidency) && (
                                                                        <>
                                                                            <label className="mb-1 block text-xs font-semibold text-gray-600 uppercase">
                                                                                Purpose
                                                                            </label>
                                                                            <input
                                                                                type="text"
                                                                                value={
                                                                                    data.purpose
                                                                                }
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    setData(
                                                                                        'purpose',
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                required
                                                                                className="w-full rounded-md border px-3 py-2 text-sm"
                                                                            />
                                                                        </>
                                                                    )}
                                                            </div>
                                                        )}
                                                    </div>

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE && (
                                                        <div className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    DELAYED_PSA_PURPOSE
                                                                }
                                                            </strong>
                                                            .
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE && (
                                                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    MARRIAGE_PURPOSE
                                                                }
                                                            </strong>
                                                            .
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE && (
                                                        <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-900">
                                                            The purpose of this
                                                            certificate is fixed
                                                            by the template:{' '}
                                                            <strong>
                                                                {
                                                                    RESIDENCY_PURPOSE
                                                                }
                                                            </strong>
                                                        </div>
                                                    )}

                                                    {data.certificate_type !== '' && (
                                                        <div className="rounded-lg border border-blue-100 bg-blue-50 p-4">
                                                            <>
                                                                        <label className="mb-1 block text-xs font-semibold text-blue-800 uppercase">
                                                                            Signatory
                                                                        </label>
                                                                        <select
                                                                            required
                                                                            value={signatoryOptionValue(
                                                                                data.signatory_name,
                                                                                data.signatory_title,
                                                                            )}
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                handleSignatoryChange(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            className="w-full rounded-md border bg-white px-3 py-2 text-sm"
                                                                        >
                                                                            <option value="">
                                                                                Select
                                                                                Signatory...
                                                                            </option>
            {signatories.map(
                                                                                (
                                                                                    sig,
                                                                                    idx,
                                                                                ) => (
                                                                                    <option
                                                                                        key={
                                                                                            idx
                                                                                        }
                                                                                        value={signatoryOptionValue(
                                                                                            sig.name,
                                                                                            sig.title,
                                                                                        )}
                                                                                    >
                                                                                        {
                                                                                            sig.name
                                                                                        }{' '}
                                                                                        -{' '}
                                                                                        {
                                                                                            sig.title
                                                                                        }
                                                                                    </option>
                                                                                ),
                                                                            )}
                                                                        </select>
                                                            </>
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        DELAYED_PSA_TYPE && (
                                                        <div className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        MARRIAGE_TYPE && (
                                                        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}

                                                    {data.certificate_type ===
                                                        RESIDENCY_TYPE && (
                                                        <div className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-900">
                                                            Select the signatory for this certificate from the published Barangay Organizational Chart.
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {!isDelayedPsa && (
                                                <div>
                                                    <label htmlFor="certificate-purok" className="mb-1 block text-xs font-semibold text-gray-600 uppercase">Purok</label>
                                                    <input id="certificate-purok" name="purok" type="text" value={data.purok}
                                                        onChange={(event) => setData('purok', event.target.value)}
                                                        readOnly={data.resident_id !== ''}
                                                        placeholder="e.g. Purok Bagong Pag-asa" maxLength={255}
                                                        className="w-full rounded-md border px-3 py-2 text-sm read-only:bg-gray-100" />
                                                    <p className="mt-1 text-xs text-gray-500">Saved with the issued certificate. Linked residents use their profile purok. Templates support {'{PUROK}'} and {'{RESIDENT_PUROK}'}.</p>
                                                </div>
                                            )}
                                            <div className="mt-4 flex justify-end border-t pt-4">
                                                <button
                                                    type="submit"
                                                    className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#315b30]"
                                                >
                                                    Preview Certificate Design
                                                    &rarr;
                                                </button>
                                            </div>
                                        </form>
                                    </>
                                ) : (
                                    /* STEP 2: THE GENERATED TEMPLATE, INCLUDING ITS ORIGINAL ARTWORK */
                                    <div className="bg-gray-100 p-6">
                                        <div className="mx-auto mb-4 max-w-[500px]">
                                            {residentSource === 'qr' ? (
                                                <div className="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
                                                    <span className="inline-flex items-center rounded-full bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">
                                                        QR VERIFIED
                                                    </span>
                                                    <span className="ml-2">
                                                        Resident identity was
                                                        confirmed by scanning a
                                                        KaBarangay QR code.
                                                    </span>
                                                </div>
                                            ) : (
                                                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                    <span className="inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">
                                                        MANUAL ENTRY
                                                    </span>
                                                    <span className="ml-2">
                                                        Resident details were
                                                        entered manually and are
                                                        not QR-verified.
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                        <CertificateDocumentPreview
                                            body={new URLSearchParams({ ...data, details_confirmed: detailsConfirmed ? '1' : '' }).toString()}
                                            onReady={setDocumentPreviewReady}
                                        />

                                        <div className="mt-6 flex items-center justify-between">
                                            <button
                                                type="button"
                                                onClick={() => setFormStep(1)}
                                                disabled={generationLoading}
                                                className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 shadow-sm hover:bg-gray-50"
                                            >
                                                &larr; Back to Edit
                                            </button>
                                            <div className="flex gap-2">
                                                <button type="button" onClick={() => void handleFinalSubmit('print')}
                                                    disabled={!documentPreviewReady || generationLoading || generationOutcomeUnknown || !canIssueCertificate}
                                                    className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50">Print Certificate</button>
                                                <button
                                                    type="button"
                                                    onClick={() => void handleFinalSubmit('download')}
                                                    disabled={processing || generationLoading || generationOutcomeUnknown || !canIssueCertificate}
                                                    className={`rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 ${processing || generationLoading || generationOutcomeUnknown || !canIssueCertificate ? 'cursor-not-allowed opacity-50' : ''}`}
                                                >
                                                    {generationLoading ? 'Generating & downloading...' : 'Confirm & Download Original.docx'}
                                                </button>
                                            </div>
                                        </div>
                                        {generationError && (
                                            <p role="alert" className={`mt-3 rounded-md border px-3 py-2 text-sm ${generationOutcomeUnknown ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-red-200 bg-red-50 text-red-800'}`}>
                                                {generationError}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                    </>
                )}

                {requestDetailsOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                            <div className="flex shrink-0 items-center justify-between bg-[#3f6f3d] px-6 py-4 text-white">
                                <h3 className="text-lg font-bold">
                                    Certificate Request Details
                                </h3>
                                <button
                                    onClick={closeRequestDetails}
                                    className="text-xl font-bold text-white/80 hover:text-white"
                                    aria-label="Close request details"
                                >
                                    &times;
                                </button>
                            </div>

                            <div className="flex-1 overflow-y-auto p-6">
                                {requestDetailsLoading ? (
                                    <p className="py-8 text-center text-sm text-[#8a7f6a]">
                                        Loading request details...
                                    </p>
                                ) : requestDetailsError ? (
                                    <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                        {requestDetailsError}
                                    </div>
                                ) : requestDetails ? (
                                    <div className="space-y-5">
                                        <div className="grid gap-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <p className="text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Reference
                                                </p>
                                                <p className="font-mono font-medium text-gray-800">
                                                    {requestDetails.reference}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Certificate
                                                </p>
                                                <p className="font-medium text-gray-800">
                                                    {requestDetails.certificate}
                                                </p>
                                                {!requestDetails.template_available && (
                                                    <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                                        This active catalog service has no provisioned DOCX template yet. The request remains available for review, but issuance cannot be generated until a template is available.
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Resident
                                                </p>
                                                <p className="font-medium text-gray-800">
                                                    {
                                                        requestDetails.resident
                                                            .name
                                                    }
                                                </p>
                                                <p className="text-xs text-[#8a7f6a]">
                                                    {requestDetails.resident
                                                        .resident_number ?? '—'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Purpose
                                                </p>
                                                <p className="text-gray-800">
                                                    {requestDetails.purpose ||
                                                        '—'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Fee
                                                </p>
                                                <p className="text-gray-800">
                                                    ₱{requestDetails.fee}
                                                </p>
                                            </div>

                                            {requestDetails.issuance && (
                                                <div className="rounded-md border border-teal-200 bg-teal-50 p-2.5 sm:col-span-2">
                                                    <p className="text-[10px] font-semibold tracking-wide text-teal-800 uppercase">
                                                        Certificate Issued
                                                    </p>
                                                    <p className="text-xs font-medium text-teal-900">
                                                        Reference: <span className="font-mono">{requestDetails.issuance.reference}</span>
                                                    </p>
                                                    <p className="text-[11px] text-teal-700">
                                                        Issued on {requestDetails.issuance.issued_at}
                                                    </p>
                                                </div>
                                            )}
                                        </div>

                                        {(requestDetails.business_name ||
                                            requestDetails.business_address) && (
                                            <div className="rounded-md border border-[#e6ddcd] bg-[#faf8f2] p-3">
                                                <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Business Details
                                                </p>
                                                <p className="text-sm text-gray-800">
                                                    {requestDetails.business_name ||
                                                        '—'}
                                                </p>
                                                <p className="text-sm text-gray-600">
                                                    {requestDetails.business_address ||
                                                        '—'}
                                                </p>
                                            </div>
                                        )}

                                        {requestActionError && (
                                            <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                                {requestActionError}
                                            </div>
                                        )}

                                        {requestActionSuccess && (
                                            <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                                {requestActionSuccess}
                                            </div>
                                        )}

                                        {/* WORKFLOW ACTIONS */}
                                        <div className="rounded-md border border-[#3f6f3d]/30 bg-[#f7f5ee] p-3.5 shadow-sm">
                                            <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#3f6f3d] uppercase">
                                                Workflow Action
                                            </p>

                                            {requestDetails.status === 'Pending' && (
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <p className="text-xs text-gray-700">
                                                        This request is pending review. Start processing to review details and issue the certificate.
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={() => void handleStartProcessing()}
                                                        disabled={actionLoading}
                                                        className="inline-flex shrink-0 items-center justify-center rounded-md bg-[#3f6f3d] px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {actionLoading ? 'Updating...' : 'Start Processing'}
                                                    </button>
                                                </div>
                                            )}

                                            {(requestDetails.status === 'Processing' || requestDetails.status === 'Needs Action') && !requestDetails.issuance && (
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <p className="text-xs text-gray-700">
                                                        Review certificate details below, then generate and issue the official certificate.
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleInitiateIssuance(requestDetails)}
                                                        className="inline-flex shrink-0 items-center justify-center rounded-md bg-blue-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-blue-700"
                                                    >
                                                        Issue Certificate
                                                    </button>
                                                </div>
                                            )}

                                            {requestDetails.status === 'For Payment' && (
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p className="text-xs font-medium text-gray-800">
                                                            Fee Due: ₱{requestDetails.fee}
                                                        </p>
                                                        <p className="text-[11px] text-gray-600">
                                                            Confirm payment once collected from resident to mark ready for claim.
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => void handleConfirmPayment()}
                                                        disabled={actionLoading}
                                                        className="inline-flex shrink-0 items-center justify-center rounded-md bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        {actionLoading ? 'Confirming...' : 'Confirm Payment Received'}
                                                    </button>
                                                </div>
                                            )}

                                            {requestDetails.status === 'Ready for Claim' && (
                                                <div className="space-y-2">
                                                    <p className="text-xs text-gray-700">
                                                        Certificate has been issued and is ready for physical release to resident.
                                                    </p>
                                                    {!showReleaseConfirm ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowReleaseConfirm(true)}
                                                            className="inline-flex items-center justify-center rounded-md bg-[#3f6f3d] px-3.5 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-[#315b30]"
                                                        >
                                                            Confirm Physical Handover (Release)
                                                        </button>
                                                    ) : (
                                                        <div className="rounded-md border border-amber-300 bg-amber-50 p-2.5 space-y-2">
                                                            <p className="text-xs font-medium text-amber-900">
                                                                Confirm that the physical certificate was handed over to the resident?
                                                            </p>
                                                            <div className="flex gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => void handleConfirmRelease()}
                                                                    disabled={actionLoading}
                                                                    className="rounded bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-60"
                                                                >
                                                                    {actionLoading ? 'Releasing...' : 'Yes, Confirm Handover'}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setShowReleaseConfirm(false)}
                                                                    className="rounded border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                                                >
                                                                    Cancel
                                                                </button>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            )}

                                            {requestDetails.status === 'Released' && (
                                                <p className="text-xs font-medium text-green-800">
                                                    ✓ Certificate released and handed over to resident. Lifecycle is complete.
                                                </p>
                                            )}

                                            {requestDetails.status === 'Rejected' && (
                                                <p className="text-xs font-medium text-red-800">
                                                    ✕ This certificate request was rejected.
                                                </p>
                                            )}
                                        </div>

                                        <div className="rounded-md border border-[#e6ddcd] bg-[#faf8f2] p-3">
                                            <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                Processing Status
                                            </p>
                                            <div className="space-y-2">
                                                <select
                                                    aria-label="Certificate request status"
                                                    value={statusDraft}
                                                    onChange={(event) =>
                                                        setStatusDraft(
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="w-full rounded-md border border-[#d8cdb8] bg-white px-3 py-2 text-sm outline-none focus:border-[#3f6f3d]"
                                                >
                                                    {requestDetails.allowed_statuses.map(
                                                        (status) => (
                                                            <option
                                                                key={status}
                                                                value={status}
                                                            >
                                                                {status}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <textarea
                                                    rows={2}
                                                    aria-label="Note shown to the resident"
                                                    value={adminNoteDraft}
                                                    onChange={(event) =>
                                                        setAdminNoteDraft(
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder={
                                                        statusDraft ===
                                                        'Rejected'
                                                            ? 'Reason for rejection (required)'
                                                            : 'Optional note shown to the resident'
                                                    }
                                                    className="w-full rounded-md border border-[#d8cdb8] bg-white px-3 py-2 text-sm outline-none focus:border-[#3f6f3d]"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        void saveRequestStatus()
                                                    }
                                                    disabled={
                                                        savingStatus ||
                                                        statusDraft === ''
                                                    }
                                                    className="rounded-md bg-[#3f6f3d] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {savingStatus
                                                        ? 'Saving...'
                                                        : 'Save Status'}
                                                </button>
                                            </div>
                                        </div>

                                        {requestDetails.details_fields.length >
                                        0 ? (
                                            <div>
                                                <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Reviewed Certificate Details
                                                </p>
                                                <div className="grid gap-3 rounded-md border border-[#e6ddcd] p-3 sm:grid-cols-2">
                                                    {requestDetails.details_fields.map(
                                                        (field) => (
                                                            <label
                                                                key={field.key}
                                                                className="text-xs font-medium text-[#8a7f6a]"
                                                            >
                                                                {field.label}
                                                                <input
                                                                    type={
                                                                        field.type
                                                                    }
                                                                    min={
                                                                        field.min
                                                                    }
                                                                    max={
                                                                        field.max
                                                                    }
                                                                    value={
                                                                        detailDraft[
                                                                            field
                                                                                .key
                                                                        ] ?? ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setDetailDraft(
                                                                            (
                                                                                current,
                                                                            ) => ({
                                                                                ...current,
                                                                                [field.key]:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            }),
                                                                        )
                                                                    }
                                                                    className="mt-1 w-full rounded-md border border-[#d8cdb8] bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-[#3f6f3d]"
                                                                />
                                                            </label>
                                                        ),
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        void saveRequestDetails()
                                                    }
                                                    disabled={savingDetails}
                                                    className="mt-2 rounded-md bg-[#3f6f3d] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    {savingDetails
                                                        ? 'Saving...'
                                                        : 'Save Details'}
                                                </button>

                                                {requestDetailsWereRevised &&
                                                    requestDetails
                                                        .original_details
                                                        .length > 0 && (
                                                        <div className="mt-3 rounded-md border border-[#e6ddcd] bg-[#faf8f2] p-3">
                                                            <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                                Originally
                                                                Submitted
                                                            </p>
                                                            <div className="divide-y divide-[#f0ebe1]">
                                                                {requestDetails.original_details.map(
                                                                    (
                                                                        detail,
                                                                    ) => (
                                                                        <div
                                                                            key={
                                                                                detail.label
                                                                            }
                                                                            className="grid gap-1 py-1.5 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]"
                                                                        >
                                                                            <span className="text-[#8a7f6a]">
                                                                                {
                                                                                    detail.label
                                                                                }
                                                                            </span>
                                                                            <span className="text-gray-800">
                                                                                {
                                                                                    detail.value
                                                                                }
                                                                            </span>
                                                                        </div>
                                                                    ),
                                                                )}
                                                            </div>
                                                        </div>
                                                    )}
                                            </div>
                                        ) : requestDetails.details.length > 0 ? (
                                            <div>
                                                <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Certificate Details
                                                </p>
                                                <div className="divide-y divide-[#f0ebe1] rounded-md border border-[#e6ddcd]">
                                                    {requestDetails.details.map(
                                                        (detail) => (
                                                            <div
                                                                key={
                                                                    detail.label
                                                                }
                                                                className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]"
                                                            >
                                                                <span className="text-[#8a7f6a]">
                                                                    {
                                                                        detail.label
                                                                    }
                                                                </span>
                                                                <span className="font-medium text-gray-800">
                                                                    {
                                                                        detail.value
                                                                    }
                                                                </span>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        ) : null}

                                        {requestDetails.notes && (
                                            <div>
                                                <p className="mb-2 text-[10px] font-semibold tracking-wide text-[#8a7f6a] uppercase">
                                                    Resident Notes
                                                </p>
                                                <p className="rounded-md border border-[#e6ddcd] bg-[#faf8f2] p-3 text-sm whitespace-pre-wrap text-gray-700">
                                                    {requestDetails.notes}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ClerkLayout>
    );
}
