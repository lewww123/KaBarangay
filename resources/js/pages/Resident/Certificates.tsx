import { Deferred, Head, router } from '@inertiajs/react';
import {
    FormEvent,
    useEffect,
    useMemo,
    useState,
    useSyncExternalStore,
} from 'react';
import Pagination from '@/components/Pagination';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import {
    UNSYNCED_STATUSES,
    offlineCertificateStore,
    prepareOfflineCertificates,
    queueOfflineCertificateRequest,
    type LocalCertificateRequest,
} from '@/lib/offlineCertificates';
import {
    readConnectivity,
    subscribeConnectivity,
} from '@/lib/offlineConnectivity';
import type { PaginatedData } from '@/types';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type Tab = 'request' | 'requests';

type Step = 'form' | 'review' | 'success' | 'queued';

type RequestStatus =
    | 'Pending'
    | 'Needs Action'
    | 'For Payment'
    | 'Processing'
    | 'Ready for Claim'
    | 'Released'
    | 'Rejected';

type CertificateField = {
    key: string;
    label: string;
    type: 'text' | 'number';
    kind: 'text' | 'integer' | 'numeric';
    required: boolean;
    min?: number;
    max?: number;
    default?: string;
    placeholder?: string;
};

type Certificate = {
    id: string;
    name: string;
    fee: number;
    description: string;
    requirements: string[];
    requiresBusinessDetails?: boolean;
    certificateType?: string | null;
    fixedPurpose?: string | null;
    detailsFields?: CertificateField[];
    available: boolean;
    templateAvailable: boolean;
};

type CertificateRequestDetailsRow = {
    label: string;
    value: string;
};

type CertificateRequest = {
    id: number;
    reference: string;
    certificate: string;
    purpose: string;
    fee: number;
    status: RequestStatus;
    date: string;
    businessName?: string;
    businessAddress?: string;
    additionalDetails?: string;
    details?: CertificateRequestDetailsRow[];
    adminNote?: string | null;
};

interface PageProps {
    resident: {
        id: number;
        full_name: string;
        resident_number: string;
        birthdate: string | null;
        sex: string | null;
        purok: string | null;
        barangay: {
            id: number;
            name: string;
            city_municipality: string;
            province: string;
        };
    };
    certificates: Certificate[];
    requests: PaginatedData<CertificateRequest>;
    errors: Record<string, string>;
}

const CERTIFICATE_FLOW_KEY = 'kabarangay_certificate_flow';

function defaultDetails(certificate?: Certificate): Record<string, string> {
    const values: Record<string, string> = {};

    certificate?.detailsFields?.forEach((field) => {
        values[field.key] = field.default ?? '';
    });

    return values;
}

function formatDisplayDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-PH', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
}

/*
|--------------------------------------------------------------------------
| Main Page
|--------------------------------------------------------------------------
*/

export default function Certificates(props: PageProps) {
    return (
        <Deferred
            data={['resident', 'certificates', 'requests']}
            fallback={
                <ResidentLayout active="certificates">
                    <PortalPageSkeleton showTabs />
                </ResidentLayout>
            }
        >
            <CertificatesContent {...props} />
        </Deferred>
    );
}

