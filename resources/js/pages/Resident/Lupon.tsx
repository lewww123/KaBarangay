import { EvidenceInput, EvidenceList } from '@/components/lupon/Evidence';
import type { LuponEvidenceFile } from '@/components/lupon/types';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Deferred, useForm, usePoll } from '@inertiajs/react';
import Pagination from '@/components/Pagination';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import type { PaginatedData } from '@/types';

type ComplaintTab = 'file' | 'complaints' | 'against';
type FormStep = 'form' | 'review' | 'success';

type ComplaintAgainstMe = {
    id: number;
    caseNumber: string;
    caseType: string;
    phase: string;
    status: string;
    trackingStatus: string;
    filedDate: string;
    complainantName: string;
    nextHearing?: {
        date: string;
        time?: string | null;
        venue: string;
    } | null;
};

/**
 * Intake statuses only. Hearing, mediation, and case-management statuses are
 * deliberately not part of this batch.
 */
type ComplaintStatus =
    | 'Pending Payment'
    | 'Payment Verification'
    | 'Payment Verified'
    | 'Submitted'
    | 'Under Review'
    | 'For Payment'
    | 'Accepted'
    | 'Rejected';

type Complaint = {
    id: number;
    reference: string;
    respondent: string;
    respondentResidentStatus: string;
    respondentAddress?: string | null;
    respondentContact?: string | null;
    complaintType: string;
    incidentDate: string;
    incidentTime?: string | null;
    incidentLocation: string;
    narrative: string;
    requestedAction: string;
    trackingStatus: string;
    caseProgress?: string | null;
    evidence: LuponEvidenceFile[];
    status: ComplaintStatus;
    luponNote?: string | null;
    assessedFee?: string | null;
    submittedDate: string;
    case?: {
        id: number;
        caseNumber: string;
        phase: string;
        status: string;
        paymentStatus?: string;
        orNumber?: string | null;
        feeAmount?: string | number | null;
    } | null;
};

type ComplaintForm = {
    submission_token: string;
    evidence: File[];
    respondentName: string;
    respondentResidentStatus: string;
    respondentAddress: string;
    respondentContact: string;
    complaintType: string;
    incidentDate: string;
    incidentTime: string;
    incidentLocation: string;
    narrative: string;
    requestedAction: string;
};

const emptyForm: ComplaintForm = {
    submission_token: '',
    evidence: [],
    respondentName: '',
    respondentResidentStatus: '',
    respondentAddress: '',
    respondentContact: '',
    complaintType: '',
    incidentDate: '',
    incidentTime: '',
    incidentLocation: '',
    narrative: '',
    requestedAction: '',
};

type ResidentProps = {
    id: number;
    fullName: string;
    residentNumber: string;
    address: string;
};

type PageProps = {
    resident: ResidentProps;
    complaintTypes: string[];
    complaints: PaginatedData<Complaint>;
    complaintsAgainstMe?: PaginatedData<ComplaintAgainstMe>;
    flash?: { success?: string | null };
};

export default function Lupon(props: PageProps) {
    return (
        <Deferred
            data={['resident', 'complaints', 'complaintsAgainstMe']}
            fallback={
                <ResidentLayout active="lupon">
                    <PortalPageSkeleton list showTabs />
                </ResidentLayout>
            }
        >
            <LuponContent {...props} />
        </Deferred>
    );
}

