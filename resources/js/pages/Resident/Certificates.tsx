import { Head } from '@inertiajs/react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type Tab = 'request' | 'requests';

type Step =
    | 'form'
    | 'review'
    | 'success';

type RequestStatus =
    | 'Pending'
    | 'Needs Action'
    | 'For Payment'
    | 'Processing'
    | 'Ready for Claim'
    | 'Released'
    | 'Rejected';

type Certificate = {
    id: string;
    name: string;
    fee: number;
    description: string;
    requirements: string[];
    requiresBusinessDetails?: boolean;
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
};

/*
|--------------------------------------------------------------------------
| Temporary Resident Data
|--------------------------------------------------------------------------
|
| Later, this will come from the authenticated resident database record.
|
*/

const resident = {
    name: 'Juan Dela Cruz',
    bsjId: 'BSJ-2026-0001',
    address: 'Purok 3, Barangay San Jose',
};

const CERTIFICATE_FLOW_KEY = 'kabarangay_certificate_flow';

/*
|--------------------------------------------------------------------------
| Certificates
|--------------------------------------------------------------------------
*/

const certificates: Certificate[] = [
    {
        id: 'residency',
        name: 'Certificate of Residency',
        fee: 100,
        description:
            'Proof that you are a registered resident of Barangay San Jose.',
        requirements: [
            'Approved KaBarangay resident account',
            'Verified resident information',
        ],
    },

    {
        id: 'indigency',
        name: 'Certificate of Indigency',
        fee: 0,
        description:
            'Certification for qualified residents who need proof of indigency.',
        requirements: [
            'Approved KaBarangay resident account',
            'Valid purpose for requesting the certificate',
        ],
    },

    {
        id: 'barangay-clearance',
        name: 'Barangay Clearance',
        fee: 0,
        description:
            'Barangay clearance for personal, employment, or official use.',
        requirements: [
            'Approved KaBarangay resident account',
            'Verified resident information',
        ],
    },

    {
        id: 'retail',
        name: 'Retail',
        fee: 300,
        description:
            'Certificate for retail-related business purposes.',
        requirements: [
            'Approved KaBarangay resident account',
            'Business name',
            'Business address',
        ],
        requiresBusinessDetails: true,
    },

    {
        id: 'tricycle',
        name: 'Tricycle',
        fee: 300,
        description:
            'Certificate for tricycle-related purposes.',
        requirements: [
            'Approved KaBarangay resident account',
            'Valid request purpose',
        ],
    },

    {
        id: 'sari-sari',
        name: 'Sari-Sari Store',
        fee: 500,
        description:
            'Certificate for sari-sari store business operations.',
        requirements: [
            'Approved KaBarangay resident account',
            'Business name',
            'Business address',
        ],
        requiresBusinessDetails: true,
    },

    {
        id: 'hardware',
        name: 'Hardware Store',
        fee: 3000,
        description:
            'Certificate for hardware store business operations.',
        requirements: [
            'Approved KaBarangay resident account',
            'Business name',
            'Business address',
        ],
        requiresBusinessDetails: true,
    },

    {
        id: 'pool-resort',
        name: 'Pool / Resort',
        fee: 1500,
        description:
            'Certificate for pool or resort-related business operations.',
        requirements: [
            'Approved KaBarangay resident account',
            'Business name',
            'Business address',
        ],
        requiresBusinessDetails: true,
    },
];

/*
|--------------------------------------------------------------------------
| Temporary Requests
|--------------------------------------------------------------------------
|
| Later, these will come from the database.
|
*/

const initialRequests: CertificateRequest[] = [
    {
        id: 1,
        reference: 'CERT-2026-0001',
        certificate: 'Certificate of Residency',
        purpose: 'Employment',
        fee: 100,
        status: 'Processing',
        date: 'September 3, 2026',
    },

    {
        id: 2,
        reference: 'CERT-2026-0002',
        certificate: 'Certificate of Indigency',
        purpose: 'Financial Assistance',
        fee: 0,
        status: 'Ready for Claim',
        date: 'September 2, 2026',
    },
];

/*
|--------------------------------------------------------------------------
| Main Page
|--------------------------------------------------------------------------
*/