function CertificatesContent({
    resident,
    certificates,
    requests,
    errors,
}: PageProps) {
    const [activeTab, setActiveTab] = useState<Tab>('request');
    const [step, setStep] = useState<Step>('form');

    // Form State
    const [certificateId, setCertificateId] = useState('');
    const [purpose, setPurpose] = useState('');
    const [otherPurpose, setOtherPurpose] = useState('');
    const [additionalDetails, setAdditionalDetails] = useState('');
    const [businessName, setBusinessName] = useState('');
    const [businessAddress, setBusinessAddress] = useState('');
    const [details, setDetails] = useState<Record<string, string>>({});

    // UI State
    const [submittedRequest, setSubmittedRequest] =
        useState<CertificateRequest | null>(null);
    const [selectedRequest, setSelectedRequest] =
        useState<CertificateRequest | null>(null);
    const [cameFromAssistant, setCameFromAssistant] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [draftMessage, setDraftMessage] = useState('');
    const [localRequests, setLocalRequests] = useState<
        LocalCertificateRequest[]
    >([]);
    const { online, degraded } = useSyncExternalStore(
        subscribeConnectivity,
        readConnectivity,
        readConnectivity,
    );
    const offlineForm = () => ({
        certificate_id: (selectedCertificate as Certificate).id,
        purpose: effectivePurpose,
        business_name: businessName,
        business_address: businessAddress,
        additional_details: additionalDetails,
        details,
    });
    const refreshLocalRequests = () => {
        offlineCertificateStore()
            .then((store) => store.list())
            .then((items) =>
                setLocalRequests(
                    items.filter((item) =>
                        UNSYNCED_STATUSES.includes(item.status),
                    ),
                ),
            )
            .catch(() => setLocalRequests([]));
    };
    useEffect(() => {
        refreshLocalRequests();
        window.addEventListener('kb-certificate-queue', refreshLocalRequests);
        return () =>
            window.removeEventListener(
                'kb-certificate-queue',
                refreshLocalRequests,
            );
        // Re-read the queue whenever connectivity flips, and after each page render.
    }, [online]);
    useEffect(() => {
        if (activeTab !== 'requests') return;
        refreshLocalRequests();
    }, [activeTab, requests]);
    const saveOfflineDraft = async () => {
        if (!selectedCertificate || processing) return;
        setProcessing(true);
        try {
            let store = await offlineCertificateStore();
            try {
                await store.context();
            } catch {
                store = await prepareOfflineCertificates();
            }
            await store.saveDraft(offlineForm());
            setDraftMessage(
                'Draft saved on this device. It is not submitted until you confirm it and the Barangay receives it.',
            );
        } catch (error) {
            setDraftMessage(
                error instanceof Error
                    ? error.message
                    : 'Unable to save this draft.',
            );
        } finally {
            setProcessing(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Load Certificate Selected From KaBarangay Assistant
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        try {
            const savedFlow = window.localStorage.getItem(CERTIFICATE_FLOW_KEY);
            if (!savedFlow) return;

            const parsed = JSON.parse(savedFlow) as {
                certificateId?: string;
                source?: string;
            };

            if (parsed.source !== 'chatbot' || !parsed.certificateId) return;

            const certificate = certificates.find(
                (item) => item.id === parsed.certificateId,
            );

            if (!certificate?.available) {
                window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
                return;
            }

            setCertificateId(parsed.certificateId);
            setDetails(defaultDetails(certificate));
            setActiveTab('request');
            setStep('form');
            setCameFromAssistant(true);
        } catch (error) {
            console.error(
                'Unable to load certificate selected by KaBarangay Assistant.',
                error,
            );
        }
    }, [certificates]);

    /*
    |--------------------------------------------------------------------------
    | Selected Certificate
    |--------------------------------------------------------------------------
    */

    const selectedCertificate = useMemo(
        () =>
            certificates.find(
                (certificate) => certificate.id === certificateId,
            ),
        [certificateId, certificates],
    );

    const effectivePurpose =
        selectedCertificate?.fixedPurpose ??
        (purpose === 'Other' ? otherPurpose : purpose);

    const detailsFields = selectedCertificate?.detailsFields ?? [];

    const availableCertificates = certificates.filter(
        (certificate) => certificate.available,
    );

    const detailsComplete = detailsFields.every(
        (field) => !field.required || (details[field.key] ?? '').trim() !== '',
    );

    const handleCertificateChange = (nextCertificateId: string) => {
        const nextCertificate = certificates.find(
            (certificate) => certificate.id === nextCertificateId,
        );

        if (!nextCertificate?.available) {
            return;
        }

        setCertificateId(nextCertificateId);
        setDetails(defaultDetails(nextCertificate));
        setBusinessName('');
        setBusinessAddress('');
    };

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    */

    const resetForm = () => {
        setCertificateId('');
        setPurpose('');
        setOtherPurpose('');
        setAdditionalDetails('');
        setBusinessName('');
        setBusinessAddress('');
        setDetails({});
        setSubmittedRequest(null);
        setStep('form');
    };

    /*
    |--------------------------------------------------------------------------
    | Continue to Review
    |--------------------------------------------------------------------------
    */

    const handleContinue = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!selectedCertificate?.available || !effectivePurpose.trim()) return;
        if (
            !selectedCertificate.fixedPurpose &&
            purpose === 'Other' &&
            !otherPurpose.trim()
        )
            return;
        if (
            selectedCertificate.requiresBusinessDetails &&
            (!businessName.trim() || !businessAddress.trim())
        )
            return;
        if (!detailsComplete) return;

        setStep('review');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /*
    |--------------------------------------------------------------------------
    | Submit Request to Database
    |--------------------------------------------------------------------------
    */

    const queueOfflineRequest = async () => {
        setProcessing(true);
        try {
            await queueOfflineCertificateRequest(offlineForm());
            window.dispatchEvent(new Event('kb-certificate-queue'));
            try {
                window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
            } catch (error) {
                console.error(
                    'Unable to clear certificate assistant flow.',
                    error,
                );
            }
            setCameFromAssistant(false);
            setDraftMessage('');
            setStep('queued');
            refreshLocalRequests();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            setStep('review');
            setDraftMessage(
                error instanceof Error
                    ? error.message
                    : 'Unable to save this request on this device.',
            );
        } finally {
            setProcessing(false);
        }
    };

    const handleSubmitRequest = () => {
        if (!selectedCertificate?.available || processing) return;
        if (!online) {
            void queueOfflineRequest();
            return;
        }
        setProcessing(true);

        router.post(
            '/resident/certificates',
            {
                certificate_id: selectedCertificate.id,
                purpose: effectivePurpose,
                business_name: businessName || undefined,
                business_address: businessAddress || undefined,
                additional_details: additionalDetails || undefined,
                details,
            },
            {
                preserveScroll: true,
                onSuccess: (page: any) => {
                    // The newest request will be the first item in the newly loaded page.
                    const requests = page.props.requests as
                        | PaginatedData<CertificateRequest>
                        | undefined;
                    if (requests?.data.length) {
                        setSubmittedRequest(requests.data[0]);
                    }

                    try {
                        window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
                    } catch (error) {
                        console.error(
                            'Unable to clear certificate assistant flow.',
                            error,
                        );
                    }

                    setCameFromAssistant(false);
                    setStep('success');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                onError: () => {
                    // Return to form so user can see validation errors
                    setStep('form');
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    const unsyncedLocalRequests = localRequests.filter(
        (item) =>
            !requests.data.some(
                (request) => request.reference === item.data.result?.reference,
            ),
    );

    return (
        <>
            <Head title="Certificate Request" />

            <ResidentLayout active="certificates">
                {/* ======================================================
                    PAGE HEADER
                ====================================================== */}
                <section className="mx-auto mb-5 w-full max-w-4xl">
                    <h1 className="text-xl font-semibold tracking-tight text-ink-strong">
                        Certificate Request
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Request a barangay certificate or track your submitted
                        request.
                    </p>
                    <button
                        type="button"
                        onClick={() => setActiveTab('requests')}
                        className="mt-2 inline-block text-sm font-semibold text-brand underline underline-offset-2"
                    >
                        Offline drafts &amp; pending requests
                    </button>
                    {!online && (
                        <p className="mt-2 text-sm leading-6 text-amber-800">
                            {degraded
                                ? 'KaBarangay is reachable, but the barangay data service cannot read requests right now. You can still complete this request; it is saved on this device and sent automatically once the data service returns.'
                                : 'You are offline. You can still complete this request; it is saved on this device and sent automatically once your internet connection returns.'}
                        </p>
                    )}
                    {draftMessage && (
                        <p
                            role="status"
                            className="mt-3 rounded-lg border bg-white p-3 text-sm"
                        >
                            {draftMessage}
                        </p>
                    )}
                </section>

                {/* ======================================================
                    TABS
                ====================================================== */}
                <div className="mx-auto mb-5 flex w-full max-w-4xl">
                    <div
                        role="tablist"
                        className="inline-flex w-full max-w-xl gap-1 rounded-lg border border-line-soft bg-canvas p-1"
                    >
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'request'}
                            onClick={() => setActiveTab('request')}
                            className={`inline-flex h-8 flex-1 items-center justify-center rounded-md px-3 text-xs font-medium whitespace-nowrap transition-colors ${
                                activeTab === 'request'
                                    ? 'bg-surface text-ink-strong shadow-card'
                                    : 'text-ink-muted hover:text-ink-strong'
                            }`}
                        >
                            Request Certificate
                        </button>
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'requests'}
                            onClick={() => setActiveTab('requests')}
                            className={`inline-flex h-8 flex-1 items-center justify-center gap-1.5 rounded-md px-3 text-xs font-medium whitespace-nowrap transition-colors ${
                                activeTab === 'requests'
                                    ? 'bg-surface text-ink-strong shadow-card'
                                    : 'text-ink-muted hover:text-ink-strong'
                            }`}
                        >
                            My Requests
                            <span
                                className={`rounded-full px-1.5 py-0.5 text-[10px] leading-none tnum ${
                                    activeTab === 'requests'
                                        ? 'bg-brand-soft text-brand'
                                        : 'bg-surface text-ink-faint'
                                }`}
                            >
                                {requests.total}
                            </span>
                        </button>
                    </div>
                </div>

                {/* ======================================================
                    REQUEST TAB
                ====================================================== */}
                {activeTab === 'request' && (
                    <>
                        {/* FORM */}
                        {step === 'form' && (
                            <div className="mx-auto w-full max-w-4xl">
                                {cameFromAssistant && selectedCertificate && (
                                    <div className="mb-4 rounded-xl border border-brand/25 bg-brand-soft p-4">
                                        <div className="flex items-start gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand">
                                                <CertificateOutlineIcon small />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-ink-strong">
                                                    Selected by KaBarangay
                                                    Assistant
                                                </p>
                                                <p className="mt-1 text-sm leading-6 text-ink-muted">
                                                    {selectedCertificate.name}{' '}
                                                    is already selected.
                                                    Continue with the request
                                                    details below.
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setCameFromAssistant(false);
                                                    window.localStorage.removeItem(
                                                        CERTIFICATE_FLOW_KEY,
                                                    );
                                                }}
                                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-ink-faint transition hover:bg-white hover:text-ink-muted"
                                                aria-label="Dismiss assistant message"
                                            >
                                                <CloseIcon />
                                            </button>
                                        </div>
                                    </div>
                                )}

                                <form
                                    onSubmit={handleContinue}
                                    data-kb-offline-queue=""
                                    className="mx-auto w-full max-w-4xl"
                                >
                                    {/* VERIFIED RESIDENT */}
                                    <section className="mb-4 overflow-hidden rounded-xl border border-line-soft bg-white sm:mb-5">
                                        <div className="flex flex-col gap-3 border-b border-line-soft px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h2 className="text-sm font-semibold tracking-tight text-ink-strong">
                                                    Your Information
                                                </h2>
                                                <p className="mt-0.5 text-xs text-ink-muted">
                                                    Taken from your verified
                                                    resident profile.
                                                </p>
                                            </div>
                                            <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-success-soft px-2.5 py-1 text-xs font-semibold text-success">
                                                <CheckSmallIcon /> Verified
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
                                            <InfoItem
                                                label="Resident Name"
                                                value={resident.full_name}
                                            />
                                            <InfoItem
                                                label="KaBarangay ID"
                                                value={resident.resident_number}
                                            />
                                            {resident.purok && (
                                                <InfoItem
                                                    label="Purok"
                                                    value={resident.purok}
                                                />
                                            )}
                                            {resident.birthdate && (
                                                <InfoItem
                                                    label="Date of Birth"
                                                    value={formatDisplayDate(
                                                        resident.birthdate,
                                                    )}
                                                />
                                            )}
                                            {resident.sex && (
                                                <InfoItem
                                                    label="Sex"
                                                    value={resident.sex}
                                                />
                                            )}
                                            <InfoItem
                                                label="Barangay"
                                                value={[
                                                    resident.barangay.name,
                                                    resident.barangay
                                                        .city_municipality,
                                                ]
                                                    .filter(Boolean)
                                                    .join(', ')}
                                            />
                                        </div>
                                    </section>

                                    {/* REQUEST DETAILS */}
                                    <section className="overflow-hidden rounded-xl border border-line-soft bg-white">
                                        <div className="border-b border-line-soft px-5 py-4">
                                            <h2 className="text-sm font-semibold tracking-tight text-ink-strong">
                                                Request Details
                                            </h2>
                                            <p className="mt-0.5 text-xs text-ink-muted">
                                                Enter only the information needed
                                                for this request.
                                            </p>
                                        </div>

                                        <div className="space-y-5 p-5">
                                        {/* CERTIFICATE TYPE */}
                                        <div>
                                            <label
                                                htmlFor="certificate"
                                                className="mb-1.5 block text-sm font-medium text-ink"
                                            >
                                                Certificate Type *
                                            </label>
                                            <select
                                                id="certificate"
                                                value={certificateId}
                                                onChange={(event) =>
                                                    handleCertificateChange(
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                                required
                                            >
                                                <option value="">
                                                    Select certificate
                                                </option>
                                                {availableCertificates.map(
                                                    (certificate) => (
                                                        <option
                                                            key={certificate.id}
                                                            value={
                                                                certificate.id
                                                            }
                                                        >
                                                            {certificate.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            {errors.certificate_id && (
                                                <p className="mt-1.5 text-xs text-destructive">
                                                    {errors.certificate_id}
                                                </p>
                                            )}
                                        </div>

                                        {/* CERTIFICATE INFORMATION */}
                                        {selectedCertificate && (
                                            <div className="mt-4 rounded-xl bg-canvas p-4">
                                                <p className="text-sm font-semibold text-ink-strong">
                                                    {selectedCertificate.name}
                                                </p>
                                                <p className="mt-1 text-sm leading-6 text-ink-muted">
                                                    {
                                                        selectedCertificate.description
                                                    }
                                                </p>

                                                {!selectedCertificate.available && (
                                                    <p className="mt-3 rounded-lg border border-warning/25 bg-warning-soft p-3 text-sm leading-6 text-warning">
                                                        This certificate is
                                                        currently unavailable.
                                                        Please choose another
                                                        certificate type.
                                                    </p>
                                                )}
                                                {selectedCertificate.available &&
                                                    !selectedCertificate.templateAvailable && (
                                                        <div className="mt-3 rounded-lg border border-warning/25 bg-warning-soft px-3 py-2 text-sm text-warning">
                                                            This active service
                                                            can accept requests.
                                                            Certificate
                                                            generation is not
                                                            available yet; the
                                                            barangay office may
                                                            need to complete
                                                            processing after its
                                                            template is
                                                            provisioned.
                                                        </div>
                                                    )}

                                                {selectedCertificate
                                                    .requirements?.length >
                                                    0 && (
                                                    <div className="mt-4">
                                                        <p className="text-sm font-semibold text-ink-strong">
                                                            Requirements
                                                        </p>
                                                        <ul className="mt-2 space-y-2">
                                                            {selectedCertificate.requirements.map(
                                                                (
                                                                    requirement,
                                                                ) => (
                                                                    <li
                                                                        key={
                                                                            requirement
                                                                        }
                                                                        className="flex items-start gap-2 text-sm leading-6 text-ink-muted"
                                                                    >
                                                                        <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-success text-white">
                                                                            <CheckSmallIcon />
                                                                        </span>
                                                                        <span>
                                                                            {
                                                                                requirement
                                                                            }
                                                                        </span>
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    </div>
                                                )}

                                                <div className="mt-4 flex items-center justify-between border-t border-line-soft pt-4">
                                                    <span className="text-sm font-medium text-ink-muted">
                                                        Fee
                                                    </span>
                                                    <span className="text-base font-semibold text-brand tnum">
                                                        {selectedCertificate.fee >
                                                        0
                                                            ? `₱${selectedCertificate.fee.toLocaleString()}`
                                                            : 'Free'}
                                                    </span>
                                                </div>
                                            </div>
                                        )}

                                        {/* FIXED PURPOSE */}
                                        {selectedCertificate?.fixedPurpose && (
                                            <div className="mt-5 rounded-lg border border-line-soft bg-canvas p-4">
                                                <p className="text-sm font-medium text-ink-muted">
                                                    Purpose
                                                </p>
                                                <p className="mt-1 text-sm font-semibold text-ink-strong">
                                                    {
                                                        selectedCertificate.fixedPurpose
                                                    }
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-ink-muted">
                                                    This certificate uses a
                                                    fixed purpose set by the
                                                    barangay.
                                                </p>
                                            </div>
                                        )}

                                        {/* PURPOSE */}
                                        {!selectedCertificate?.fixedPurpose && (
                                            <>
                                                <div className="mt-5">
                                                    <label
                                                        htmlFor="purpose"
                                                        className="mb-1.5 block text-sm font-medium text-ink"
                                                    >
                                                        Purpose / Requirement
                                                        For *
                                                    </label>
                                                    <select
                                                        id="purpose"
                                                        value={purpose}
                                                        onChange={(event) =>
                                                            setPurpose(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className={inputClass}
                                                        required
                                                    >
                                                        <option value="">
                                                            Select purpose
                                                        </option>
                                                        <option value="Employment">
                                                            Employment
                                                        </option>
                                                        <option value="School Requirement">
                                                            School Requirement
                                                        </option>
                                                        <option value="Government Requirement">
                                                            Government
                                                            Requirement
                                                        </option>
                                                        <option value="Financial Assistance">
                                                            Financial Assistance
                                                        </option>
                                                        <option value="DSWD Assistance">
                                                            DSWD Assistance
                                                        </option>
                                                        <option value="Medical Assistance">
                                                            Medical Assistance
                                                        </option>
                                                        <option value="Scholarship">
                                                            Scholarship
                                                        </option>
                                                        <option value="Business">
                                                            Business
                                                        </option>
                                                        <option value="Personal">
                                                            Personal
                                                        </option>
                                                        <option value="Other">
                                                            Other
                                                        </option>
                                                    </select>
                                                    {errors.purpose && (
                                                        <p className="mt-1.5 text-xs text-destructive">
                                                            {errors.purpose}
                                                        </p>
                                                    )}
                                                </div>

                                                {/* OTHER PURPOSE */}
                                                {purpose === 'Other' && (
                                                    <div className="mt-5">
                                                        <label
                                                            htmlFor="otherPurpose"
                                                            className="mb-1.5 block text-sm font-medium text-ink"
                                                        >
                                                            Please specify *
                                                        </label>
                                                        <input
                                                            id="otherPurpose"
                                                            type="text"
                                                            value={otherPurpose}
                                                            onChange={(event) =>
                                                                setOtherPurpose(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder="Enter purpose"
                                                            className={
                                                                inputClass
                                                            }
                                                            required
                                                        />
                                                    </div>
                                                )}
                                            </>
                                        )}

                                        {/* BUSINESS DETAILS */}
                                        {selectedCertificate?.requiresBusinessDetails && (
                                            <div className="mt-6 border-t border-line-soft pt-6">
                                                <h3 className="text-base font-semibold text-ink-strong">
                                                    Business Information
                                                </h3>
                                                <p className="mt-1 text-sm text-ink-muted">
                                                    Enter the business
                                                    information required for
                                                    this certificate.
                                                </p>
                                                <div className="mt-4 space-y-4">
                                                    <div>
                                                        <label className="mb-1.5 block text-sm font-medium text-ink">
                                                            Business Owner
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={
                                                                resident.full_name
                                                            }
                                                            readOnly
                                                            aria-readonly="true"
                                                            className={`${inputClass} cursor-not-allowed bg-canvas text-ink-muted`}
                                                        />
                                                        <p className="mt-2 text-xs text-ink-muted">
                                                            The verified
                                                            Resident account
                                                            holder is used as
                                                            the business owner.
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label
                                                            htmlFor="businessName"
                                                            className="mb-1.5 block text-sm font-medium text-ink"
                                                        >
                                                            Business Name *
                                                        </label>
                                                        <input
                                                            id="businessName"
                                                            type="text"
                                                            value={businessName}
                                                            onChange={(event) =>
                                                                setBusinessName(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className={
                                                                inputClass
                                                            }
                                                            required
                                                        />
                                                        {errors.business_name && (
                                                            <p className="mt-1.5 text-xs text-destructive">
                                                                {
                                                                    errors.business_name
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <label
                                                            htmlFor="businessAddress"
                                                            className="mb-1.5 block text-sm font-medium text-ink"
                                                        >
                                                            Business Address *
                                                        </label>
                                                        <input
                                                            id="businessAddress"
                                                            type="text"
                                                            value={
                                                                businessAddress
                                                            }
                                                            onChange={(event) =>
                                                                setBusinessAddress(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className={
                                                                inputClass
                                                            }
                                                            required
                                                        />
                                                        {errors.business_address && (
                                                            <p className="mt-1.5 text-xs text-destructive">
                                                                {
                                                                    errors.business_address
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* CERTIFICATE-SPECIFIC DETAILS */}
                                        {detailsFields.length > 0 && (
                                            <div className="mt-6 border-t border-line-soft pt-6">
                                                <h3 className="text-base font-semibold text-ink-strong">
                                                    {selectedCertificate?.certificateType ??
                                                        'Certificate'}{' '}
                                                    Information
                                                </h3>
                                                <p className="mt-1 text-sm text-ink-muted">
                                                    These details are printed on
                                                    the certificate. Please
                                                    review them carefully.
                                                </p>
                                                <div className="mt-4 space-y-4">
                                                    {detailsFields.map(
                                                        (field) => (
                                                            <div
                                                                key={field.key}
                                                            >
                                                                <label
                                                                    htmlFor={`detail-${field.key}`}
                                                                    className="mb-1.5 block text-sm font-medium text-ink"
                                                                >
                                                                    {
                                                                        field.label
                                                                    }
                                                                    {field.required &&
                                                                        ' *'}
                                                                </label>
                                                                <input
                                                                    id={`detail-${field.key}`}
                                                                    type={
                                                                        field.type
                                                                    }
                                                                    value={
                                                                        details[
                                                                            field
                                                                                .key
                                                                        ] ?? ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) => {
                                                                        const value =
                                                                            event
                                                                                .target
                                                                                .value;
                                                                        setDetails(
                                                                            (
                                                                                current,
                                                                            ) => ({
                                                                                ...current,
                                                                                [field.key]:
                                                                                    value,
                                                                            }),
                                                                        );
                                                                    }}
                                                                    min={
                                                                        field.min
                                                                    }
                                                                    max={
                                                                        field.max
                                                                    }
                                                                    step={
                                                                        field.type ===
                                                                        'number'
                                                                            ? field.kind ===
                                                                              'numeric'
                                                                                ? '0.01'
                                                                                : '1'
                                                                            : undefined
                                                                    }
                                                                    placeholder={
                                                                        field.placeholder
                                                                    }
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                    required={
                                                                        field.required
                                                                    }
                                                                />
                                                                {errors[
                                                                    `details.${field.key}`
                                                                ] && (
                                                                    <p className="mt-1.5 text-xs text-destructive">
                                                                        {
                                                                            errors[
                                                                                `details.${field.key}`
                                                                            ]
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        {/* ADDITIONAL DETAILS */}
                                        <div className="mt-5">
                                            <label
                                                htmlFor="additionalDetails"
                                                className="mb-1.5 block text-sm font-medium text-ink"
                                            >
                                                Additional Details
                                                <span className="ml-1 text-xs font-normal text-ink-faint">
                                                    (Optional)
                                                </span>
                                            </label>
                                            <textarea
                                                id="additionalDetails"
                                                rows={3}
                                                value={additionalDetails}
                                                onChange={(event) =>
                                                    setAdditionalDetails(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Enter additional information if needed"
                                                className="min-h-24 w-full resize-none rounded-lg border border-line bg-surface px-3 py-3 text-sm text-ink-strong outline-none transition placeholder:text-ink-faint focus:border-brand focus:ring-2 focus:ring-brand/15"
                                            />
                                            {errors.additional_details && (
                                                <p className="mt-1.5 text-xs text-destructive">
                                                    {errors.additional_details}
                                                </p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <button
                                                type="submit"
                                                disabled={!selectedCertificate}
                                                className="flex h-11 w-full items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover disabled:cursor-not-allowed disabled:bg-brand/40"
                                            >
                                                Continue
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    void saveOfflineDraft()
                                                }
                                                disabled={
                                                    processing ||
                                                    !selectedCertificate
                                                }
                                                className="flex h-11 w-full items-center justify-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold text-ink-muted transition-colors hover:bg-canvas disabled:opacity-50"
                                            >
                                                Save draft on this device
                                            </button>
                                        </div>
                                        </div>
                                    </section>
                                </form>
                            </div>
                        )}

                        {/* REVIEW */}
                        {step === 'review' && selectedCertificate && (
                            <div className="mx-auto w-full max-w-4xl">
                                <section className="overflow-hidden rounded-xl border border-line-soft bg-white">
                                    <div className="border-b border-line-soft px-5 py-4">
                                        <h2 className="text-sm font-semibold tracking-tight text-ink-strong">
                                            Review Your Request
                                        </h2>
                                        <p className="mt-0.5 text-xs text-ink-muted">
                                            Check the information before
                                            submitting your request.
                                        </p>
                                    </div>

                                    <div className="p-5">
                                    <div>
                                        <ReviewRow
                                            label="Resident"
                                            value={resident.full_name}
                                        />
                                        <ReviewRow
                                            label="KaBarangay ID"
                                            value={resident.resident_number}
                                        />
                                        <ReviewRow
                                            label="Certificate"
                                            value={selectedCertificate.name}
                                        />
                                        <ReviewRow
                                            label="Purpose"
                                            value={effectivePurpose}
                                        />

                                        {selectedCertificate.requiresBusinessDetails && (
                                            <>
                                                <ReviewRow
                                                    label="Business Owner"
                                                    value={resident.full_name}
                                                />
                                                <ReviewRow
                                                    label="Business Name"
                                                    value={businessName}
                                                />
                                                <ReviewRow
                                                    label="Business Address"
                                                    value={businessAddress}
                                                />
                                            </>
                                        )}

                                        {detailsFields.map((field) =>
                                            (
                                                details[field.key] ?? ''
                                            ).trim() !== '' ? (
                                                <ReviewRow
                                                    key={field.key}
                                                    label={field.label}
                                                    value={details[field.key]}
                                                />
                                            ) : null,
                                        )}

                                        {additionalDetails && (
                                            <ReviewRow
                                                label="Additional Details"
                                                value={additionalDetails}
                                            />
                                        )}

                                        <ReviewRow
                                            label="Fee"
                                            value={
                                                selectedCertificate.fee > 0
                                                    ? `₱${selectedCertificate.fee.toLocaleString()}`
                                                    : 'Free'
                                            }
                                            last
                                        />
                                    </div>

                                    <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void saveOfflineDraft()
                                            }
                                            disabled={processing}
                                            className="inline-flex h-9 items-center justify-center rounded-md border border-line bg-surface px-4 text-sm font-medium text-ink-strong transition-colors hover:bg-canvas disabled:opacity-50"
                                        >
                                            Save draft on this device
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setStep('form')}
                                            disabled={processing}
                                            className="inline-flex h-9 w-full items-center justify-center rounded-md bg-secondary px-4 text-sm font-medium text-ink-strong transition-colors hover:bg-secondary/80 disabled:opacity-50 sm:w-auto"
                                        >
                                            Back
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleSubmitRequest}
                                            disabled={processing}
                                            className="inline-flex h-9 w-full items-center justify-center rounded-md bg-brand px-4 text-sm font-medium text-white transition-colors hover:bg-brand-hover disabled:opacity-50 sm:w-auto"
                                        >
                                            {processing
                                                ? 'Submitting...'
                                                : online
                                                  ? 'Submit Request'
                                                  : 'Submit (sends when online)'}
                                        </button>
                                    </div>
                                    </div>
                                </section>
                            </div>
                        )}

                        {/* SUCCESS */}
                        {step === 'success' && submittedRequest && (
                            <div className="mx-auto w-full max-w-xl">
                                <section className="overflow-hidden rounded-xl border border-line-soft bg-white p-6 text-center">
                                    <span className="mx-auto grid size-14 place-items-center rounded-full bg-success-soft text-success">
                                        <CheckLargeIcon />
                                    </span>
                                    <h2 className="mt-4 text-lg font-semibold text-ink-strong">
                                        Request Submitted
                                    </h2>
                                    <p className="mt-1 text-sm text-ink-muted">
                                        Your certificate request has been
                                        submitted for barangay review.
                                    </p>
                                    <div className="mt-5 space-y-3 rounded-xl bg-canvas p-4 text-left">
                                        <InfoItem
                                            label="Request Number"
                                            value={submittedRequest.reference}
                                        />
                                        <div className="border-t border-line-soft" />
                                        <InfoItem
                                            label="Certificate"
                                            value={submittedRequest.certificate}
                                        />
                                        <div className="border-t border-line-soft" />
                                        <div className="flex items-center justify-between gap-4">
                                            <span className="text-sm text-ink-muted">
                                                Status
                                            </span>
                                            <StatusBadge
                                                status={submittedRequest.status}
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <button
                                            type="button"
                                            onClick={resetForm}
                                            className="inline-flex h-9 items-center justify-center rounded-md border border-line bg-surface px-4 text-sm font-medium text-ink-strong transition-colors hover:bg-canvas"
                                        >
                                            Request Another
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setActiveTab('requests')
                                            }
                                            className="inline-flex h-9 items-center justify-center rounded-md bg-brand px-4 text-sm font-medium text-white transition-colors hover:bg-brand-hover"
                                        >
                                            My Requests
                                        </button>
                                    </div>
                                </section>
                            </div>
                        )}

                        {/* SAVED ON DEVICE, NOT YET SENT */}
                        {step === 'queued' && (
                            <div className="mx-auto w-full max-w-xl">
                                <section className="overflow-hidden rounded-xl border border-warning/25 bg-warning-soft p-6 text-center">
                                    <span className="mx-auto grid size-14 place-items-center rounded-full bg-warning text-white">
                                        <ClockLargeIcon />
                                    </span>
                                    <h2 className="mt-4 text-lg font-semibold text-warning">
                                        {degraded
                                            ? 'Waiting for the Barangay data service'
                                            : 'Waiting for Internet Connection'}
                                    </h2>
                                    <p className="mt-1 text-sm text-warning">
                                        Your request has been saved on this
                                        device. It will be sent automatically
                                        once{' '}
                                        {degraded
                                            ? 'the Barangay data service can read requests again'
                                            : 'your internet connection returns'}
                                        . The Barangay has not received it yet.
                                    </p>
                                    <div className="mt-5 space-y-3 rounded-xl bg-white p-4 text-left">
                                        <InfoItem
                                            label="Saved on this device"
                                            value="No request number yet"
                                        />
                                        <div className="border-t border-line-soft" />
                                        <InfoItem
                                            label="Status"
                                            value="Not yet received by the Barangay"
                                        />
                                    </div>
                                    <div className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <button
                                            type="button"
                                            onClick={resetForm}
                                            className="inline-flex h-9 items-center justify-center rounded-md border border-line bg-surface px-4 text-sm font-medium text-ink-strong transition-colors hover:bg-canvas"
                                        >
                                            Request Another
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                refreshLocalRequests();
                                                setActiveTab('requests');
                                            }}
                                            className="inline-flex h-9 items-center justify-center rounded-md bg-brand px-4 text-sm font-medium text-white transition-colors hover:bg-brand-hover"
                                        >
                                            My Requests
                                        </button>
                                    </div>
                                </section>
                            </div>
                        )}
                    </>
                )}

                {/* ======================================================
                    MY REQUESTS
                ====================================================== */}
                {activeTab === 'requests' && (
                    <section className="mx-auto w-full max-w-4xl">
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold tracking-tight text-ink-strong">
                                My Requests
                            </h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                View and track your submitted certificate
                                requests.
                            </p>
                        </div>
                        {unsyncedLocalRequests.length > 0 && (
                            <div className="mb-4 space-y-3">
                                {unsyncedLocalRequests.map((item) => (
                                    <LocalRequestRow key={item.id} item={item} />
                                ))}
                            </div>
                        )}
                        {requests.data.length > 0 ? (
                            <div className="space-y-3">
                                {requests.data.map((request) => (
                                    <div
                                        key={request.id}
                                        className="overflow-hidden rounded-xl border border-line-soft bg-white"
                                    >
                                        <div className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold break-words text-ink-strong sm:text-base">
                                                    {request.certificate}
                                                </p>
                                                <p className="mt-0.5 text-xs font-medium break-all text-ink-muted">
                                                    {request.reference}
                                                </p>
                                                <p className="mt-0.5 text-xs text-ink-faint">
                                                    Submitted: {request.date}
                                                </p>
                                            </div>
                                            <div className="flex flex-col gap-2 sm:items-end">
                                                <StatusBadge
                                                    status={request.status}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setSelectedRequest(
                                                            request,
                                                        )
                                                    }
                                                    className="inline-flex h-8 w-full items-center justify-center rounded-lg border border-line bg-surface px-3 text-xs font-medium text-ink-strong transition-colors hover:bg-canvas sm:w-auto"
                                                >
                                                    View Details
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                <Pagination
                                    paginator={requests}
                                    label="Certificate request"
                                />
                            </div>
                        ) : unsyncedLocalRequests.length === 0 ? (
                            <div className="rounded-xl border border-line-soft bg-white">
                                <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
                                    <span className="grid size-12 place-items-center rounded-full bg-canvas text-ink-faint">
                                        <CertificateOutlineIcon small />
                                    </span>
                                    <div className="max-w-sm space-y-1">
                                        <p className="text-sm font-semibold text-ink-strong">
                                            No certificate requests
                                        </p>
                                        <p className="text-xs leading-relaxed text-ink-muted">
                                            Your submitted requests will appear
                                            here.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            resetForm();
                                            setActiveTab('request');
                                        }}
                                        className="inline-flex h-9 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover"
                                    >
                                        Request Certificate
                                    </button>
                                </div>
                            </div>
                        ) : null}
                    </section>
                )}

                {/* ======================================================
                    DETAILS MODAL
                ====================================================== */}
                {selectedRequest && (
                    <RequestDetailsModal
                        request={selectedRequest}
                        onClose={() => setSelectedRequest(null)}
                    />
                )}
            </ResidentLayout>
        </>
    );
}

/*
|--------------------------------------------------------------------------
| Shared Input
|--------------------------------------------------------------------------
*/

const inputClass =
    'h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink-strong outline-none transition placeholder:text-ink-faint focus:border-brand focus:ring-2 focus:ring-brand/15';

/*
|--------------------------------------------------------------------------
| Request Details Modal
|--------------------------------------------------------------------------
*/

function RequestDetailsModal({
    request,
    onClose,
}: {
    request: CertificateRequest;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4">
            <div className="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-w-3xl sm:rounded-xl">
                {/* HEADER */}
                <div className="flex shrink-0 items-center justify-between border-b border-line-soft px-4 py-4 sm:px-5">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-ink-strong sm:text-xl">
                            Request Details
                        </h2>
                        <p className="mt-1 text-sm font-medium break-all text-ink-muted">
                            {request.reference}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close request details"
                        className="ml-3 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-muted transition hover:bg-secondary"
                    >
                        <CloseIcon />
                    </button>
                </div>

                {/* CONTENT */}
                <div className="flex-1 overflow-y-auto p-4 sm:p-5">
                    <div className="rounded-xl border border-line-soft bg-canvas p-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <InfoItem
                                label="Certificate"
                                value={request.certificate}
                            />
                            <InfoItem label="Purpose" value={request.purpose} />
                            <InfoItem label="Submitted" value={request.date} />
                            <InfoItem
                                label="Fee"
                                value={
                                    request.fee > 0
                                        ? `₱${request.fee.toLocaleString()}`
                                        : 'Free'
                                }
                            />
                            {request.businessName && (
                                <InfoItem
                                    label="Business Name"
                                    value={request.businessName}
                                />
                            )}
                            {request.businessAddress && (
                                <InfoItem
                                    label="Business Address"
                                    value={request.businessAddress}
                                />
                            )}
                            {request.details?.map((detail) => (
                                <InfoItem
                                    key={detail.label}
                                    label={detail.label}
                                    value={detail.value}
                                />
                            ))}
                        </div>
                        {request.additionalDetails && (
                            <>
                                <div className="my-4 border-t border-line-soft" />
                                <InfoItem
                                    label="Additional Details"
                                    value={request.additionalDetails}
                                />
                            </>
                        )}
                        {request.adminNote && (
                            <>
                                <div className="my-4 border-t border-line-soft" />
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                    <p className="text-sm font-semibold text-amber-800">
                                        Barangay Note
                                    </p>
                                    <p className="mt-1 text-sm leading-6 whitespace-pre-wrap text-amber-900">
                                        {request.adminNote}
                                    </p>
                                </div>
                            </>
                        )}
                        <div className="my-4 border-t border-line-soft" />
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span className="text-sm text-ink-muted">
                                Current Status
                            </span>
                            <StatusBadge status={request.status} />
                        </div>
                    </div>

                    {/* TRACK STATUS */}
                    <div className="mt-6">
                        <h3 className="text-lg font-semibold text-ink-strong">
                            Track Status
                        </h3>
                        <p className="mt-1 text-sm leading-6 text-ink-muted">
                            Follow the progress of your certificate request.
                        </p>
                        <div className="mt-5">
                            <RequestTracker status={request.status} />
                        </div>
                    </div>
                </div>

                {/* FOOTER */}
                <div className="shrink-0 border-t border-line-soft p-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="min-h-11 w-full rounded-lg bg-brand px-5 text-sm font-semibold text-white transition hover:bg-brand-hover sm:ml-auto sm:block sm:w-auto"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Request Tracker
|--------------------------------------------------------------------------
*/

function RequestTracker({ status }: { status: RequestStatus }) {
    if (status === 'Rejected') {
        return (
            <div className="rounded-xl border border-red-200 bg-red-50 p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                        <CloseIcon />
                    </div>
                    <div>
                        <p className="font-semibold text-red-700">
                            Request Rejected
                        </p>
                        <p className="mt-1 text-sm leading-6 text-red-600">
                            Your request could not be processed. Please check
                            the provided reason or contact the barangay office.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    if (status === 'Needs Action') {
        return (
            <div className="rounded-xl border border-orange-200 bg-orange-50 p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-600">
                        <AlertIcon />
                    </div>
                    <div>
                        <p className="font-semibold text-orange-700">
                            Action Required
                        </p>
                        <p className="mt-1 text-sm leading-6 text-orange-600">
                            Additional or corrected information is needed before
                            processing can continue.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    // Only show statuses the request has actually reached. There is no
    // standalone "Reviewed" status, and a payment step is omitted unless the
    // request is currently waiting for payment.
    const steps = ['Submitted'];

    if (status === 'For Payment') {
        steps.push('For Payment');
    } else if (status === 'Processing') {
        steps.push('Processing');
    } else if (status === 'Ready for Claim') {
        steps.push('Processing', 'Ready for Claim');
    } else if (status === 'Released') {
        steps.push('Processing', 'Ready for Claim', 'Released');
    }

    const currentStep = steps.length - 1;

    return (
        <div>
            {/* MOBILE TRACKER */}
            <div className="sm:hidden">
                {steps.map((step, index) => {
                    const completed = index < currentStep;
                    const current = index === currentStep;

                    return (
                        <div key={step} className="relative flex gap-3">
                            {index !== steps.length - 1 && (
                                <div
                                    className={`absolute top-9 left-[17px] h-[calc(100%-1px)] w-[2px] ${
                                        index < currentStep
                                            ? 'bg-brand'
                                            : 'bg-line-soft'
                                    }`}
                                />
                            )}
                            <div
                                className={`relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 ${
                                    completed
                                        ? 'border-brand bg-brand text-white'
                                        : current
                                          ? 'border-brand bg-brand-soft text-brand'
                                          : 'border-line bg-white text-ink-faint'
                                }`}
                            >
                                <TrackerIcon
                                    step={step}
                                    completed={completed}
                                />
                            </div>
                            <div className="min-w-0 pb-6">
                                <p
                                    className={`text-sm font-semibold ${completed || current ? 'text-ink-strong' : 'text-ink-faint'}`}
                                >
                                    {step}
                                </p>
                                {completed && (
                                    <p className="mt-1 text-xs text-ink-muted">
                                        Completed
                                    </p>
                                )}
                                {current && (
                                    <p className="mt-1 text-xs font-semibold text-brand">
                                        Current status
                                    </p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* TABLET / DESKTOP TRACKER */}
            <div className="hidden overflow-x-auto pb-2 sm:block">
                <div
                    className={
                        steps.length === 6
                            ? 'flex min-w-[690px] items-start px-2'
                            : 'flex min-w-[560px] items-start px-2'
                    }
                >
                    {steps.map((step, index) => {
                        const completed = index < currentStep;
                        const current = index === currentStep;
                        const reached = index <= currentStep;

                        return (
                            <div
                                key={step}
                                className="relative flex flex-1 flex-col items-center"
                            >
                                {index > 0 && (
                                    <div
                                        className={`absolute top-5 right-1/2 h-[3px] w-full ${reached ? 'bg-brand' : 'bg-line-soft'}`}
                                    />
                                )}
                                <div
                                    className={`relative z-10 flex h-10 w-10 items-center justify-center rounded-full border-2 ${
                                        completed
                                            ? 'border-brand bg-brand text-white'
                                            : current
                                              ? 'border-brand bg-brand-soft text-brand'
                                              : 'border-line bg-white text-ink-faint'
                                    }`}
                                >
                                    <TrackerIcon
                                        step={step}
                                        completed={completed}
                                    />
                                </div>
                                <p
                                    className={`mt-2 max-w-[105px] text-center text-xs leading-4 font-semibold ${
                                        completed || current
                                            ? 'text-ink-strong'
                                            : 'text-ink-faint'
                                    }`}
                                >
                                    {step}
                                </p>
                                {current && (
                                    <span className="mt-1 text-[11px] font-semibold text-brand">
                                        Current
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* STATUS MESSAGE */}
            <div className="mt-4 rounded-xl border border-line-soft bg-canvas p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-soft text-brand">
                        <CurrentStatusIcon status={status} />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-ink-strong">
                            {getFriendlyStatusTitle(status)}
                        </p>
                        <p className="mt-1 text-sm leading-6 text-ink-muted">
                            {getStatusMessage(status)}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Tracker Icon
|--------------------------------------------------------------------------
*/

function TrackerIcon({
    step,
    completed,
}: {
    step: string;
    completed: boolean;
}) {
    if (completed) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="2.6"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="M5 12.5l4 4L19 7" />
            </svg>
        );
    }

    if (step === 'Submitted') {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" />
                <path d="M14 3.5V8h4" />
                <path d="M9 12h6" />
                <path d="M9 16h6" />
            </svg>
        );
    }

    if (step === 'For Payment') {
        return <span className="text-sm leading-none font-semibold">₱</span>;
    }

    if (step === 'Processing') {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <circle cx="12" cy="12" r="3" />
                <path d="M12 2.5v3" />
                <path d="M12 18.5v3" />
                <path d="M2.5 12h3" />
                <path d="M18.5 12h3" />
                <path d="m5.3 5.3 2.1 2.1" />
                <path d="m16.6 16.6 2.1 2.1" />
            </svg>
        );
    }

    if (step === 'Ready for Claim') {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <rect x="5" y="4" width="14" height="17" rx="2" />
                <path d="m8.5 13 2.2 2.2 4.8-5" />
            </svg>
        );
    }

    if (step === 'Released') {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <circle cx="12" cy="12" r="9" />
                <path d="m8 12 2.7 2.7L16.5 9" />
            </svg>
        );
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Current Status Icon
|--------------------------------------------------------------------------
*/

function CurrentStatusIcon({ status }: { status: RequestStatus }) {
    if (status === 'For Payment') {
        return <span className="text-sm font-semibold">₱</span>;
    }

    if (status === 'Processing') {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <circle cx="12" cy="12" r="3" />
                <path d="M12 3v3" />
                <path d="M12 18v3" />
                <path d="M3 12h3" />
                <path d="M18 12h3" />
            </svg>
        );
    }

    if (status === 'Pending') {
        return <CertificateOutlineIcon small />;
    }

    return <CheckSmallIcon />;
}

/*
|--------------------------------------------------------------------------
| Tracker Logic
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Friendly Status Title
|--------------------------------------------------------------------------
*/

function getFriendlyStatusTitle(status: RequestStatus) {
    switch (status) {
        case 'Pending':
            return 'Request Submitted';
        case 'For Payment':
            return 'Payment Required';
        case 'Processing':
            return 'Certificate Processing';
        case 'Ready for Claim':
            return 'Ready for Claim';
        case 'Released':
            return 'Certificate Released';
        case 'Needs Action':
            return 'Action Required';
        case 'Rejected':
            return 'Request Rejected';
        default:
            return 'Request Status';
    }
}

/*
|--------------------------------------------------------------------------
| Status Message
|--------------------------------------------------------------------------
*/

function getStatusMessage(status: RequestStatus) {
    switch (status) {
        case 'Pending':
            return 'Your request has been received and is waiting for review.';
        case 'For Payment':
            return 'Please complete the required payment before processing can continue.';
        case 'Processing':
            return 'Your certificate is currently being prepared.';
        case 'Ready for Claim':
            return 'Your certificate is ready. Please proceed to the barangay office to claim it.';
        case 'Released':
            return 'Your certificate has been successfully released.';
        default:
            return '';
    }
}

/*
|--------------------------------------------------------------------------
| Info Item
|--------------------------------------------------------------------------
*/

function InfoItem({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <p className="text-xs font-medium text-ink-muted">{label}</p>
            <p className="mt-0.5 text-sm font-semibold break-words text-ink-strong">
                {value}
            </p>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Review Row
|--------------------------------------------------------------------------
*/

function ReviewRow({
    label,
    value,
    last = false,
}: {
    label: string;
    value: string;
    last?: boolean;
}) {
    return (
        <div
            className={`flex flex-col gap-1 py-3 sm:flex-row sm:justify-between sm:gap-6 ${!last ? 'border-b border-line-soft' : ''}`}
        >
            <span className="text-sm text-ink-muted">{label}</span>
            <span className="text-sm font-medium break-words text-ink-strong sm:max-w-[65%] sm:text-right">
                {value}
            </span>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Status Badge
|--------------------------------------------------------------------------
*/

function StatusBadge({ status }: { status: RequestStatus }) {
    const styles: Record<RequestStatus, string> = {
        Pending: 'bg-warning-soft text-warning',
        'Needs Action': 'bg-warning-soft text-warning',
        'For Payment': 'bg-brand-soft text-brand',
        Processing: 'bg-info-soft text-info',
        'Ready for Claim': 'bg-success-soft text-success',
        Released: 'bg-secondary text-ink-muted',
        Rejected: 'bg-danger-soft text-destructive',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ${styles[status]}`}
        >
            {status}
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Locally saved request (not yet confirmed by the server)
|--------------------------------------------------------------------------
*/

const LOCAL_SYNC_LABEL: Record<string, string> = {
    pending: 'Waiting for Internet Connection',
    syncing: 'Sending to the Barangay…',
    'login-required': 'Sign in online to send this request',
    'needs-review': 'Needs your review before it can be sent',
};

function LocalRequestRow({ item }: { item: LocalCertificateRequest }) {
    return (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <p className="text-base font-semibold break-words text-amber-950 sm:text-lg">
                        {item.data.certificateName}
                    </p>
                    <p className="mt-1 text-sm font-medium break-words text-amber-900">
                        {item.data.form?.purpose || 'No purpose recorded'}
                    </p>
                    <p className="mt-1 text-sm text-amber-800">
                        Saved locally:{' '}
                        {new Date(item.createdAt).toLocaleDateString('en-PH', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                        })}
                    </p>
                    <p className="mt-2 text-xs leading-5 text-amber-900 sm:text-sm">
                        Saved on this device. Not yet received by the Barangay.
                        This is not an official request status.
                    </p>
                    {item.data.error && (
                        <p className="mt-2 rounded-lg border border-amber-300 bg-white p-2 text-xs leading-5 text-amber-950 sm:text-sm">
                            {item.data.error}
                        </p>
                    )}
                </div>
                <span className="inline-flex shrink-0 items-center gap-2 self-start rounded-full border border-amber-400 bg-white px-3 py-1 text-xs font-semibold text-amber-900">
                    <span aria-hidden className="h-2 w-2 rounded-full bg-amber-500" />
                    {LOCAL_SYNC_LABEL[item.status] ||
                        'Waiting for Internet Connection'}
                </span>
            </div>
        </div>
    );
}

function CheckLargeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M5 12.5l4 4L19 7" />
        </svg>
    );
}

function ClockLargeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3.5 2" />
        </svg>
    );
}

function CheckSmallIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-3.5 w-3.5"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M5 12.5l4 4L19 7" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M6 6l12 12" />
            <path d="M18 6 6 18" />
        </svg>
    );
}

function AlertIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M12 3 2.8 19h18.4L12 3Z" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
    );
}

function CertificateOutlineIcon({ small = false }: { small?: boolean }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className={small ? 'h-4 w-4' : 'mx-auto h-10 w-10 text-brand'}
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M9 13h6" />
            <path d="M9 17h4" />
        </svg>
    );
}
