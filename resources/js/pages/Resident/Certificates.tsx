import { Deferred, Head, router } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import Pagination from '@/components/Pagination';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import { offlineCertificateStore, prepareOfflineCertificates } from '@/lib/offlineCertificates';
import type { PaginatedData } from '@/types';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type Tab = 'request' | 'requests';

type Step = 'form' | 'review' | 'success';

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

/*
|--------------------------------------------------------------------------
| Main Page
|--------------------------------------------------------------------------
*/

export default function Certificates(props: PageProps) {
    return (
        <Deferred
            data={['resident', 'certificates', 'requests']}
            fallback={<ResidentLayout active="certificates"><PortalPageSkeleton showTabs /></ResidentLayout>}
        >
            <CertificatesContent {...props} />
        </Deferred>
    );
}

function CertificatesContent({ resident, certificates, requests, errors }: PageProps) {
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
    const [submittedRequest, setSubmittedRequest] = useState<CertificateRequest | null>(null);
    const [selectedRequest, setSelectedRequest] = useState<CertificateRequest | null>(null);
    const [cameFromAssistant, setCameFromAssistant] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [draftMessage, setDraftMessage] = useState('');
    const saveOfflineDraft = async () => {
        if (!selectedCertificate || processing) return;
        setProcessing(true);
        try {
            let store = await offlineCertificateStore();
            try { await store.context(); } catch { store = await prepareOfflineCertificates(); }
            await store.saveDraft({ certificate_id: selectedCertificate.id, purpose: effectivePurpose, business_name: businessName, business_address: businessAddress, additional_details: additionalDetails, details });
            setDraftMessage('Draft saved on this device. Open Offline drafts & requests to review and queue it. It is not officially submitted yet.');
        } catch (error) { setDraftMessage(error instanceof Error ? error.message : 'Unable to save this draft.'); }
        finally { setProcessing(false); }
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
            console.error('Unable to load certificate selected by KaBarangay Assistant.', error);
        }
    }, [certificates]);

    /*
    |--------------------------------------------------------------------------
    | Selected Certificate
    |--------------------------------------------------------------------------
    */

    const selectedCertificate = useMemo(
        () => certificates.find((certificate) => certificate.id === certificateId),
        [certificateId, certificates]
    );

    const effectivePurpose =
        selectedCertificate?.fixedPurpose ?? (purpose === 'Other' ? otherPurpose : purpose);

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
        if (!selectedCertificate.fixedPurpose && purpose === 'Other' && !otherPurpose.trim()) return;
        if (selectedCertificate.requiresBusinessDetails && (!businessName.trim() || !businessAddress.trim())) return;
        if (!detailsComplete) return;

        setStep('review');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /*
    |--------------------------------------------------------------------------
    | Submit Request to Database
    |--------------------------------------------------------------------------
    */

    const handleSubmitRequest = () => {
        if (!selectedCertificate?.available || processing) return;
        setProcessing(true);

        router.post('/resident/certificates', {
            certificate_id: selectedCertificate.id,
            purpose: effectivePurpose,
            business_name: businessName || undefined,
            business_address: businessAddress || undefined,
            additional_details: additionalDetails || undefined,
            details,
        }, {
            preserveScroll: true,
            onSuccess: (page: any) => {
                // The newest request will be the first item in the newly loaded page.
                const requests = page.props.requests as PaginatedData<CertificateRequest> | undefined;
                if (requests?.data.length) {
                    setSubmittedRequest(requests.data[0]);
                }

                try {
                    window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
                } catch (error) {
                    console.error('Unable to clear certificate assistant flow.', error);
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
            }
        });
    };

    return (
        <>
            <Head title="Certificate Request" />

            <ResidentLayout active="certificates">
                {/* ======================================================
                    PAGE HEADER
                ====================================================== */}
                <section className="mb-5 sm:mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800 sm:text-3xl">
                        Certificate Request
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                        Request a barangay certificate or track your submitted request.
                    </p>
                    <a href="/offline-certificates.html" className="mt-2 inline-block text-sm font-semibold text-[#3f6f3d] underline">Offline drafts & pending requests</a>
                    {draftMessage && <p role="status" className="mt-3 rounded-lg border bg-white p-3 text-sm">{draftMessage}</p>}
                </section>

                {/* ======================================================
                    TABS
                ====================================================== */}
                <div className="mb-6 flex w-full max-w-xl rounded-xl border border-gray-200 bg-white p-1">
                    <button
                        type="button"
                        onClick={() => setActiveTab('request')}
                        className={`min-h-12 flex-1 rounded-lg px-2 text-sm font-semibold transition sm:px-4 sm:text-base ${
                            activeTab === 'request'
                                ? 'bg-[#3f6f3d] text-white'
                                : 'text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        Request Certificate
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('requests')}
                        className={`min-h-12 flex-1 rounded-lg px-2 text-sm font-semibold transition sm:px-4 sm:text-base ${
                            activeTab === 'requests'
                                ? 'bg-[#3f6f3d] text-white'
                                : 'text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        My Requests
                    </button>
                </div>

                {/* ======================================================
                    REQUEST TAB
                ====================================================== */}
                {activeTab === 'request' && (
                    <>
                        {/* FORM */}
                        {step === 'form' && (
                            <div className="mx-auto w-full max-w-3xl">
                                {cameFromAssistant && selectedCertificate && (
                                    <div className="mb-4 rounded-xl border border-[#d7e7d2] bg-[#f3f8f1] p-4">
                                        <div className="flex items-start gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">
                                                <CertificateOutlineIcon small />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-gray-800">
                                                    Selected by KaBarangay Assistant
                                                </p>
                                                <p className="mt-1 text-sm leading-6 text-gray-600">
                                                    {selectedCertificate.name} is already selected. Continue with the request details below.
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setCameFromAssistant(false);
                                                    window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
                                                }}
                                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-white hover:text-gray-600"
                                                aria-label="Dismiss assistant message"
                                            >
                                                <CloseIcon />
                                            </button>
                                        </div>
                                    </div>
                                )}

                                <form onSubmit={handleContinue} className="mx-auto w-full max-w-3xl">
                                    {/* VERIFIED RESIDENT */}
                                    <section className="mb-4 rounded-xl border border-gray-200 bg-white p-4 sm:mb-5 sm:p-6">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <h2 className="text-lg font-semibold text-gray-800">Your Information</h2>
                                                <p className="mt-1 text-sm leading-6 text-gray-500">
                                                    Taken from your verified resident profile.
                                                </p>
                                            </div>
                                            <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-[#edf4ea] px-3 py-1.5 text-sm font-semibold text-[#3f6f3d]">
                                                <CheckSmallIcon /> Verified
                                            </span>
                                        </div>
                                        <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <InfoItem label="Resident Name" value={resident.full_name} />
                                            <InfoItem label="KaBarangay ID" value={resident.resident_number} />
                                            {resident.purok && <InfoItem label="Purok" value={resident.purok} />}
                                            {resident.birthdate && <InfoItem label="Date of Birth" value={resident.birthdate} />}
                                            {resident.sex && <InfoItem label="Sex" value={resident.sex} />}
                                            <div className="sm:col-span-2">
                                                <InfoItem label="Barangay" value={`${resident.barangay.name}, ${resident.barangay.city_municipality}`} />
                                            </div>
                                        </div>
                                    </section>

                                    {/* REQUEST DETAILS */}
                                    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-6">
                                        <h2 className="text-lg font-semibold text-gray-800">Request Details</h2>
                                        <p className="mt-1 text-sm leading-6 text-gray-500">
                                            Enter only the information needed for this request.
                                        </p>

                                        {/* CERTIFICATE TYPE */}
                                        <div className="mt-5 sm:mt-6">
                                            <label htmlFor="certificate" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                Certificate Type *
                                            </label>
                                            <select
                                                id="certificate"
                                                value={certificateId}
                                                onChange={(event) => handleCertificateChange(event.target.value)}
                                                className={inputClass}
                                                required
                                            >
                                                <option value="">Select certificate</option>
                                                {availableCertificates.map((certificate) => (
                                                    <option
                                                        key={certificate.id}
                                                        value={certificate.id}
                                                    >
                                                        {certificate.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.certificate_id && <p className="mt-2 text-sm text-red-600">{errors.certificate_id}</p>}
                                        </div>

                                        {/* CERTIFICATE INFORMATION */}
                                        {selectedCertificate && (
                                            <div className="mt-4 rounded-xl bg-[#f7f8f5] p-4">
                                                <p className="text-base font-semibold text-gray-800">{selectedCertificate.name}</p>
                                                <p className="mt-1 text-sm leading-6 text-gray-500">{selectedCertificate.description}</p>

                                                {!selectedCertificate.available && (
                                                    <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800">
                                                        This certificate is currently unavailable. Please choose another certificate type.
                                                    </p>
                                                )}
                                                {selectedCertificate.available && !selectedCertificate.templateAvailable && (
                                                    <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                                        This active service can accept requests. Certificate generation is not available yet; the barangay office may need to complete processing after its template is provisioned.
                                                    </div>
                                                )}

                                                {selectedCertificate.requirements?.length > 0 && (
                                                    <div className="mt-4">
                                                        <p className="text-sm font-semibold text-gray-700">Requirements</p>
                                                        <ul className="mt-2 space-y-2">
                                                            {selectedCertificate.requirements.map((requirement) => (
                                                                <li key={requirement} className="flex items-start gap-2 text-sm leading-6 text-gray-600">
                                                                    <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-[#3f6f3d] text-white">
                                                                        <CheckSmallIcon />
                                                                    </span>
                                                                    <span>{requirement}</span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                                
                                                <div className="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
                                                    <span className="text-sm font-medium text-gray-600">Fee</span>
                                                    <span className="text-lg font-semibold text-[#3f6f3d]">
                                                        {selectedCertificate.fee > 0 ? `₱${selectedCertificate.fee.toLocaleString()}` : 'Free'}
                                                    </span>
                                                </div>
                                            </div>
                                        )}

                                        {/* FIXED PURPOSE */}
                                        {selectedCertificate?.fixedPurpose && (
                                            <div className="mt-5 rounded-lg border border-[#d7e3d4] bg-[#edf4ea] p-4">
                                                <p className="text-sm font-medium text-gray-600">Purpose</p>
                                                <p className="mt-1 text-base font-semibold text-gray-800">
                                                    {selectedCertificate.fixedPurpose}
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-gray-500">
                                                    This certificate uses a fixed purpose set by the barangay.
                                                </p>
                                            </div>
                                        )}

                                        {/* PURPOSE */}
                                        {!selectedCertificate?.fixedPurpose && (
                                            <>
                                                <div className="mt-5">
                                                    <label htmlFor="purpose" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                        Purpose / Requirement For *
                                                    </label>
                                                    <select
                                                        id="purpose"
                                                        value={purpose}
                                                        onChange={(event) => setPurpose(event.target.value)}
                                                        className={inputClass}
                                                        required
                                                    >
                                                        <option value="">Select purpose</option>
                                                        <option value="Employment">Employment</option>
                                                        <option value="School Requirement">School Requirement</option>
                                                        <option value="Government Requirement">Government Requirement</option>
                                                        <option value="Financial Assistance">Financial Assistance</option>
                                                        <option value="DSWD Assistance">DSWD Assistance</option>
                                                        <option value="Medical Assistance">Medical Assistance</option>
                                                        <option value="Scholarship">Scholarship</option>
                                                        <option value="Business">Business</option>
                                                        <option value="Personal">Personal</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                    {errors.purpose && <p className="mt-2 text-sm text-red-600">{errors.purpose}</p>}
                                                </div>

                                                {/* OTHER PURPOSE */}
                                                {purpose === 'Other' && (
                                                    <div className="mt-5">
                                                        <label htmlFor="otherPurpose" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                            Please specify *
                                                        </label>
                                                        <input
                                                            id="otherPurpose"
                                                            type="text"
                                                            value={otherPurpose}
                                                            onChange={(event) => setOtherPurpose(event.target.value)}
                                                            placeholder="Enter purpose"
                                                            className={inputClass}
                                                            required
                                                        />
                                                    </div>
                                                )}
                                            </>
                                        )}

                                        {/* BUSINESS DETAILS */}
                                        {selectedCertificate?.requiresBusinessDetails && (
                                            <div className="mt-6 border-t border-gray-100 pt-6">
                                                <h3 className="text-base font-semibold text-gray-800">Business Information</h3>
                                                <p className="mt-1 text-sm text-gray-500">Enter the business information required for this certificate.</p>
                                                <div className="mt-4 space-y-4">
                                                    <div>
                                                        <label className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                            Business Owner
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={resident.full_name}
                                                            readOnly
                                                            aria-readonly="true"
                                                            className={`${inputClass} cursor-not-allowed bg-gray-50 text-gray-600`}
                                                        />
                                                        <p className="mt-2 text-xs text-gray-500">
                                                            The verified Resident account holder is used as the business owner.
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label htmlFor="businessName" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                            Business Name *
                                                        </label>
                                                        <input
                                                            id="businessName"
                                                            type="text"
                                                            value={businessName}
                                                            onChange={(event) => setBusinessName(event.target.value)}
                                                            className={inputClass}
                                                            required
                                                        />
                                                        {errors.business_name && <p className="mt-2 text-sm text-red-600">{errors.business_name}</p>}
                                                    </div>
                                                    <div>
                                                        <label htmlFor="businessAddress" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                            Business Address *
                                                        </label>
                                                        <input
                                                            id="businessAddress"
                                                            type="text"
                                                            value={businessAddress}
                                                            onChange={(event) => setBusinessAddress(event.target.value)}
                                                            className={inputClass}
                                                            required
                                                        />
                                                        {errors.business_address && <p className="mt-2 text-sm text-red-600">{errors.business_address}</p>}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* CERTIFICATE-SPECIFIC DETAILS */}
                                        {detailsFields.length > 0 && (
                                            <div className="mt-6 border-t border-gray-100 pt-6">
                                                <h3 className="text-base font-semibold text-gray-800">
                                                    {selectedCertificate?.certificateType ?? 'Certificate'} Information
                                                </h3>
                                                <p className="mt-1 text-sm text-gray-500">
                                                    These details are printed on the certificate. Please review them carefully.
                                                </p>
                                                <div className="mt-4 space-y-4">
                                                    {detailsFields.map((field) => (
                                                        <div key={field.key}>
                                                            <label
                                                                htmlFor={`detail-${field.key}`}
                                                                className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                                            >
                                                                {field.label}
                                                                {field.required && ' *'}
                                                            </label>
                                                            <input
                                                                id={`detail-${field.key}`}
                                                                type={field.type}
                                                                value={details[field.key] ?? ''}
                                                                onChange={(event) => {
                                                                    const value = event.target.value;
                                                                    setDetails((current) => ({
                                                                        ...current,
                                                                        [field.key]: value,
                                                                    }));
                                                                }}
                                                                min={field.min}
                                                                max={field.max}
                                                                step={
                                                                    field.type === 'number'
                                                                        ? field.kind === 'numeric'
                                                                            ? '0.01'
                                                                            : '1'
                                                                        : undefined
                                                                }
                                                                placeholder={field.placeholder}
                                                                className={inputClass}
                                                                required={field.required}
                                                            />
                                                            {errors[`details.${field.key}`] && (
                                                                <p className="mt-2 text-sm text-red-600">
                                                                    {errors[`details.${field.key}`]}
                                                                </p>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* ADDITIONAL DETAILS */}
                                        <div className="mt-5">
                                            <label htmlFor="additionalDetails" className="mb-2 block text-sm font-medium text-gray-700 sm:text-base">
                                                Additional Details
                                                <span className="ml-1 text-xs font-normal text-gray-400 sm:text-sm">(Optional)</span>
                                            </label>
                                            <textarea
                                                id="additionalDetails"
                                                rows={3}
                                                value={additionalDetails}
                                                onChange={(event) => setAdditionalDetails(event.target.value)}
                                                placeholder="Enter additional information if needed"
                                                className={`${inputClass} resize-none py-3`}
                                            />
                                            {errors.additional_details && <p className="mt-2 text-sm text-red-600">{errors.additional_details}</p>}
                                        </div>

                                        <button
                                            type="submit"
                                            className="mt-6 flex min-h-12 w-full items-center justify-center rounded-lg bg-[#3f6f3d] px-5 text-base font-semibold text-white transition hover:bg-[#315b30]"
                                        >
                                            Continue
                                        </button>
                                        <button type="button" onClick={() => void saveOfflineDraft()} disabled={processing || !selectedCertificate} className="mt-3 min-h-11 w-full rounded-lg border border-[#3f6f3d] px-4 font-semibold text-[#3f6f3d] disabled:opacity-50">Save draft on this device</button>
                                    </section>
                                </form>
                            </div>
                        )}

                        {/* REVIEW */}
                        {step === 'review' && selectedCertificate && (
                            <div className="mx-auto w-full max-w-3xl">
                                <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-6">
                                    <h2 className="text-xl font-semibold text-gray-800">Review Your Request</h2>
                                    <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                                        Check the information before submitting your request.
                                    </p>

                                    <div className="mt-5 sm:mt-6">
                                        <ReviewRow label="Resident" value={resident.full_name} />
                                        <ReviewRow label="KaBarangay ID" value={resident.resident_number} />
                                        <ReviewRow label="Certificate" value={selectedCertificate.name} />
                                        <ReviewRow label="Purpose" value={effectivePurpose} />

                                        {selectedCertificate.requiresBusinessDetails && (
                                            <>
                                                <ReviewRow label="Business Owner" value={resident.full_name} />
                                                <ReviewRow label="Business Name" value={businessName} />
                                                <ReviewRow label="Business Address" value={businessAddress} />
                                            </>
                                        )}

                                        {detailsFields.map((field) =>
                                            (details[field.key] ?? '').trim() !== '' ? (
                                                <ReviewRow
                                                    key={field.key}
                                                    label={field.label}
                                                    value={details[field.key]}
                                                />
                                            ) : null,
                                        )}

                                        {additionalDetails && (
                                            <ReviewRow label="Additional Details" value={additionalDetails} />
                                        )}
                                        
                                        <ReviewRow
                                            label="Fee"
                                            value={selectedCertificate.fee > 0 ? `₱${selectedCertificate.fee.toLocaleString()}` : 'Free'}
                                            last
                                        />
                                    </div>

                                    <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                        <button type="button" onClick={() => void saveOfflineDraft()} disabled={processing} className="min-h-12 rounded-lg border border-[#3f6f3d] px-4 font-semibold text-[#3f6f3d] disabled:opacity-50">Save draft on this device</button>
                                        <button
                                            type="button"
                                            onClick={() => setStep('form')}
                                            disabled={processing}
                                            className="min-h-12 w-full rounded-lg border border-gray-300 px-6 text-base font-semibold text-gray-600 transition hover:bg-gray-50 disabled:opacity-50 sm:w-auto"
                                        >
                                            Back
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleSubmitRequest}
                                            disabled={processing}
                                            className="min-h-12 w-full rounded-lg bg-[#3f6f3d] px-6 text-base font-semibold text-white transition hover:bg-[#315b30] disabled:opacity-50 sm:w-auto"
                                        >
                                            {processing ? 'Submitting...' : 'Submit Request'}
                                        </button>
                                    </div>
                                </section>
                            </div>
                        )}

                        {/* SUCCESS */}
                        {step === 'success' && submittedRequest && (
                            <div className="mx-auto w-full max-w-xl">
                                <section className="rounded-xl border border-gray-200 bg-white p-5 text-center sm:p-7">
                                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#3f6f3d] text-white">
                                        <CheckLargeIcon />
                                    </div>
                                    <h2 className="mt-5 text-xl font-semibold text-gray-800 sm:text-2xl">Request Submitted</h2>
                                    <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                                        Your certificate request has been submitted for barangay review.
                                    </p>
                                    <div className="mt-6 rounded-xl bg-[#f7f8f5] p-4 text-left sm:p-5">
                                        <InfoItem label="Request Number" value={submittedRequest.reference} />
                                        <div className="my-4 border-t border-gray-200" />
                                        <InfoItem label="Certificate" value={submittedRequest.certificate} />
                                        <div className="my-4 border-t border-gray-200" />
                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <span className="text-sm text-gray-500">Status</span>
                                            <StatusBadge status={submittedRequest.status} />
                                        </div>
                                    </div>
                                    <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <button
                                            type="button"
                                            onClick={resetForm}
                                            className="min-h-12 rounded-lg border border-[#3f6f3d] px-5 text-base font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea]"
                                        >
                                            Request Another
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setActiveTab('requests')}
                                            className="min-h-12 rounded-lg bg-[#3f6f3d] px-5 text-base font-semibold text-white transition hover:bg-[#315b30]"
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
                            <h2 className="text-xl font-semibold text-gray-800">My Requests</h2>
                            <p className="mt-1 text-sm leading-6 text-gray-500 sm:text-base">
                                View and track your submitted certificate requests.
                            </p>
                        </div>
                        {requests.data.length > 0 ? (
                            <div className="space-y-4">
                                {requests.data.map((request) => (
                                    <div key={request.id} className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0">
                                                <p className="break-words text-base font-semibold text-gray-800 sm:text-lg">
                                                    {request.certificate}
                                                </p>
                                                <p className="mt-1 break-all text-sm font-medium text-gray-500">
                                                    {request.reference}
                                                </p>
                                                <p className="mt-1 text-sm text-gray-400">Submitted: {request.date}</p>
                                            </div>
                                            <div className="flex flex-col gap-3 sm:items-end">
                                                <StatusBadge status={request.status} />
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedRequest(request)}
                                                    className="min-h-11 w-full rounded-lg border border-[#3f6f3d] px-4 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea] sm:w-auto"
                                                >
                                                    View Details
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                <Pagination paginator={requests} label="Certificate request" />
                            </div>
                        ) : (
                            <div className="rounded-xl border border-gray-200 bg-white p-7 text-center">
                                <CertificateOutlineIcon />
                                <h3 className="mt-4 text-lg font-semibold text-gray-800">No certificate requests</h3>
                                <p className="mt-2 text-sm leading-6 text-gray-500">
                                    Your submitted requests will appear here.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => {
                                        resetForm();
                                        setActiveTab('request');
                                    }}
                                    className="mt-5 min-h-12 w-full rounded-lg bg-[#3f6f3d] px-5 text-base font-semibold text-white sm:w-auto"
                                >
                                    Request Certificate
                                </button>
                            </div>
                        )}
                    </section>
                )}

                {/* ======================================================
                    DETAILS MODAL
                ====================================================== */}
                {selectedRequest && (
                    <RequestDetailsModal request={selectedRequest} onClose={() => setSelectedRequest(null)} />
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
    'min-h-12 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/20 sm:px-4 sm:text-base';

/*
|--------------------------------------------------------------------------
| Request Details Modal
|--------------------------------------------------------------------------
*/

function RequestDetailsModal({ request, onClose }: { request: CertificateRequest; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center sm:p-4">
            <div className="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-w-3xl sm:rounded-2xl">
                {/* HEADER */}
                <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-4 sm:px-5">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-gray-800 sm:text-xl">Request Details</h2>
                        <p className="mt-1 break-all text-sm font-medium text-gray-500">{request.reference}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close request details"
                        className="ml-3 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100"
                    >
                        <CloseIcon />
                    </button>
                </div>

                {/* CONTENT */}
                <div className="flex-1 overflow-y-auto p-4 sm:p-6">
                    <div className="rounded-xl border border-gray-200 bg-[#fafaf7] p-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <InfoItem label="Certificate" value={request.certificate} />
                            <InfoItem label="Purpose" value={request.purpose} />
                            <InfoItem label="Submitted" value={request.date} />
                            <InfoItem
                                label="Fee"
                                value={request.fee > 0 ? `₱${request.fee.toLocaleString()}` : 'Free'}
                            />
                            {request.businessName && <InfoItem label="Business Name" value={request.businessName} />}
                            {request.businessAddress && <InfoItem label="Business Address" value={request.businessAddress} />}
                            {request.details?.map((detail) => (
                                <InfoItem key={detail.label} label={detail.label} value={detail.value} />
                            ))}
                        </div>
                        {request.additionalDetails && (
                            <>
                                <div className="my-4 border-t border-gray-200" />
                                <InfoItem label="Additional Details" value={request.additionalDetails} />
                            </>
                        )}
                        {request.adminNote && (
                            <>
                                <div className="my-4 border-t border-gray-200" />
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                    <p className="text-sm font-semibold text-amber-800">Barangay Note</p>
                                    <p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-amber-900">
                                        {request.adminNote}
                                    </p>
                                </div>
                            </>
                        )}
                        <div className="my-4 border-t border-gray-200" />
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span className="text-sm text-gray-500">Current Status</span>
                            <StatusBadge status={request.status} />
                        </div>
                    </div>

                    {/* TRACK STATUS */}
                    <div className="mt-6">
                        <h3 className="text-lg font-semibold text-gray-800">Track Status</h3>
                        <p className="mt-1 text-sm leading-6 text-gray-500">Follow the progress of your certificate request.</p>
                        <div className="mt-5">
                            <RequestTracker status={request.status} />
                        </div>
                    </div>
                </div>

                {/* FOOTER */}
                <div className="shrink-0 border-t border-gray-200 p-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="min-h-11 w-full rounded-lg bg-[#3f6f3d] px-5 text-sm font-semibold text-white transition hover:bg-[#315b30] sm:ml-auto sm:block sm:w-auto"
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
                        <p className="font-semibold text-red-700">Request Rejected</p>
                        <p className="mt-1 text-sm leading-6 text-red-600">
                            Your request could not be processed. Please check the provided reason or contact the barangay office.
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
                        <p className="font-semibold text-orange-700">Action Required</p>
                        <p className="mt-1 text-sm leading-6 text-orange-600">
                            Additional or corrected information is needed before processing can continue.
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
                                    className={`absolute left-[17px] top-9 h-[calc(100%-1px)] w-[2px] ${
                                        index < currentStep ? 'bg-[#3f6f3d]' : 'bg-gray-200'
                                    }`}
                                />
                            )}
                            <div
                                className={`relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 ${
                                    completed
                                        ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                        : current
                                          ? 'border-[#3f6f3d] bg-[#e8f2e5] text-[#3f6f3d]'
                                          : 'border-gray-300 bg-white text-gray-400'
                                }`}
                            >
                                <TrackerIcon step={step} completed={completed} />
                            </div>
                            <div className="min-w-0 pb-6">
                                <p className={`text-sm font-semibold ${completed || current ? 'text-gray-800' : 'text-gray-400'}`}>
                                    {step}
                                </p>
                                {completed && <p className="mt-1 text-xs text-gray-500">Completed</p>}
                                {current && <p className="mt-1 text-xs font-semibold text-[#3f6f3d]">Current status</p>}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* TABLET / DESKTOP TRACKER */}
            <div className="hidden overflow-x-auto pb-2 sm:block">
                <div className={steps.length === 6 ? 'flex min-w-[690px] items-start px-2' : 'flex min-w-[560px] items-start px-2'}>
                    {steps.map((step, index) => {
                        const completed = index < currentStep;
                        const current = index === currentStep;
                        const reached = index <= currentStep;

                        return (
                            <div key={step} className="relative flex flex-1 flex-col items-center">
                                {index > 0 && (
                                    <div
                                        className={`absolute right-1/2 top-5 h-[3px] w-full ${reached ? 'bg-[#3f6f3d]' : 'bg-gray-200'}`}
                                    />
                                )}
                                <div
                                    className={`relative z-10 flex h-10 w-10 items-center justify-center rounded-full border-2 ${
                                        completed
                                            ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                            : current
                                              ? 'border-[#3f6f3d] bg-[#e8f2e5] text-[#3f6f3d]'
                                              : 'border-gray-300 bg-white text-gray-400'
                                    }`}
                                >
                                    <TrackerIcon step={step} completed={completed} />
                                </div>
                                <p
                                    className={`mt-2 max-w-[105px] text-center text-xs font-semibold leading-4 ${
                                        completed || current ? 'text-gray-800' : 'text-gray-400'
                                    }`}
                                >
                                    {step}
                                </p>
                                {current && <span className="mt-1 text-[11px] font-semibold text-[#3f6f3d]">Current</span>}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* STATUS MESSAGE */}
            <div className="mt-4 rounded-xl border border-[#d8e8d4] bg-[#f4f8f2] p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">
                        <CurrentStatusIcon status={status} />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-gray-800">{getFriendlyStatusTitle(status)}</p>
                        <p className="mt-1 text-sm leading-6 text-gray-600">{getStatusMessage(status)}</p>
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

function TrackerIcon({ step, completed }: { step: string; completed: boolean }) {
    if (completed) {
        return (
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
                <path d="M5 12.5l4 4L19 7" />
            </svg>
        );
    }

    if (step === 'Submitted') {
        return (
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" />
                <path d="M14 3.5V8h4" />
                <path d="M9 12h6" />
                <path d="M9 16h6" />
            </svg>
        );
    }

    if (step === 'For Payment') {
        return <span className="text-sm font-bold leading-none">₱</span>;
    }

    if (step === 'Processing') {
        return (
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
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
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <rect x="5" y="4" width="14" height="17" rx="2" />
                <path d="m8.5 13 2.2 2.2 4.8-5" />
            </svg>
        );
    }

    if (step === 'Released') {
        return (
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
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
        return <span className="text-sm font-bold">₱</span>;
    }

    if (status === 'Processing') {
        return (
            <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
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
        case 'Pending': return 'Request Submitted';
        case 'For Payment': return 'Payment Required';
        case 'Processing': return 'Certificate Processing';
        case 'Ready for Claim': return 'Ready for Claim';
        case 'Released': return 'Certificate Released';
        case 'Needs Action': return 'Action Required';
        case 'Rejected': return 'Request Rejected';
        default: return 'Request Status';
    }
}

/*
|--------------------------------------------------------------------------
| Status Message
|--------------------------------------------------------------------------
*/

function getStatusMessage(status: RequestStatus) {
    switch (status) {
        case 'Pending': return 'Your request has been received and is waiting for review.';
        case 'For Payment': return 'Please complete the required payment before processing can continue.';
        case 'Processing': return 'Your certificate is currently being prepared.';
        case 'Ready for Claim': return 'Your certificate is ready. Please proceed to the barangay office to claim it.';
        case 'Released': return 'Your certificate has been successfully released.';
        default: return '';
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
            <p className="text-xs font-medium text-gray-500 sm:text-sm">{label}</p>
            <p className="mt-1 break-words text-sm font-semibold text-gray-800 sm:text-base">{value}</p>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Review Row
|--------------------------------------------------------------------------
*/

function ReviewRow({ label, value, last = false }: { label: string; value: string; last?: boolean }) {
    return (
        <div className={`flex flex-col gap-1 py-4 sm:flex-row sm:justify-between sm:gap-6 ${!last ? 'border-b border-gray-100' : ''}`}>
            <span className="text-sm text-gray-500">{label}</span>
            <span className="break-words text-sm font-medium text-gray-800 sm:max-w-[65%] sm:text-right sm:text-base">
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
        Pending: 'bg-[#fff4d8] text-[#8a6700]',
        'Needs Action': 'bg-[#fff0e5] text-[#a65316]',
        'For Payment': 'bg-[#f3ecff] text-[#6f42a5]',
        Processing: 'bg-[#eef3fb] text-[#416b9c]',
        'Ready for Claim': 'bg-[#edf4ea] text-[#3f6f3d]',
        Released: 'bg-gray-100 text-gray-600',
        Rejected: 'bg-[#fdecec] text-[#a33a3a]',
    };

    return (
        <span className={`inline-flex w-fit items-center rounded-full px-3 py-1.5 text-xs font-semibold sm:text-sm ${styles[status]}`}>
            {status}
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function CheckLargeIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 12.5l4 4L19 7" />
        </svg>
    );
}

function CheckSmallIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-3.5 w-3.5" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 12.5l4 4L19 7" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 6l12 12" />
            <path d="M18 6 6 18" />
        </svg>
    );
}

function AlertIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 3 2.8 19h18.4L12 3Z" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
    );
}

function CertificateOutlineIcon({ small = false }: { small?: boolean }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={small ? 'h-4 w-4' : 'mx-auto h-10 w-10 text-[#3f6f3d]'} stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M9 13h6" />
            <path d="M9 17h4" />
        </svg>
    );
}