export default function Certificates() {
    const [activeTab, setActiveTab] =
        useState<Tab>('request');

    const [step, setStep] =
        useState<Step>('form');

    const [certificateId, setCertificateId] =
        useState('');

    const [purpose, setPurpose] =
        useState('');

    const [otherPurpose, setOtherPurpose] =
        useState('');

    const [additionalDetails, setAdditionalDetails] =
        useState('');

    const [businessName, setBusinessName] =
        useState('');

    const [businessAddress, setBusinessAddress] =
        useState('');

    const [requests, setRequests] =
        useState<CertificateRequest[]>(
            initialRequests,
        );

    const [submittedRequest, setSubmittedRequest] =
        useState<CertificateRequest | null>(null);

    const [selectedRequest, setSelectedRequest] =
        useState<CertificateRequest | null>(null);

    const [cameFromAssistant, setCameFromAssistant] =
        useState(false);

    /*
    |--------------------------------------------------------------------------
    | Load Certificate Selected From KaBarangay Assistant
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        try {
            const savedFlow = window.localStorage.getItem(
                CERTIFICATE_FLOW_KEY,
            );

            if (!savedFlow) return;

            const parsed = JSON.parse(savedFlow) as {
                certificateId?: string;
                source?: string;
            };

            if (parsed.source !== 'chatbot' || !parsed.certificateId) return;

            const certificateExists = certificates.some(
                (certificate) => certificate.id === parsed.certificateId,
            );

            if (!certificateExists) {
                window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
                return;
            }

            setCertificateId(parsed.certificateId);
            setActiveTab('request');
            setStep('form');
            setCameFromAssistant(true);
        } catch (error) {
            console.error(
                'Unable to load certificate selected by KaBarangay Assistant.',
                error,
            );
        }
    }, []);


    /*
    |--------------------------------------------------------------------------
    | Selected Certificate
    |--------------------------------------------------------------------------
    */

    const selectedCertificate = useMemo(
        () =>
            certificates.find(
                (certificate) =>
                    certificate.id ===
                    certificateId,
            ),
        [certificateId],
    );

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
        setSubmittedRequest(null);
        setStep('form');
    };

    /*
    |--------------------------------------------------------------------------
    | Continue to Review
    |--------------------------------------------------------------------------
    */

    const handleContinue = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (
            !selectedCertificate ||
            !purpose
        ) {
            return;
        }

        if (
            purpose === 'Other' &&
            !otherPurpose.trim()
        ) {
            return;
        }

        if (
            selectedCertificate.requiresBusinessDetails &&
            (
                !businessName.trim() ||
                !businessAddress.trim()
            )
        ) {
            return;
        }

        setStep('review');

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Submit Request
    |--------------------------------------------------------------------------
    */

    const handleSubmitRequest = () => {
        if (!selectedCertificate) {
            return;
        }

        const nextNumber =
            requests.length + 1;

        const reference =
            `CERT-2026-${String(
                nextNumber,
            ).padStart(4, '0')}`;

        const newRequest: CertificateRequest = {
            id: Date.now(),

            reference,

            certificate:
                selectedCertificate.name,

            purpose:
                purpose === 'Other'
                    ? otherPurpose
                    : purpose,

            fee: selectedCertificate.fee,

            status: 'Pending',

            date: 'September 3, 2026',

            businessName:
                businessName || undefined,

            businessAddress:
                businessAddress || undefined,

            additionalDetails:
                additionalDetails || undefined,
        };

        setRequests((current) => [
            newRequest,
            ...current,
        ]);

        setSubmittedRequest(newRequest);

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

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
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
                        Request a barangay certificate or
                        track your submitted request.
                    </p>

                </section>

                {/* ======================================================
                    TABS
                ====================================================== */}

                <div className="mb-6 flex w-full max-w-xl rounded-xl border border-gray-200 bg-white p-1">

                    <button
                        type="button"
                        onClick={() =>
                            setActiveTab(
                                'request',
                            )
                        }
                        className={`min-h-12 flex-1 rounded-lg px-2 text-sm font-semibold transition sm:px-4 sm:text-base ${
                            activeTab ===
                            'request'
                                ? 'bg-[#3f6f3d] text-white'
                                : 'text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        Request Certificate
                    </button>

                    <button
                        type="button"
                        onClick={() =>
                            setActiveTab(
                                'requests',
                            )
                        }
                        className={`min-h-12 flex-1 rounded-lg px-2 text-sm font-semibold transition sm:px-4 sm:text-base ${
                            activeTab ===
                            'requests'
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

                            <form
                                onSubmit={
                                    handleContinue
                                }
                                className="mx-auto w-full max-w-3xl"
                            >

                                {/* VERIFIED RESIDENT */}

                                <section className="mb-4 rounded-xl border border-gray-200 bg-white p-4 sm:mb-5 sm:p-6">

                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                                        <div>

                                            <h2 className="text-lg font-semibold text-gray-800">
                                                Your Information
                                            </h2>

                                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                                Taken from your verified
                                                resident profile.
                                            </p>

                                        </div>

                                        <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-[#edf4ea] px-3 py-1.5 text-sm font-semibold text-[#3f6f3d]">

                                            <CheckSmallIcon />

                                            Verified

                                        </span>

                                    </div>

                                    <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">

                                        <InfoItem
                                            label="Resident Name"
                                            value={
                                                resident.name
                                            }
                                        />

                                        <InfoItem
                                            label="BSJ ID"
                                            value={
                                                resident.bsjId
                                            }
                                        />

                                        <div className="sm:col-span-2">

                                            <InfoItem
                                                label="Address"
                                                value={
                                                    resident.address
                                                }
                                            />

                                        </div>

                                    </div>

                                </section>

                                {/* REQUEST DETAILS */}

                                <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-6">

                                    <h2 className="text-lg font-semibold text-gray-800">
                                        Request Details
                                    </h2>

                                    <p className="mt-1 text-sm leading-6 text-gray-500">
                                        Enter only the information
                                        needed for this request.
                                    </p>

                                    {/* CERTIFICATE TYPE */}

                                    <div className="mt-5 sm:mt-6">

                                        <label
                                            htmlFor="certificate"
                                            className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                        >
                                            Certificate Type *
                                        </label>

                                        <select
                                            id="certificate"
                                            value={
                                                certificateId
                                            }
                                            onChange={(
                                                event,
                                            ) => {
                                                setCertificateId(
                                                    event
                                                        .target
                                                        .value,
                                                );

                                                setBusinessName(
                                                    '',
                                                );

                                                setBusinessAddress(
                                                    '',
                                                );
                                            }}
                                            className={
                                                inputClass
                                            }
                                            required
                                        >

                                            <option value="">
                                                Select certificate
                                            </option>

                                            {certificates.map(
                                                (
                                                    certificate,
                                                ) => (
                                                    <option
                                                        key={
                                                            certificate.id
                                                        }
                                                        value={
                                                            certificate.id
                                                        }
                                                    >
                                                        {
                                                            certificate.name
                                                        }
                                                    </option>
                                                ),
                                            )}

                                        </select>

                                    </div>

                                    {/* CERTIFICATE INFORMATION */}

                                    {selectedCertificate && (
                                        <div className="mt-4 rounded-xl bg-[#f7f8f5] p-4">

                                            <p className="text-base font-semibold text-gray-800">
                                                {
                                                    selectedCertificate.name
                                                }
                                            </p>

                                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                                {
                                                    selectedCertificate.description
                                                }
                                            </p>

                                            <div className="mt-4">

                                                <p className="text-sm font-semibold text-gray-700">
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
                                                                className="flex items-start gap-2 text-sm leading-6 text-gray-600"
                                                            >

                                                                <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-[#3f6f3d] text-white">

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

                                            <div className="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">

                                                <span className="text-sm font-medium text-gray-600">
                                                    Fee
                                                </span>

                                                <span className="text-lg font-semibold text-[#3f6f3d]">

                                                    {selectedCertificate.fee >
                                                    0
                                                        ? `₱${selectedCertificate.fee.toLocaleString()}`
                                                        : 'Free'}

                                                </span>

                                            </div>

                                        </div>
                                    )}

                                    {/* PURPOSE */}

                                    <div className="mt-5">

                                        <label
                                            htmlFor="purpose"
                                            className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                        >
                                            Purpose *
                                        </label>

                                        <select
                                            id="purpose"
                                            value={
                                                purpose
                                            }
                                            onChange={(
                                                event,
                                            ) =>
                                                setPurpose(
                                                    event
                                                        .target
                                                        .value,
                                                )
                                            }
                                            className={
                                                inputClass
                                            }
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
                                                Government Requirement
                                            </option>

                                            <option value="Financial Assistance">
                                                Financial Assistance
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

                                    </div>

                                    {/* OTHER PURPOSE */}

                                    {purpose === 'Other' && (
                                        <div className="mt-5">

                                            <label
                                                htmlFor="otherPurpose"
                                                className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                            >
                                                Please specify *
                                            </label>

                                            <input
                                                id="otherPurpose"
                                                type="text"
                                                value={
                                                    otherPurpose
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    setOtherPurpose(
                                                        event
                                                            .target
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

                                    {/* BUSINESS DETAILS */}

                                    {selectedCertificate?.requiresBusinessDetails && (
                                        <div className="mt-6 border-t border-gray-100 pt-6">

                                            <h3 className="text-base font-semibold text-gray-800">
                                                Business Information
                                            </h3>

                                            <p className="mt-1 text-sm text-gray-500">
                                                Enter the business
                                                information required for
                                                this certificate.
                                            </p>

                                            <div className="mt-4 space-y-4">

                                                <div>

                                                    <label
                                                        htmlFor="businessName"
                                                        className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                                    >
                                                        Business Name *
                                                    </label>

                                                    <input
                                                        id="businessName"
                                                        type="text"
                                                        value={
                                                            businessName
                                                        }
                                                        onChange={(
                                                            event,
                                                        ) =>
                                                            setBusinessName(
                                                                event
                                                                    .target
                                                                    .value,
                                                            )
                                                        }
                                                        className={
                                                            inputClass
                                                        }
                                                        required
                                                    />

                                                </div>

                                                <div>

                                                    <label
                                                        htmlFor="businessAddress"
                                                        className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                                    >
                                                        Business Address *
                                                    </label>

                                                    <input
                                                        id="businessAddress"
                                                        type="text"
                                                        value={
                                                            businessAddress
                                                        }
                                                        onChange={(
                                                            event,
                                                        ) =>
                                                            setBusinessAddress(
                                                                event
                                                                    .target
                                                                    .value,
                                                            )
                                                        }
                                                        className={
                                                            inputClass
                                                        }
                                                        required
                                                    />

                                                </div>

                                            </div>

                                        </div>
                                    )}

                                    {/* ADDITIONAL DETAILS */}

                                    <div className="mt-5">

                                        <label
                                            htmlFor="additionalDetails"
                                            className="mb-2 block text-sm font-medium text-gray-700 sm:text-base"
                                        >
                                            Additional Details

                                            <span className="ml-1 text-xs font-normal text-gray-400 sm:text-sm">
                                                (Optional)
                                            </span>

                                        </label>

                                        <textarea
                                            id="additionalDetails"
                                            rows={3}
                                            value={
                                                additionalDetails
                                            }
                                            onChange={(
                                                event,
                                            ) =>
                                                setAdditionalDetails(
                                                    event
                                                        .target
                                                        .value,
                                                )
                                            }
                                            placeholder="Enter additional information if needed"
                                            className={`${inputClass} resize-none py-3`}
                                        />

                                    </div>

                                    <button
                                        type="submit"
                                        className="mt-6 flex min-h-12 w-full items-center justify-center rounded-lg bg-[#3f6f3d] px-5 text-base font-semibold text-white transition hover:bg-[#315b30]"
                                    >
                                        Continue
                                    </button>

                                </section>

                            </form>
                            </div>
                        )}

                        {/* ==================================================
                            REVIEW
                        ================================================== */}

                        {step === 'review' &&
                            selectedCertificate && (
                                <div className="mx-auto w-full max-w-3xl">

                                    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-6">

                                        <h2 className="text-xl font-semibold text-gray-800">
                                            Review Your Request
                                        </h2>

                                        <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                                            Check the information before
                                            submitting your request.
                                        </p>

                                        <div className="mt-5 sm:mt-6">

                                            <ReviewRow
                                                label="Resident"
                                                value={
                                                    resident.name
                                                }
                                            />

                                            <ReviewRow
                                                label="BSJ ID"
                                                value={
                                                    resident.bsjId
                                                }
                                            />

                                            <ReviewRow
                                                label="Certificate"
                                                value={
                                                    selectedCertificate.name
                                                }
                                            />

                                            <ReviewRow
                                                label="Purpose"
                                                value={
                                                    purpose ===
                                                    'Other'
                                                        ? otherPurpose
                                                        : purpose
                                                }
                                            />

                                            {selectedCertificate.requiresBusinessDetails && (
                                                <>
                                                    <ReviewRow
                                                        label="Business Name"
                                                        value={
                                                            businessName
                                                        }
                                                    />

                                                    <ReviewRow
                                                        label="Business Address"
                                                        value={
                                                            businessAddress
                                                        }
                                                    />
                                                </>
                                            )}

                                            {additionalDetails && (
                                                <ReviewRow
                                                    label="Additional Details"
                                                    value={
                                                        additionalDetails
                                                    }
                                                />
                                            )}

                                            <ReviewRow
                                                label="Fee"
                                                value={
                                                    selectedCertificate.fee >
                                                    0
                                                        ? `₱${selectedCertificate.fee.toLocaleString()}`
                                                        : 'Free'
                                                }
                                                last
                                            />

                                        </div>

                                        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setStep(
                                                        'form',
                                                    )
                                                }
                                                className="min-h-12 w-full rounded-lg border border-gray-300 px-6 text-base font-semibold text-gray-600 transition hover:bg-gray-50 sm:w-auto"
                                            >
                                                Back
                                            </button>

                                            <button
                                                type="button"
                                                onClick={
                                                    handleSubmitRequest
                                                }
                                                className="min-h-12 w-full rounded-lg bg-[#3f6f3d] px-6 text-base font-semibold text-white transition hover:bg-[#315b30] sm:w-auto"
                                            >
                                                Submit Request
                                            </button>

                                        </div>

                                    </section>

                                </div>
                            )}

                        {/* ==================================================
                            SUCCESS
                        ================================================== */}

                        {step === 'success' &&
                            submittedRequest && (
                                <div className="mx-auto w-full max-w-xl">

                                    <section className="rounded-xl border border-gray-200 bg-white p-5 text-center sm:p-7">

                                        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#3f6f3d] text-white">
                                            <CheckLargeIcon />
                                        </div>

                                        <h2 className="mt-5 text-xl font-semibold text-gray-800 sm:text-2xl">
                                            Request Submitted
                                        </h2>

                                        <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                                            Your certificate request
                                            has been submitted for
                                            barangay review.
                                        </p>

                                        <div className="mt-6 rounded-xl bg-[#f7f8f5] p-4 text-left sm:p-5">

                                            <InfoItem
                                                label="Request Number"
                                                value={
                                                    submittedRequest.reference
                                                }
                                            />

                                            <div className="my-4 border-t border-gray-200" />

                                            <InfoItem
                                                label="Certificate"
                                                value={
                                                    submittedRequest.certificate
                                                }
                                            />

                                            <div className="my-4 border-t border-gray-200" />

                                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

                                                <span className="text-sm text-gray-500">
                                                    Status
                                                </span>

                                                <StatusBadge
                                                    status={
                                                        submittedRequest.status
                                                    }
                                                />

                                            </div>

                                        </div>

                                        <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">

                                            <button
                                                type="button"
                                                onClick={
                                                    resetForm
                                                }
                                                className="min-h-12 rounded-lg border border-[#3f6f3d] px-5 text-base font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea]"
                                            >
                                                Request Another
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setActiveTab(
                                                        'requests',
                                                    )
                                                }
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

                            <h2 className="text-xl font-semibold text-gray-800">
                                My Requests
                            </h2>

                            <p className="mt-1 text-sm leading-6 text-gray-500 sm:text-base">
                                View and track your submitted
                                certificate requests.
                            </p>

                        </div>

                        {requests.length > 0 ? (
                            <div className="space-y-4">

                                {requests.map(
                                    (request) => (
                                        <div
                                            key={
                                                request.id
                                            }
                                            className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5"
                                        >

                                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                                                <div className="min-w-0">

                                                    <p className="break-words text-base font-semibold text-gray-800 sm:text-lg">
                                                        {
                                                            request.certificate
                                                        }
                                                    </p>

                                                    <p className="mt-1 break-all text-sm font-medium text-gray-500">
                                                        {
                                                            request.reference
                                                        }
                                                    </p>

                                                    <p className="mt-1 text-sm text-gray-400">
                                                        Submitted:{' '}
                                                        {
                                                            request.date
                                                        }
                                                    </p>

                                                </div>

                                                <div className="flex flex-col gap-3 sm:items-end">

                                                    <StatusBadge
                                                        status={
                                                            request.status
                                                        }
                                                    />

                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setSelectedRequest(
                                                                request,
                                                            )
                                                        }
                                                        className="min-h-11 w-full rounded-lg border border-[#3f6f3d] px-4 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea] sm:w-auto"
                                                    >
                                                        View Details
                                                    </button>

                                                </div>

                                            </div>

                                        </div>
                                    ),
                                )}

                            </div>
                        ) : (
                            <div className="rounded-xl border border-gray-200 bg-white p-7 text-center">

                                <CertificateOutlineIcon />

                                <h3 className="mt-4 text-lg font-semibold text-gray-800">
                                    No certificate requests
                                </h3>

                                <p className="mt-2 text-sm leading-6 text-gray-500">
                                    Your submitted requests
                                    will appear here.
                                </p>

                                <button
                                    type="button"
                                    onClick={() =>
                                        setActiveTab(
                                            'request',
                                        )
                                    }
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
                    <RequestDetailsModal
                        request={
                            selectedRequest
                        }
                        onClose={() =>
                            setSelectedRequest(
                                null,
                            )
                        }
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
    'min-h-12 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/20 sm:px-4 sm:text-base';

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

            <div className="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:max-w-3xl sm:rounded-2xl">

                {/* HEADER */}

                <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-4 sm:px-5">

                    <div className="min-w-0">

                        <h2 className="text-lg font-semibold text-gray-800 sm:text-xl">
                            Request Details
                        </h2>

                        <p className="mt-1 break-all text-sm font-medium text-gray-500">
                            {request.reference}
                        </p>

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

                    {/* INFO */}

                    <div className="rounded-xl border border-gray-200 bg-[#fafaf7] p-4">

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">

                            <InfoItem
                                label="Certificate"
                                value={
                                    request.certificate
                                }
                            />

                            <InfoItem
                                label="Purpose"
                                value={
                                    request.purpose
                                }
                            />

                            <InfoItem
                                label="Submitted"
                                value={
                                    request.date
                                }
                            />

                            <InfoItem
                                label="Fee"
                                value={
                                    request.fee >
                                    0
                                        ? `₱${request.fee.toLocaleString()}`
                                        : 'Free'
                                }
                            />

                            {request.businessName && (
                                <InfoItem
                                    label="Business Name"
                                    value={
                                        request.businessName
                                    }
                                />
                            )}

                            {request.businessAddress && (
                                <InfoItem
                                    label="Business Address"
                                    value={
                                        request.businessAddress
                                    }
                                />
                            )}

                        </div>

                        {request.additionalDetails && (
                            <>
                                <div className="my-4 border-t border-gray-200" />

                                <InfoItem
                                    label="Additional Details"
                                    value={
                                        request.additionalDetails
                                    }
                                />
                            </>
                        )}

                        <div className="my-4 border-t border-gray-200" />

                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

                            <span className="text-sm text-gray-500">
                                Current Status
                            </span>

                            <StatusBadge
                                status={
                                    request.status
                                }
                            />

                        </div>

                    </div>

                    {/* TRACK STATUS */}

                    <div className="mt-6">

                        <h3 className="text-lg font-semibold text-gray-800">
                            Track Status
                        </h3>

                        <p className="mt-1 text-sm leading-6 text-gray-500">
                            Follow the progress of your
                            certificate request.
                        </p>

                        <div className="mt-5">

                            <RequestTracker
                                status={
                                    request.status
                                }
                            />

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

function RequestTracker({
    status,
}: {
    status: RequestStatus;
}) {
    /*
    |--------------------------------------------------------------------------
    | Rejected
    |--------------------------------------------------------------------------
    */

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
                            Your request could not be
                            processed. Please check the
                            provided reason or contact the
                            barangay office.
                        </p>

                    </div>

                </div>

            </div>
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Needs Action
    |--------------------------------------------------------------------------
    */

    if (
        status ===
        'Needs Action'
    ) {
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
                            Additional or corrected
                            information is needed before
                            processing can continue.
                        </p>

                    </div>

                </div>

            </div>
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Normal Steps
    |--------------------------------------------------------------------------
    */

    const steps =
        status === 'For Payment'
            ? [
                  'Submitted',
                  'Reviewed',
                  'For Payment',
                  'Processing',
                  'Ready for Claim',
                  'Released',
              ]
            : [
                  'Submitted',
                  'Reviewed',
                  'Processing',
                  'Ready for Claim',
                  'Released',
              ];

    const currentStep =
        getCurrentStep(
            status,
            steps,
        );

    return (
        <div>

            {/* ==========================================================
                MOBILE TRACKER
            ========================================================== */}

            <div className="sm:hidden">

                {steps.map(
                    (
                        step,
                        index,
                    ) => {
                        const completed =
                            index <
                            currentStep;

                        const current =
                            index ===
                            currentStep;

                        return (
                            <div
                                key={
                                    step
                                }
                                className="relative flex gap-3"
                            >

                                {/* VERTICAL LINE */}

                                {index !==
                                    steps.length -
                                        1 && (
                                    <div
                                        className={`absolute left-[17px] top-9 h-[calc(100%-1px)] w-[2px] ${
                                            index <
                                            currentStep
                                                ? 'bg-[#3f6f3d]'
                                                : 'bg-gray-200'
                                        }`}
                                    />
                                )}

                                {/* CIRCLE */}

                                <div
                                    className={`relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 ${
                                        completed
                                            ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                            : current
                                              ? 'border-[#3f6f3d] bg-[#e8f2e5] text-[#3f6f3d]'
                                              : 'border-gray-300 bg-white text-gray-400'
                                    }`}
                                >

                                    <TrackerIcon
                                        step={
                                            step
                                        }
                                        completed={
                                            completed
                                        }
                                    />

                                </div>

                                {/* LABEL */}

                                <div className="min-w-0 pb-6">

                                    <p
                                        className={`text-sm font-semibold ${
                                            completed ||
                                            current
                                                ? 'text-gray-800'
                                                : 'text-gray-400'
                                        }`}
                                    >
                                        {
                                            step
                                        }
                                    </p>

                                    {completed && (
                                        <p className="mt-1 text-xs text-gray-500">
                                            Completed
                                        </p>
                                    )}

                                    {current && (
                                        <p className="mt-1 text-xs font-semibold text-[#3f6f3d]">
                                            Current status
                                        </p>
                                    )}

                                </div>

                            </div>
                        );
                    },
                )}

            </div>

            {/* ==========================================================
                TABLET / DESKTOP TRACKER
            ========================================================== */}

            <div className="hidden overflow-x-auto pb-2 sm:block">

                <div
                    className={
                        steps.length ===
                        6
                            ? 'flex min-w-[690px] items-start px-2'
                            : 'flex min-w-[560px] items-start px-2'
                    }
                >

                    {steps.map(
                        (
                            step,
                            index,
                        ) => {
                            const completed =
                                index <
                                currentStep;

                            const current =
                                index ===
                                currentStep;

                            const reached =
                                index <=
                                currentStep;

                            return (
                                <div
                                    key={
                                        step
                                    }
                                    className="relative flex flex-1 flex-col items-center"
                                >

                                    {/* LINE */}

                                    {index >
                                        0 && (
                                        <div
                                            className={`absolute right-1/2 top-5 h-[3px] w-full ${
                                                reached
                                                    ? 'bg-[#3f6f3d]'
                                                    : 'bg-gray-200'
                                            }`}
                                        />
                                    )}

                                    {/* CIRCLE */}

                                    <div
                                        className={`relative z-10 flex h-10 w-10 items-center justify-center rounded-full border-2 ${
                                            completed
                                                ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                                : current
                                                  ? 'border-[#3f6f3d] bg-[#e8f2e5] text-[#3f6f3d]'
                                                  : 'border-gray-300 bg-white text-gray-400'
                                        }`}
                                    >

                                        <TrackerIcon
                                            step={
                                                step
                                            }
                                            completed={
                                                completed
                                            }
                                        />

                                    </div>

                                    <p
                                        className={`mt-2 max-w-[105px] text-center text-xs font-semibold leading-4 ${
                                            completed ||
                                            current
                                                ? 'text-gray-800'
                                                : 'text-gray-400'
                                        }`}
                                    >
                                        {
                                            step
                                        }
                                    </p>

                                    {current && (
                                        <span className="mt-1 text-[11px] font-semibold text-[#3f6f3d]">
                                            Current
                                        </span>
                                    )}

                                </div>
                            );
                        },
                    )}

                </div>

            </div>

            {/* ==========================================================
                STATUS MESSAGE
            ========================================================== */}

            <div className="mt-4 rounded-xl border border-[#d8e8d4] bg-[#f4f8f2] p-4">

                <div className="flex items-start gap-3">

                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">

                        <CurrentStatusIcon
                            status={
                                status
                            }
                        />

                    </div>

                    <div>

                        <p className="text-sm font-semibold text-gray-800">
                            {getFriendlyStatusTitle(
                                status,
                            )}
                        </p>

                        <p className="mt-1 text-sm leading-6 text-gray-600">
                            {getStatusMessage(
                                status,
                            )}
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
                aria-hidden="true"
            >
                <path d="M5 12.5l4 4L19 7" />
            </svg>
        );
    }

    if (
        step ===
        'Submitted'
    ) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="M7 3.5h7l4 4V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" />
                <path d="M14 3.5V8h4" />
                <path d="M9 12h6" />
                <path d="M9 16h6" />
            </svg>
        );
    }

    if (
        step ===
        'Reviewed'
    ) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <circle
                    cx="10"
                    cy="10"
                    r="5"
                />

                <path d="m14 14 5 5" />

                <path d="m7.5 10 1.5 1.5 3-3" />
            </svg>
        );
    }

    if (
        step ===
        'For Payment'
    ) {
        return (
            <span className="text-sm font-bold leading-none">
                ₱
            </span>
        );
    }

    if (
        step ===
        'Processing'
    ) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <circle
                    cx="12"
                    cy="12"
                    r="3"
                />

                <path d="M12 2.5v3" />
                <path d="M12 18.5v3" />
                <path d="M2.5 12h3" />
                <path d="M18.5 12h3" />
                <path d="m5.3 5.3 2.1 2.1" />
                <path d="m16.6 16.6 2.1 2.1" />
            </svg>
        );
    }

    if (
        step ===
        'Ready for Claim'
    ) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <rect
                    x="5"
                    y="4"
                    width="14"
                    height="17"
                    rx="2"
                />

                <path d="m8.5 13 2.2 2.2 4.8-5" />
            </svg>
        );
    }

    if (
        step ===
        'Released'
    ) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className="h-4 w-4"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <circle
                    cx="12"
                    cy="12"
                    r="9"
                />

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

function CurrentStatusIcon({
    status,
}: {
    status: RequestStatus;
}) {
    if (
        status ===
        'For Payment'
    ) {
        return (
            <span className="text-sm font-bold">
                ₱
            </span>
        );
    }

    if (
        status ===
        'Processing'
    ) {
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
                <circle
                    cx="12"
                    cy="12"
                    r="3"
                />

                <path d="M12 3v3" />
                <path d="M12 18v3" />
                <path d="M3 12h3" />
                <path d="M18 12h3" />
            </svg>
        );
    }

    if (
        status ===
        'Pending'
    ) {
        return (
            <CertificateOutlineIcon
                small
            />
        );
    }

    return (
        <CheckSmallIcon />
    );
}