function LuponContent({
    resident,
    complaintTypes,
    complaints,
    complaintsAgainstMe,
    flash,
}: PageProps) {
    usePoll(15000, { only: ['complaints', 'complaintsAgainstMe'] });
    useEffect(() => {
        setSelectedComplaint((current) =>
            current
                ? (complaints.data.find((item) => item.id === current.id) ??
                  current)
                : null,
        );
    }, [complaints]);
    const [activeTab, setActiveTab] = useState<ComplaintTab>('file');
    const [step, setStep] = useState<FormStep>('form');
    const [selectedComplaint, setSelectedComplaint] =
        useState<Complaint | null>(null);
    const [showTracking, setShowTracking] = useState(false);

    const { data, setData, post, processing, errors, reset } =
        useForm<ComplaintForm>({ ...emptyForm, submission_token: globalThis.crypto.randomUUID() });

    const canContinue = useMemo(
        () =>
            data.respondentName.trim() !== '' &&
            data.respondentResidentStatus !== '' &&
            data.complaintType !== '' &&
            data.incidentDate !== '' &&
            data.incidentLocation.trim() !== '' &&
            data.narrative.trim() !== '' &&
            data.requestedAction.trim() !== '',
        [data],
    );

    const updateForm = (field: keyof ComplaintForm, value: string) => {
        setData(field, value);
    };

    const handleContinue = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!canContinue) {
            return;
        }

        setStep('review');

        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /**
     * Submit the complaint to the server. The server assigns the reference
     * number and stores the complaint as "Submitted".
     */
    const handleSubmitComplaint = () => {
        post('/resident/lupon/complaints', {
            forceFormData: true,
            onError: () => setStep('form'),
            preserveScroll: false,
            onSuccess: () => {
                setStep('success');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
        });
    };

    const handleNewComplaint = () => {
        reset();
        setData('submission_token', globalThis.crypto.randomUUID());
        setSelectedComplaint(null);
        setShowTracking(false);
        setStep('form');
        setActiveTab('file');
    };

    const openMyComplaints = () => {
        setSelectedComplaint(null);
        setShowTracking(false);
        setActiveTab('complaints');
        setStep('form');

        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const justSubmitted = complaints.data[0] ?? null;

    return (
        <ResidentLayout active="lupon">
            <div className="mx-auto w-full max-w-7xl">
                <div className="mb-5 sm:mb-6">
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Lupon Assistance
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        File a complaint and keep track of your complaint with
                        the Lupong Tagapamayapa.
                    </p>
                </div>

                {flash?.success && (
                    <div className="mb-5 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-sm">
                    <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-3">
                        <button
                            type="button"
                            onClick={() => {
                                setActiveTab('file');
                                setSelectedComplaint(null);
                                setShowTracking(false);

                                if (step === 'success') {
                                    setStep('form');
                                }
                            }}
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab === 'file'
                                    ? 'bg-brand text-white shadow-card'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            File a Complaint
                        </button>

                        <button
                            type="button"
                            onClick={openMyComplaints}
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab === 'complaints'
                                    ? 'bg-brand text-white shadow-card'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            My Complaints ({complaints.total})
                        </button>

                        <button
                            type="button"
                            onClick={() => {
                                setSelectedComplaint(null);
                                setShowTracking(false);
                                setActiveTab('against');
                                setStep('form');
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                            }}
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab === 'against'
                                    ? 'bg-brand text-white shadow-card'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            Complaints Against Me (
                            {complaintsAgainstMe?.total ?? 0})
                        </button>
                    </div>
                </div>

                {activeTab === 'file' && (
                    <>
                        {step === 'form' && (
                            <form
                                onSubmit={handleContinue}
                                className="space-y-5"
                            >
                                <SectionCard>
                                    <SectionHeading
                                        number="1"
                                        title="Your Information"
                                        description="Your verified resident information will be used as the complainant."
                                    />

                                    <div className="mt-5 rounded-xl border border-[#d9e7d5] bg-[#f4f8f2] p-4">
                                        <div className="flex items-start gap-3">
                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-brand">
                                                <UserIcon />
                                            </div>

                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-semibold text-gray-900">
                                                        {resident.fullName}
                                                    </p>
                                                    <span className="rounded-full bg-[#dcebd8] px-2 py-0.5 text-[10px] font-bold tracking-wide text-brand uppercase">
                                                        Verified
                                                    </span>
                                                </div>

                                                <p className="mt-1 text-sm text-gray-500">
                                                    {resident.residentNumber}
                                                </p>
                                                <p className="mt-1 text-sm leading-5 text-gray-600">
                                                    {resident.address}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </SectionCard>

                                <SectionCard>
                                    <SectionHeading
                                        number="2"
                                        title="Respondent Information"
                                        description="Tell us who the complaint is against."
                                    />

                                    <div className="mt-5 grid gap-4 md:grid-cols-2">
                                        <Field
                                            label="Respondent Full Name"
                                            required
                                            error={errors.respondentName}
                                        >
                                            <input
                                                type="text"
                                                value={data.respondentName}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'respondentName',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Enter full name"
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Is the respondent a resident of this barangay?"
                                            required
                                            error={
                                                errors.respondentResidentStatus
                                            }
                                        >
                                            <select
                                                value={
                                                    data.respondentResidentStatus
                                                }
                                                onChange={(event) =>
                                                    updateForm(
                                                        'respondentResidentStatus',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">Select</option>
                                                <option value="Yes">Yes</option>
                                                <option value="No">No</option>
                                                <option value="Unknown">
                                                    I don't know
                                                </option>
                                            </select>
                                        </Field>

                                        <Field
                                            label="Respondent Address"
                                            error={errors.respondentAddress}
                                        >
                                            <input
                                                type="text"
                                                value={data.respondentAddress}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'respondentAddress',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Enter address if known"
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Contact Number"
                                            optional
                                            error={errors.respondentContact}
                                        >
                                            <input
                                                type="text"
                                                value={data.respondentContact}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'respondentContact',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="09XX XXX XXXX"
                                                className={inputClass}
                                            />
                                        </Field>
                                    </div>
                                </SectionCard>

                                <SectionCard>
                                    <SectionHeading
                                        number="3"
                                        title="Complaint Details"
                                        description="Provide the important details about what happened."
                                    />

                                    <div className="mt-5 grid gap-4 md:grid-cols-2">
                                        <Field
                                            label="Nature of Complaint"
                                            required
                                            error={errors.complaintType}
                                        >
                                            <select
                                                value={data.complaintType}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'complaintType',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">
                                                    Select complaint type
                                                </option>
                                                {complaintTypes.map((type) => (
                                                    <option
                                                        key={type}
                                                        value={type}
                                                    >
                                                        {type}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field
                                            label="Date of Incident"
                                            required
                                            error={errors.incidentDate}
                                        >
                                            <input
                                                type="date"
                                                value={data.incidentDate}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'incidentDate',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Time of Incident"
                                            optional
                                            error={errors.incidentTime}
                                        >
                                            <input
                                                type="time"
                                                value={data.incidentTime}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'incidentTime',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Place of Incident"
                                            required
                                            error={errors.incidentLocation}
                                        >
                                            <input
                                                type="text"
                                                value={data.incidentLocation}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'incidentLocation',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Where did it happen?"
                                                className={inputClass}
                                            />
                                        </Field>
                                    </div>

                                    <div className="mt-4 space-y-4">
                                        <Field
                                            label="Complaint Details / Narrative"
                                            required
                                            error={errors.narrative}
                                        >
                                            <textarea
                                                rows={5}
                                                value={data.narrative}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'narrative',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Describe what happened clearly and briefly..."
                                                className={`${inputClass} resize-none`}
                                            />

                                            <p className="mt-1.5 text-xs leading-5 text-gray-400">
                                                Include only information that is
                                                relevant to the complaint.
                                            </p>
                                        </Field>

                                        <Field
                                            label="Requested Action"
                                            required
                                            error={errors.requestedAction}
                                        >
                                            <textarea
                                                rows={3}
                                                value={data.requestedAction}
                                                onChange={(event) =>
                                                    updateForm(
                                                        'requestedAction',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="What assistance or resolution are you requesting?"
                                                className={`${inputClass} resize-none`}
                                            />
                                        </Field>
                                    </div>
                                </SectionCard>

                                <div className="rounded-xl border bg-white p-5">
                                    <EvidenceInput
                                        files={data.evidence}
                                        onChange={(files) =>
                                            setData('evidence', files)
                                        }
                                        error={Object.entries(errors)
                                            .filter(([key]) =>
                                                key.startsWith('evidence'),
                                            )
                                            .map(([, value]) => value)
                                            .join(' ')}
                                    />
                                </div>
                                <div className="flex justify-end pb-3">
                                    <button
                                        type="submit"
                                        disabled={!canContinue}
                                        className="min-h-12 w-full rounded-xl bg-[#3f6f3d] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto sm:min-w-36"
                                    >
                                        Continue
                                    </button>
                                </div>
                            </form>
                        )}

                        {step === 'review' && (
                            <div className="space-y-5">
                                <SectionCard>
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-brand">
                                            <ReviewIcon />
                                        </div>

                                        <div>
                                            <h2 className="text-base font-bold text-gray-900 sm:text-lg">
                                                Review Your Complaint
                                            </h2>
                                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                                Please make sure the information
                                                below is correct before
                                                submitting.
                                            </p>
                                        </div>
                                    </div>
                                </SectionCard>

                                <SectionCard>
                                    <ReviewSection
                                        title="Complainant"
                                        items={[
                                            ['Name', resident.fullName],
                                            [
                                                'Resident Number',
                                                resident.residentNumber,
                                            ],
                                            ['Address', resident.address],
                                        ]}
                                    />

                                    <Divider />

                                    <ReviewSection
                                        title="Respondent"
                                        items={[
                                            ['Full Name', data.respondentName],
                                            [
                                                'Barangay Resident',
                                                data.respondentResidentStatus,
                                            ],
                                            [
                                                'Address',
                                                data.respondentAddress ||
                                                    'Not provided',
                                            ],
                                            [
                                                'Contact Number',
                                                data.respondentContact ||
                                                    'Not provided',
                                            ],
                                        ]}
                                    />

                                    <Divider />

                                    <ReviewSection
                                        title="Complaint"
                                        items={[
                                            [
                                                'Nature of Complaint',
                                                data.complaintType,
                                            ],
                                            [
                                                'Date of Incident',
                                                formatInputDate(
                                                    data.incidentDate,
                                                ),
                                            ],
                                            [
                                                'Time of Incident',
                                                data.incidentTime
                                                    ? data.incidentTime
                                                    : 'Not provided',
                                            ],
                                            [
                                                'Place of Incident',
                                                data.incidentLocation,
                                            ],
                                        ]}
                                    />

                                    <Divider />

                                    <div>
                                        <p className="text-sm font-semibold text-ink-strong">
                                            Complaint Details
                                        </p>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                                            {data.narrative}
                                        </p>
                                    </div>

                                    <Divider />

                                    <div>
                                        <p className="text-sm font-semibold text-ink-strong">
                                            Requested Action
                                        </p>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                                            {data.requestedAction}
                                        </p>
                                    </div>
                                </SectionCard>

                                <div className="flex flex-col-reverse gap-3 pb-3 sm:flex-row sm:justify-end">
                                    <button
                                        type="button"
                                        onClick={() => setStep('form')}
                                        className="min-h-12 rounded-xl border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                                    >
                                        Back
                                    </button>

                                    <button
                                        type="button"
                                        onClick={handleSubmitComplaint}
                                        disabled={processing}
                                        className="min-h-12 rounded-xl bg-[#3f6f3d] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {processing
                                            ? 'Submitting...'
                                            : 'Submit Complaint'}
                                    </button>
                                </div>
                            </div>
                        )}

                        {step === 'success' && justSubmitted && (
                            <div className="mx-auto max-w-2xl">
                                <div className="rounded-2xl border border-gray-200 bg-white px-5 py-8 text-center shadow-sm sm:px-8 sm:py-10">
                                    <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e7f2e3] text-brand">
                                        <LargeCheckIcon />
                                    </div>

                                    <h2 className="mt-5 text-xl font-bold text-gray-900 sm:text-2xl">
                                        Complaint Submitted
                                    </h2>

                                    <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-gray-500">
                                        Your complaint has been sent to the
                                        Lupong Tagapamayapa for review.
                                    </p>

                                    <div className="mx-auto mt-6 max-w-md rounded-xl border border-gray-200 bg-gray-50 p-4">
                                        <p className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                            Case Reference
                                        </p>

                                        <p className="mt-1 text-lg font-bold text-brand">
                                            {justSubmitted.reference}
                                        </p>

                                        <div className="my-4 border-t border-gray-200" />

                                        <div className="flex items-center justify-between gap-4 text-left">
                                            <div>
                                                <p className="text-xs text-gray-400">
                                                    Current Status
                                                </p>
                                                <p className="mt-1 text-sm font-semibold text-gray-800">
                                                    {justSubmitted.status}
                                                </p>
                                            </div>

                                            <StatusBadge
                                                status={justSubmitted.status}
                                            />
                                        </div>
                                    </div>

                                    <p className="mx-auto mt-5 max-w-lg text-xs leading-5 text-gray-400">
                                        Keep your case reference number. You can
                                        view future updates under My Complaints.
                                        Open the docketed case to see hearing
                                        schedules and released documents.
                                    </p>

                                    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
                                        <button
                                            type="button"
                                            onClick={openMyComplaints}
                                            className="min-h-11 rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30]"
                                        >
                                            View My Complaints
                                        </button>

                                        <button
                                            type="button"
                                            onClick={handleNewComplaint}
                                            className="min-h-11 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                                        >
                                            File Another Complaint
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </>
                )}

                {activeTab === 'complaints' && (
                    <>
                        {!selectedComplaint && (
                            <div className="space-y-4">
                                <div>
                                    <h2 className="text-base font-bold text-gray-900 sm:text-lg">
                                        My Complaints
                                    </h2>
                                    <p className="mt-1 text-sm text-gray-500">
                                        View your submitted complaints and their
                                        current status.
                                    </p>
                                </div>

                                {complaints.data.length > 0 ? (
                                    <div className="grid gap-4">
                                        {complaints.data.map((complaint) => (
                                            <ComplaintCard
                                                key={complaint.id}
                                                complaint={complaint}
                                                onView={() => {
                                                    setSelectedComplaint(
                                                        complaint,
                                                    );
                                                    setShowTracking(false);

                                                    window.scrollTo({
                                                        top: 0,
                                                        behavior: 'smooth',
                                                    });
                                                }}
                                            />
                                        ))}
                                        <Pagination
                                            paginator={complaints}
                                            label="Complaint"
                                        />
                                    </div>
                                ) : (
                                    <EmptyComplaints
                                        onFile={() => setActiveTab('file')}
                                    />
                                )}
                            </div>
                        )}

                        {selectedComplaint && !showTracking && (
                            <div className="space-y-5">
                                <button
                                    type="button"
                                    onClick={() => setSelectedComplaint(null)}
                                    className="inline-flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
                                >
                                    <BackIcon />
                                    Back to My Complaints
                                </button>

                                <SectionCard>
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                                Case Reference
                                            </p>
                                            <h2 className="mt-1 text-xl font-bold text-gray-900">
                                                {selectedComplaint.reference}
                                            </h2>
                                            <p className="mt-1 text-sm text-gray-500">
                                                Submitted{' '}
                                                {
                                                    selectedComplaint.submittedDate
                                                }
                                            </p>
                                        </div>

                                        <StatusBadge
                                            status={selectedComplaint.status}
                                        />
                                    </div>
                                </SectionCard>

                                {!selectedComplaint.case && (
                                    <p className="rounded-xl border bg-white p-4 text-sm text-slate-600">
                                        Lupon staff will review your complaint
                                        before docketing it and scheduling a
                                        hearing.
                                    </p>
                                )}

                                <SectionCard>
                                    <ReviewSection
                                        title="Case Information"
                                        items={[
                                            [
                                                'Respondent',
                                                selectedComplaint.respondent,
                                            ],
                                            [
                                                'Barangay Resident',
                                                selectedComplaint.respondentResidentStatus,
                                            ],
                                            [
                                                'Nature of Complaint',
                                                selectedComplaint.complaintType,
                                            ],
                                            [
                                                'Incident Date',
                                                selectedComplaint.incidentDate,
                                            ],
                                            [
                                                'Incident Time',
                                                selectedComplaint.incidentTime ||
                                                    'Not provided',
                                            ],
                                            [
                                                'Incident Location',
                                                selectedComplaint.incidentLocation,
                                            ],
                                        ]}
                                    />

                                    <Divider />

                                    <div>
                                        <p className="text-sm font-semibold text-ink-strong">
                                            Complaint Details
                                        </p>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                                            {selectedComplaint.narrative}
                                        </p>
                                    </div>

                                    <Divider />

                                    <div>
                                        <p className="text-sm font-semibold text-ink-strong">
                                            Requested Action
                                        </p>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                                            {selectedComplaint.requestedAction}
                                        </p>
                                    </div>

                                    <EvidenceList
                                        files={selectedComplaint.evidence ?? []}
                                    />
                                    {selectedComplaint.luponNote && (
                                        <>
                                            <Divider />

                                            <div>
                                                <p className="text-sm font-semibold text-ink-strong">
                                                    Note from Lupon
                                                </p>
                                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                                                    {
                                                        selectedComplaint.luponNote
                                                    }
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </SectionCard>

                                <ComplaintPayment
                                    complaint={selectedComplaint}
                                />
                                <div className="flex justify-end">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowTracking(true);
                                            window.scrollTo({
                                                top: 0,
                                                behavior: 'smooth',
                                            });
                                        }}
                                        className="min-h-11 w-full rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30] sm:w-auto"
                                    >
                                        Track Complaint Status
                                    </button>
                                </div>
                            </div>
                        )}

                        {selectedComplaint && showTracking && (
                            <ComplaintTracking
                                complaint={selectedComplaint}
                                onBack={() => {
                                    setShowTracking(false);
                                    window.scrollTo({
                                        top: 0,
                                        behavior: 'smooth',
                                    });
                                }}
                            />
                        )}
                    </>
                )}

                {activeTab === 'against' && (
                    <div className="space-y-4">
                        <div>
                            <h2 className="text-base font-bold text-gray-900 sm:text-lg">
                                Complaints Against Me
                            </h2>
                            <p className="mt-1 text-sm text-gray-500">
                                Official Lupon cases where you have been named
                                and staff-verified as a respondent.
                            </p>
                        </div>

                        {complaintsAgainstMe &&
                        complaintsAgainstMe.data.length > 0 ? (
                            <div className="grid gap-4">
                                {complaintsAgainstMe.data.map((item) => (
                                    <div
                                        key={item.id}
                                        className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#3f6f3d]/50"
                                    >
                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="space-y-1.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-base font-bold text-gray-900">
                                                        {item.caseNumber}
                                                    </span>
                                                    <span className="rounded-full bg-[#e8f1e4] px-2.5 py-0.5 text-xs font-semibold text-brand">
                                                        {item.phase} Phase
                                                    </span>
                                                    <span className="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                                                        {item.trackingStatus}
                                                    </span>
                                                </div>

                                                <p className="text-sm font-semibold text-gray-800">
                                                    Nature of Dispute:{' '}
                                                    {item.caseType}
                                                </p>

                                                <div className="mt-2 grid gap-1 text-xs text-ink-muted sm:grid-cols-2 sm:gap-x-8">
                                                    <p>
                                                        <span className="text-gray-400">
                                                            Complainant:
                                                        </span>{' '}
                                                        {item.complainantName}
                                                    </p>
                                                    <p>
                                                        <span className="text-gray-400">
                                                            Filed Date:
                                                        </span>{' '}
                                                        {item.filedDate}
                                                    </p>
                                                    {item.nextHearing ? (
                                                        <p className="font-medium text-brand sm:col-span-2">
                                                            📅 Next Hearing:{' '}
                                                            {
                                                                item.nextHearing
                                                                    .date
                                                            }
                                                            {item.nextHearing
                                                                .time &&
                                                                ` at ${item.nextHearing.time}`}{' '}
                                                            (
                                                            {
                                                                item.nextHearing
                                                                    .venue
                                                            }
                                                            )
                                                        </p>
                                                    ) : (
                                                        <p className="text-gray-400 italic">
                                                            No hearing scheduled
                                                            yet
                                                        </p>
                                                    )}
                                                </div>
                                            </div>

                                            <a
                                                href={`/resident/lupon/cases/${item.id}`}
                                                className="inline-flex shrink-0 items-center justify-center rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#315b30]"
                                            >
                                                View Proceedings →
                                            </a>
                                        </div>
                                    </div>
                                ))}
                                <Pagination
                                    paginator={complaintsAgainstMe}
                                    label="Case"
                                />
                            </div>
                        ) : (
                            <div className="rounded-2xl border border-dashed border-gray-300 bg-[#fafaf7] p-10 text-center">
                                <span className="text-3xl">🛡️</span>
                                <h3 className="mt-3 text-base font-semibold text-gray-800">
                                    No complaints against you
                                </h3>
                                <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-gray-500">
                                    You have no active cases where you are named
                                    and verified as a respondent. If a case is
                                    filed and staff verifies your account, it
                                    will appear here.
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </ResidentLayout>
    );
}

function ComplaintPayment({ complaint }: { complaint: Complaint }) {
    const amount = complaint.case?.feeAmount ?? complaint.assessedFee;
    const inPaymentStage = [
        'Pending Payment',
        'Payment Verification',
        'Payment Verified',
        'For Payment',
    ].includes(complaint.status);

    if ((!inPaymentStage && !complaint.case) || (!complaint.case && Number(amount ?? 0) <= 0)) {
        return null;
    }

    const paid = complaint.case?.paymentStatus === 'paid';
    return (
        <div
            role="status"
            className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-gray-800"
        >
            <h3 className="font-bold">
                {paid
                    ? 'Filing fee recorded'
                    : complaint.case
                      ? 'Filing fee'
                      : 'For Payment — pay at the barangay office'}
            </h3>
            <p className="mt-2">
                {paid || complaint.case ? 'Filing fee' : 'Amount to Pay'}: ₱
                {Number(amount ?? 0).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                })}
            </p>
            {complaint.case?.orNumber && (
                <p className="mt-1">
                    OR / reference: {complaint.case.orNumber}
                </p>
            )}
            {!complaint.case && (
                <p className="mt-2 leading-6">
                    Dalhin ang reference na{' '}
                    <strong>{complaint.reference}</strong> sa barangay office at
                    bayaran ang assessed filing fee. Kunin ang official receipt.
                    Itatala ng staff ang OR number at unang hearing schedule
                    bago malikha ang case. Walang online payment sa form na ito.
                </p>
            )}
        </div>
    );
}

function ComplaintTracking({
    complaint,
    onBack,
}: {
    complaint: Complaint;
    onBack: () => void;
}) {
    const steps = getTrackingSteps(complaint.status, Boolean(complaint.case));

    return (
        <div className="space-y-5">
            <button
                type="button"
                onClick={onBack}
                className="inline-flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
            >
                <BackIcon />
                Back to Complaint Details
            </button>

            <SectionCard>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                            Complaint Tracking
                        </p>
                        <h2 className="mt-1 text-xl font-bold text-gray-900">
                            {complaint.reference}
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            {complaint.complaintType}
                        </p>
                    </div>

                    <StatusBadge status={complaint.status} />
                </div>
            </SectionCard>

            <div className="rounded-2xl border border-[#d8e6d3] bg-[#f4f8f2] p-4 shadow-sm sm:p-5">
                <p className="text-xs font-semibold tracking-wide text-[#5d8058] uppercase">
                    Current Status
                </p>
                <p className="mt-1 text-lg font-bold text-[#315b30]">
                    {complaint.trackingStatus ?? complaint.status}
                </p>
                {complaint.caseProgress &&
                    complaint.caseProgress !== complaint.trackingStatus && (
                        <p className="mt-1 text-sm leading-6 text-gray-600">
                            Case progress: {complaint.caseProgress}
                        </p>
                    )}
                <p className="mt-2 text-sm leading-6 text-gray-600">
                    {getStatusDescription(complaint.status)}
                </p>
            </div>

            <ComplaintPayment complaint={complaint} />
            {complaint.luponNote && (
                <SectionCard>
                    <p className="text-sm font-semibold text-ink-strong">
                        Note from Lupon
                    </p>
                    <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-600">
                        {complaint.luponNote}
                    </p>
                </SectionCard>
            )}

            {complaint.case && (
                <div className="flex flex-col items-center justify-between gap-4 rounded-2xl border border-[#3f6f3d]/30 bg-[#f4f8f2] p-5 shadow-sm sm:flex-row">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="text-lg">⚖️</span>
                            <span className="font-bold text-gray-900">
                                Case Created: {complaint.case.caseNumber}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-gray-600">
                            This complaint has been docketed.{' '}
                            <a
                                className="font-bold underline"
                                href={`/resident/lupon/cases/${complaint.case.id}`}
                            >
                                Open case tracking, hearings and released
                                documents →
                            </a>
                        </p>
                    </div>

                    <a
                        href={`/resident/lupon/cases/${complaint.case.id}`}
                        className="rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                    >
                        View Case Proceedings →
                    </a>
                </div>
            )}

            <SectionCard>
                <div className="mb-6">
                    <h3 className="font-bold text-gray-900">
                        Complaint Progress
                    </h3>
                    <p className="mt-1 text-sm text-gray-500">
                        Follow the progress of your complaint.
                    </p>
                </div>

                <div className="mx-auto max-w-2xl">
                    {steps.map((item, index) => {
                        const isLast = index === steps.length - 1;

                        return (
                            <div
                                key={item.label}
                                className="relative flex gap-4"
                            >
                                {!isLast && (
                                    <div
                                        className={`absolute top-9 left-[17px] h-[calc(100%-12px)] w-0.5 ${
                                            item.state === 'completed'
                                                ? 'bg-[#7da477]'
                                                : 'bg-gray-200'
                                        }`}
                                    />
                                )}

                                <div className="relative z-10 shrink-0">
                                    {item.state === 'completed' && (
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-[#3f6f3d] text-white shadow-sm">
                                            <CheckIcon />
                                        </div>
                                    )}

                                    {item.state === 'current' && (
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full border-[3px] border-[#3f6f3d] bg-white">
                                            <span className="h-3 w-3 rounded-full bg-[#3f6f3d]" />
                                        </div>
                                    )}

                                    {item.state === 'future' && (
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-gray-300 bg-white">
                                            <span className="h-2.5 w-2.5 rounded-full bg-gray-200" />
                                        </div>
                                    )}
                                </div>

                                <div
                                    className={`min-w-0 flex-1 ${isLast ? 'pb-0' : 'pb-7'}`}
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p
                                            className={`text-sm font-semibold ${
                                                item.state === 'future'
                                                    ? 'text-gray-400'
                                                    : 'text-gray-900'
                                            }`}
                                        >
                                            {item.label}
                                        </p>

                                        {item.state === 'current' && (
                                            <span className="rounded-full bg-[#e8f1e4] px-2 py-0.5 text-[10px] font-bold tracking-wide text-brand uppercase">
                                                Current
                                            </span>
                                        )}
                                    </div>

                                    <p
                                        className={`mt-1 text-xs leading-5 ${
                                            item.state === 'future'
                                                ? 'text-gray-400'
                                                : 'text-gray-500'
                                        }`}
                                    >
                                        {item.description}
                                    </p>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </SectionCard>

            {!complaint.case && (
                <p className="rounded-xl border bg-white p-4 text-sm text-slate-600">
                    A case and its first Kapitan hearing are created only after
                    staff records the barangay payment and official receipt.
                </p>
            )}
        </div>
    );
}

type TrackingState = 'completed' | 'current' | 'future';

type TrackingStep = {
    label: string;
    description: string;
    state: TrackingState;
};

/**
 * Review -> payment -> case creation. Actual proceedings are in the linked case.
 */
function getTrackingSteps(
    status: ComplaintStatus,
    hasCase = false,
): TrackingStep[] {
    const baseSteps = [
        {
            label: 'Complaint Submitted',
            description:
                'Your complaint was successfully submitted to the barangay.',
        },
        {
            label: 'Under Review',
            description:
                'Lupon staff is reviewing the information in your complaint.',
        },
        {
            label: 'Barangay Payment',
            description:
                'After review, pay the assessed filing fee at the barangay and obtain an official receipt.',
        },
        {
            label: 'Case and Kapitan Hearing #1',
            description:
                'Staff records the OR number and first schedule. The case then starts in the Kapitan phase.',
        },
    ];

    if (status === 'Rejected') {
        return [
            { ...baseSteps[0], state: 'completed' },
            {
                label: 'Complaint rejected',
                description: 'Read the note from Lupon for the reason.',
                state: 'current',
            },
        ];
    }

    const currentIndex = hasCase ? 3 : ['For Payment', 'Pending Payment', 'Payment Verification'].includes(status) ? 2 : 1;

    return baseSteps.map((item, index) => {
        let state: TrackingState = 'future';

        if (index < currentIndex) {
            state = 'completed';
        } else if (index === currentIndex) {
            state = 'current';
        }

        return { ...item, state };
    });
}

function ComplaintCard({
    complaint,
    onView,
}: {
    complaint: Complaint;
    onView: () => void;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 sm:p-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-bold text-gray-900">
                            {complaint.reference}
                        </p>
                        <StatusBadge status={complaint.status} />
                    </div>

                    <p className="mt-2 text-sm font-semibold text-gray-800">
                        {complaint.complaintType}
                    </p>

                    <div className="mt-3 grid gap-2 text-sm text-gray-500 sm:grid-cols-2 sm:gap-x-8">
                        <p>
                            <span className="text-gray-400">Respondent:</span>{' '}
                            {complaint.respondent}
                        </p>
                        <p>
                            <span className="text-gray-400">Submitted:</span>{' '}
                            {complaint.submittedDate}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={onView}
                    className="min-h-10 shrink-0 rounded-xl border border-[#bcd2b6] bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-[#f0f6ee]"
                >
                    View Details
                </button>
            </div>
        </div>
    );
}

/**
 * Residents follow three stages only: review, payment, and the official case.
 * Historical intake labels collapse onto the stage they meant.
 */
function statusLabel(status: ComplaintStatus): string {
    if (status === 'Submitted' || status === 'Under Review') {
        return 'Under Review';
    }

    if (
        status === 'Pending Payment' ||
        status === 'Payment Verification' ||
        status === 'For Payment'
    ) {
        return 'For Payment';
    }

    if (status === 'Payment Verified') {
        return 'Case Created';
    }

    return status;
}

function StatusBadge({ status }: { status: ComplaintStatus }) {
    const styles: Record<ComplaintStatus, string> = {
        'Pending Payment': 'bg-orange-50 text-orange-800 border-orange-200',
        'Payment Verification': 'bg-amber-50 text-amber-800 border-amber-200',
        'Payment Verified': 'bg-emerald-50 text-emerald-800 border-emerald-200',
        Submitted: 'bg-blue-50 text-blue-700 border-blue-200',
        'Under Review': 'bg-amber-50 text-amber-700 border-amber-200',
        'For Payment': 'bg-orange-50 text-orange-800 border-orange-200',
        Accepted: 'bg-green-50 text-green-700 border-green-200',
        Rejected: 'bg-red-50 text-red-700 border-red-200',
    };

    const shown = statusLabel(status);

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[11px] font-bold ${styles[shown as ComplaintStatus] ?? styles['Payment Verified']}`}
        >
            {shown}
        </span>
    );
}

function SectionCard({ children }: { children: React.ReactNode }) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 lg:p-6">
            {children}
        </div>
    );
}

function SectionHeading({
    number,
    title,
    description,
}: {
    number: string;
    title: string;
    description: string;
}) {
    return (
        <div className="flex items-start gap-3">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-sm font-bold text-brand">
                {number}
            </div>

            <div>
                <h2 className="font-bold text-gray-900">{title}</h2>
                <p className="mt-1 text-sm leading-5 text-gray-500">
                    {description}
                </p>
            </div>
        </div>
    );
}

function Field({
    label,
    required = false,
    optional = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    optional?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}

                {required && <span className="ml-1 text-red-500">*</span>}

                {optional && (
                    <span className="ml-1 text-xs font-normal text-gray-400">
                        (Optional)
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-xs leading-5 text-red-500">{error}</p>
            )}
        </div>
    );
}

function ReviewSection({
    title,
    items,
}: {
    title: string;
    items: [string, string][];
}) {
    return (
        <div>
            <h3 className="text-sm font-semibold text-ink-strong">{title}</h3>

            <div className="mt-3 grid gap-4 sm:grid-cols-2">
                {items.map(([label, value]) => (
                    <div key={label}>
                        <p className="text-xs text-gray-400">{label}</p>
                        <p className="mt-1 text-sm leading-5 font-medium break-words text-gray-700">
                            {value}
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Divider() {
    return <div className="my-5 border-t border-gray-100" />;
}

function EmptyComplaints({ onFile }: { onFile: () => void }) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-sm">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <ComplaintIcon />
            </div>

            <h3 className="mt-4 font-bold text-gray-900">No complaints yet</h3>

            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
                Complaints you submit will appear here together with their
                current status.
            </p>

            <button
                type="button"
                onClick={onFile}
                className="mt-5 min-h-11 rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30]"
            >
                File a Complaint
            </button>
        </div>
    );
}

function getStatusDescription(status: ComplaintStatus) {
    const descriptions: Record<ComplaintStatus, string> = {
        'Pending Payment': 'Visit the barangay hall to pay the assessed filing fee. No official case exists until the Secretary verifies payment.',
        'Payment Verification': 'The Secretary is checking the actual payment and official receipt.',
        'Payment Verified': 'Payment verified and linked to the official case.',
        Submitted:
            'Your complaint has been submitted and is waiting for Lupon review.',
        'Under Review':
            'Lupon staff is currently reviewing the information in your complaint.',
        'For Payment':
            'Your complaint has been reviewed. Pay the assessed filing fee at the barangay office and obtain an official receipt. Staff must record the OR before your case and first hearing are created.',
        Accepted:
            'Lupon staff accepted your complaint for docketing. Open the linked case when available to see hearing schedules and proceedings.',
        Rejected:
            'Lupon staff rejected the complaint. See the note from Lupon for the reason.',
    };

    return descriptions[status];
}

const inputClass = `
    min-h-11
    w-full
    rounded-xl
    border
    border-gray-300
    bg-white
    px-3.5
    py-2.5
    text-base
    text-gray-800
    outline-none
    transition
    placeholder:text-gray-400
    focus:border-[#3f6f3d]
    focus:ring-2
    focus:ring-[#3f6f3d]/15

    sm:text-sm
`;

function formatInputDate(value: string) {
    if (!value) {
        return '';
    }

    const date = new Date(`${value}T00:00:00`);

    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function UserIcon() {
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
            <path d="M20 21a8 8 0 0 0-16 0" />
            <circle cx="12" cy="7" r="4" />
        </svg>
    );
}

function ReviewIcon() {
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
            <path d="M9 11l3 3L22 4" />
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}

function LargeCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-8 w-8"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}

function BackIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m15 18-6-6 6-6" />
        </svg>
    );
}

function ComplaintIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M21 15a4 4 0 0 1-4 4H8l-5 3 1.5-4.5A8 8 0 1 1 21 15Z" />
            <path d="M12 8v4" />
            <path d="M12 16h.01" />
        </svg>
    );
}