/*
|--------------------------------------------------------------------------
| Tracker Logic
|--------------------------------------------------------------------------
*/

function getCurrentStep(
    status: RequestStatus,
    steps: string[],
) {
    if (
        status === 'Pending'
    ) {
        return 0;
    }

    if (
        status ===
        'For Payment'
    ) {
        return steps.indexOf(
            'For Payment',
        );
    }

    if (
        status ===
        'Processing'
    ) {
        return steps.indexOf(
            'Processing',
        );
    }

    if (
        status ===
        'Ready for Claim'
    ) {
        return steps.indexOf(
            'Ready for Claim',
        );
    }

    if (
        status ===
        'Released'
    ) {
        return steps.indexOf(
            'Released',
        );
    }

    return 0;
}

/*
|--------------------------------------------------------------------------
| Friendly Status Title
|--------------------------------------------------------------------------
*/

function getFriendlyStatusTitle(
    status: RequestStatus,
) {
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

function getStatusMessage(
    status: RequestStatus,
) {
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

function InfoItem({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="min-w-0">

            <p className="text-xs font-medium text-gray-500 sm:text-sm">
                {label}
            </p>

            <p className="mt-1 break-words text-sm font-semibold text-gray-800 sm:text-base">
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
            className={`flex flex-col gap-1 py-4 sm:flex-row sm:justify-between sm:gap-6 ${
                !last
                    ? 'border-b border-gray-100'
                    : ''
            }`}
        >

            <span className="text-sm text-gray-500">
                {label}
            </span>

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

function StatusBadge({
    status,
}: {
    status: RequestStatus;
}) {
    const styles: Record<
        RequestStatus,
        string
    > = {
        Pending:
            'bg-[#fff4d8] text-[#8a6700]',

        'Needs Action':
            'bg-[#fff0e5] text-[#a65316]',

        'For Payment':
            'bg-[#f3ecff] text-[#6f42a5]',

        Processing:
            'bg-[#eef3fb] text-[#416b9c]',

        'Ready for Claim':
            'bg-[#edf4ea] text-[#3f6f3d]',

        Released:
            'bg-gray-100 text-gray-600',

        Rejected:
            'bg-[#fdecec] text-[#a33a3a]',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full px-3 py-1.5 text-xs font-semibold sm:text-sm ${styles[status]}`}
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
            aria-hidden="true"
        >
            <path d="M5 12.5l4 4L19 7" />
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
            aria-hidden="true"
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
            aria-hidden="true"
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
            aria-hidden="true"
        >
            <path d="M12 3 2.8 19h18.4L12 3Z" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
    );
}

function CertificateOutlineIcon({
    small = false,
}: {
    small?: boolean;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className={
                small
                    ? 'h-4 w-4'
                    : 'mx-auto h-10 w-10 text-[#3f6f3d]'
            }
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M9 13h6" />
            <path d="M9 17h4" />
        </svg>
    );
}