import { FormEvent, useMemo, useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type ComplaintTab = 'file' | 'complaints';
type FormStep = 'form' | 'review' | 'success';

type ComplaintStatus =
    | 'Submitted'
    | 'Under Review'
    | 'Hearing Scheduled'
    | 'First Hearing'
    | 'Second Hearing'
    | 'Third Hearing'
    | 'Resolved'
    | 'Unresolved';

type Complaint = {
    id: number;
    reference: string;
    respondent: string;
    complaintType: string;
    incidentDate: string;
    incidentTime?: string;
    incidentLocation: string;
    narrative: string;
    requestedAction: string;
    status: ComplaintStatus;
    submittedDate: string;

    hearing?: {
        number: 1 | 2 | 3;
        date: string;
        time: string;
        location: string;
    };
};

type ComplaintForm = {
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

/*
|--------------------------------------------------------------------------
| Mock Logged-in Resident
|--------------------------------------------------------------------------
|
| Later this will come from the authenticated resident account.
|
*/

const resident = {
    name: 'Juan Dela Cruz',
    residentId: 'BSJ-2026-0001',
    address: 'Purok 3, Barangay San Jose',
};

/*
|--------------------------------------------------------------------------
| Complaint Types
|--------------------------------------------------------------------------
*/

const complaintTypes = [
    'Property / Boundary Dispute',
    'Noise / Disturbance',
    'Debt / Money Dispute',
    'Damage to Property',
    'Threat / Harassment',
    'Family / Neighbor Dispute',
    'Other',
];

/*
|--------------------------------------------------------------------------
| Mock Complaints
|--------------------------------------------------------------------------
|
| UI data only.
| Later these will come from the database.
|
*/

const initialComplaints: Complaint[] = [
    {
        id: 1,
        reference: 'LUP-2026-00001',
        respondent: 'Pedro Santos',
        complaintType: 'Family / Neighbor Dispute',
        incidentDate: 'August 29, 2026',
        incidentTime: '7:30 PM',
        incidentLocation: 'Purok 3, Barangay San Jose',
        narrative:
            'A dispute occurred between neighboring households regarding repeated disturbances.',
        requestedAction:
            'Request assistance from the Lupong Tagapamayapa to help both parties reach an agreement.',
        status: 'Hearing Scheduled',
        submittedDate: 'September 1, 2026',
        hearing: {
            number: 1,
            date: 'September 10, 2026',
            time: '9:00 AM',
            location: 'Barangay San Jose - Lupon Office',
        },
    },

    {
        id: 2,
        reference: 'LUP-2026-00002',
        respondent: 'Maria Reyes',
        complaintType: 'Debt / Money Dispute',
        incidentDate: 'August 20, 2026',
        incidentLocation: 'Barangay San Jose',
        narrative:
            'The complaint concerns an unsettled personal debt between the parties.',
        requestedAction:
            'Request mediation to reach an agreement regarding payment.',
        status: 'Under Review',
        submittedDate: 'September 3, 2026',
    },

    {
        id: 3,
        reference: 'LUP-2026-00003',
        respondent: 'Sample Respondent',
        complaintType: 'Property / Boundary Dispute',
        incidentDate: 'July 12, 2026',
        incidentLocation: 'Purok 5, Barangay San Jose',
        narrative:
            'The complaint concerns disagreement regarding the boundary between neighboring properties.',
        requestedAction:
            'Request assistance in reaching a peaceful settlement.',
        status: 'Resolved',
        submittedDate: 'July 15, 2026',
    },
];

/*
|--------------------------------------------------------------------------
| Empty Form
|--------------------------------------------------------------------------
*/

const emptyForm: ComplaintForm = {
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

/*
|--------------------------------------------------------------------------
| Main Component
|--------------------------------------------------------------------------
*/

export default function Lupon() {
    const [activeTab, setActiveTab] =
        useState<ComplaintTab>('file');

    const [step, setStep] =
        useState<FormStep>('form');

    const [form, setForm] =
        useState<ComplaintForm>(emptyForm);

    const [complaints, setComplaints] =
        useState<Complaint[]>(initialComplaints);

    const [
        selectedComplaint,
        setSelectedComplaint,
    ] = useState<Complaint | null>(null);

    const [
        showTracking,
        setShowTracking,
    ] = useState(false);

    const [
        submittedComplaint,
        setSubmittedComplaint,
    ] = useState<Complaint | null>(null);

    /*
    |--------------------------------------------------------------------------
    | Form Validation
    |--------------------------------------------------------------------------
    */

    const canContinue = useMemo(() => {
        return (
            form.respondentName.trim() !== '' &&
            form.respondentResidentStatus !== '' &&
            form.complaintType !== '' &&
            form.incidentDate !== '' &&
            form.incidentLocation.trim() !== '' &&
            form.narrative.trim() !== '' &&
            form.requestedAction.trim() !== ''
        );
    }, [form]);

    /*
    |--------------------------------------------------------------------------
    | Update Form
    |--------------------------------------------------------------------------
    */

    const updateForm = (
        field: keyof ComplaintForm,
        value: string,
    ) => {
        setForm((current) => ({
            ...current,
            [field]: value,
        }));
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

        if (!canContinue) {
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
    | Submit Complaint
    |--------------------------------------------------------------------------
    */

    const handleSubmitComplaint = () => {
        const reference =
            `LUP-2026-${String(
                complaints.length + 4,
            ).padStart(5, '0')}`;

        const newComplaint: Complaint = {
            id: Date.now(),
            reference,
            respondent:
                form.respondentName,
            complaintType:
                form.complaintType,
            incidentDate:
                formatInputDate(
                    form.incidentDate,
                ),
            incidentTime:
                form.incidentTime
                    ? formatInputTime(
                          form.incidentTime,
                      )
                    : undefined,
            incidentLocation:
                form.incidentLocation,
            narrative:
                form.narrative,
            requestedAction:
                form.requestedAction,
            status: 'Submitted',
            submittedDate:
                'September 4, 2026',
        };

        setComplaints((current) => [
            newComplaint,
            ...current,
        ]);

        setSubmittedComplaint(
            newComplaint,
        );

        setStep('success');

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    /*
    |--------------------------------------------------------------------------
    | New Complaint
    |--------------------------------------------------------------------------
    */

    const handleNewComplaint = () => {
        setForm(emptyForm);
        setSubmittedComplaint(null);
        setSelectedComplaint(null);
        setShowTracking(false);
        setStep('form');
        setActiveTab('file');
    };

    /*
    |--------------------------------------------------------------------------
    | Open My Complaints
    |--------------------------------------------------------------------------
    */

    const openMyComplaints = () => {
        setSelectedComplaint(null);
        setShowTracking(false);
        setActiveTab('complaints');
        setStep('form');

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        |
        | active="lupon" makes File a Complaint green in ResidentLayout.
        |--------------------------------------------------------------------------
        */

        <ResidentLayout active="lupon">

            <div className="mx-auto w-full max-w-7xl">

                {/* ========================================================
                    PAGE HEADER
                ======================================================== */}

                <div className="mb-5 sm:mb-6">

                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Lupon Assistance
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        File a complaint and keep track of your
                        case with the Lupong Tagapamayapa.
                    </p>

                </div>

                {/* ========================================================
                    MAIN TABS
                ======================================================== */}

                <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-sm">

                    <div className="grid grid-cols-2 gap-1.5">

                        <button
                            type="button"
                            onClick={() => {
                                setActiveTab('file');
                                setSelectedComplaint(null);
                                setShowTracking(false);

                                if (
                                    step ===
                                    'success'
                                ) {
                                    setStep(
                                        'form',
                                    );
                                }
                            }}
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab ===
                                'file'
                                    ? 'bg-[#3f6f3d] text-white shadow-sm'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            File a Complaint
                        </button>

                        <button
                            type="button"
                            onClick={
                                openMyComplaints
                            }
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab ===
                                'complaints'
                                    ? 'bg-[#3f6f3d] text-white shadow-sm'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            My Complaints
                        </button>

                    </div>

                </div>

                {/* ========================================================
                    FILE COMPLAINT TAB
                ======================================================== */}

                {activeTab === 'file' && (
                    <>
                        {/* =================================================
                            FORM STEP
                        ================================================= */}

                        {step === 'form' && (
                            <form
                                onSubmit={
                                    handleContinue
                                }
                                className="space-y-5"
                            >

                                {/* -----------------------------------------
                                    COMPLAINANT
                                ----------------------------------------- */}

                                <SectionCard>

                                    <SectionHeading
                                        number="1"
                                        title="Your Information"
                                        description="Your verified resident information will be used as the complainant."
                                    />

                                    <div className="mt-5 rounded-xl border border-[#d9e7d5] bg-[#f4f8f2] p-4">

                                        <div className="flex items-start gap-3">

                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">
                                                <UserIcon />
                                            </div>

                                            <div className="min-w-0">

                                                <div className="flex flex-wrap items-center gap-2">

                                                    <p className="font-semibold text-gray-900">
                                                        {
                                                            resident.name
                                                        }
                                                    </p>

                                                    <span className="rounded-full bg-[#dcebd8] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#3f6f3d]">
                                                        Verified
                                                    </span>

                                                </div>

                                                <p className="mt-1 text-sm text-gray-500">
                                                    {
                                                        resident.residentId
                                                    }
                                                </p>

                                                <p className="mt-1 text-sm leading-5 text-gray-600">
                                                    {
                                                        resident.address
                                                    }
                                                </p>

                                            </div>

                                        </div>

                                    </div>

                                </SectionCard>

                                {/* -----------------------------------------
                                    RESPONDENT
                                ----------------------------------------- */}

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
                                        >
                                            <input
                                                type="text"
                                                value={
                                                    form.respondentName
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'respondentName',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                placeholder="Enter full name"
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Is the respondent a resident of this barangay?"
                                            required
                                        >
                                            <select
                                                value={
                                                    form.respondentResidentStatus
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'respondentResidentStatus',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">
                                                    Select
                                                </option>

                                                <option value="Yes">
                                                    Yes
                                                </option>

                                                <option value="No">
                                                    No
                                                </option>

                                                <option value="Unknown">
                                                    I don't know
                                                </option>

                                            </select>
                                        </Field>

                                        <Field label="Respondent Address">
                                            <input
                                                type="text"
                                                value={
                                                    form.respondentAddress
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'respondentAddress',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                placeholder="Enter address if known"
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Contact Number"
                                            optional
                                        >
                                            <input
                                                type="text"
                                                value={
                                                    form.respondentContact
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'respondentContact',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                placeholder="09XX XXX XXXX"
                                                className={inputClass}
                                            />
                                        </Field>

                                    </div>

                                </SectionCard>

                                {/* -----------------------------------------
                                    COMPLAINT DETAILS
                                ----------------------------------------- */}

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
                                        >
                                            <select
                                                value={
                                                    form.complaintType
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'complaintType',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">
                                                    Select complaint type
                                                </option>

                                                {complaintTypes.map(
                                                    (
                                                        type,
                                                    ) => (
                                                        <option
                                                            key={
                                                                type
                                                            }
                                                            value={
                                                                type
                                                            }
                                                        >
                                                            {
                                                                type
                                                            }
                                                        </option>
                                                    ),
                                                )}

                                            </select>
                                        </Field>

                                        <Field
                                            label="Date of Incident"
                                            required
                                        >
                                            <input
                                                type="date"
                                                value={
                                                    form.incidentDate
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'incidentDate',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Time of Incident"
                                            optional
                                        >
                                            <input
                                                type="time"
                                                value={
                                                    form.incidentTime
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'incidentTime',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>

                                        <Field
                                            label="Place of Incident"
                                            required
                                        >
                                            <input
                                                type="text"
                                                value={
                                                    form.incidentLocation
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'incidentLocation',
                                                        event
                                                            .target
                                                            .value,
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
                                        >
                                            <textarea
                                                rows={5}
                                                value={
                                                    form.narrative
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'narrative',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                placeholder="Describe what happened clearly and briefly..."
                                                className={`${inputClass} resize-none`}
                                            />

                                            <p className="mt-1.5 text-xs leading-5 text-gray-400">
                                                Include only information that is relevant
                                                to the complaint.
                                            </p>
                                        </Field>

                                        <Field
                                            label="Requested Action"
                                            required
                                        >
                                            <textarea
                                                rows={3}
                                                value={
                                                    form.requestedAction
                                                }
                                                onChange={(
                                                    event,
                                                ) =>
                                                    updateForm(
                                                        'requestedAction',
                                                        event
                                                            .target
                                                            .value,
                                                    )
                                                }
                                                placeholder="What assistance or resolution are you requesting?"
                                                className={`${inputClass} resize-none`}
                                            />
                                        </Field>

                                    </div>

                                </SectionCard>

                                {/* -----------------------------------------
                                    SUPPORTING EVIDENCE
                                ----------------------------------------- */}

                                <SectionCard>

                                    <SectionHeading
                                        number="4"
                                        title="Supporting Evidence"
                                        description="You may attach supporting files if available."
                                    />

                                    <div className="mt-5">

                                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-7 text-center transition hover:border-[#9fbe98] hover:bg-[#f7faf5]">

                                            <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-white text-gray-500 shadow-sm">
                                                <UploadIcon />
                                            </div>

                                            <p className="text-sm font-semibold text-gray-700">
                                                Upload supporting file
                                            </p>

                                            <p className="mt-1 text-xs leading-5 text-gray-400">
                                                Optional for this UI prototype
                                            </p>

                                            <input
                                                type="file"
                                                className="hidden"
                                            />

                                        </label>

                                    </div>

                                </SectionCard>

                                {/* -----------------------------------------
                                    CONTINUE
                                ----------------------------------------- */}

                                <div className="flex justify-end pb-3">

                                    <button
                                        type="submit"
                                        disabled={
                                            !canContinue
                                        }
                                        className="
                                            min-h-12
                                            w-full
                                            rounded-xl
                                            bg-[#3f6f3d]
                                            px-6
                                            py-3
                                            text-sm
                                            font-semibold
                                            text-white
                                            shadow-sm
                                            transition
                                            hover:bg-[#315b30]
                                            disabled:cursor-not-allowed
                                            disabled:opacity-40

                                            sm:w-auto
                                            sm:min-w-36
                                        "
                                    >
                                        Continue
                                    </button>

                                </div>

                            </form>
                        )}

                        {/* =================================================
                            REVIEW
                        ================================================= */}

                        {step === 'review' && (
                            <div className="space-y-5">

                                <SectionCard>

                                    <div className="flex items-start gap-3">

                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                                            <ReviewIcon />
                                        </div>

                                        <div>
                                            <h2 className="text-base font-bold text-gray-900 sm:text-lg">
                                                Review Your Complaint
                                            </h2>

                                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                                Please make sure the information below
                                                is correct before submitting.
                                            </p>
                                        </div>

                                    </div>

                                </SectionCard>

                                <SectionCard>

                                    <ReviewSection
                                        title="Complainant"
                                        items={[
                                            [
                                                'Name',
                                                resident.name,
                                            ],
                                            [
                                                'Resident ID',
                                                resident.residentId,
                                            ],
                                            [
                                                'Address',
                                                resident.address,
                                            ],
                                        ]}
                                    />

                                    <Divider />

                                    <ReviewSection
                                        title="Respondent"
                                        items={[
                                            [
                                                'Full Name',
                                                form.respondentName,
                                            ],
                                            [
                                                'Barangay Resident',
                                                form.respondentResidentStatus,
                                            ],
                                            [
                                                'Address',
                                                form.respondentAddress ||
                                                    'Not provided',
                                            ],
                                            [
                                                'Contact Number',
                                                form.respondentContact ||
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
                                                form.complaintType,
                                            ],
                                            [
                                                'Date of Incident',
                                                formatInputDate(
                                                    form.incidentDate,
                                                ),
                                            ],
                                            [
                                                'Time of Incident',
                                                form.incidentTime
                                                    ? formatInputTime(
                                                          form.incidentTime,
                                                      )
                                                    : 'Not provided',
                                            ],
                                            [
                                                'Place of Incident',
                                                form.incidentLocation,
                                            ],
                                        ]}
                                    />

                                    <Divider />

                                    <div>

                                        <p className="text-sm font-bold text-gray-900">
                                            Complaint Details
                                        </p>

                                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-600">
                                            {
                                                form.narrative
                                            }
                                        </p>

                                    </div>

                                    <Divider />

                                    <div>

                                        <p className="text-sm font-bold text-gray-900">
                                            Requested Action
                                        </p>

                                        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-600">
                                            {
                                                form.requestedAction
                                            }
                                        </p>

                                    </div>

                                </SectionCard>

                                <div className="flex flex-col-reverse gap-3 pb-3 sm:flex-row sm:justify-end">

                                    <button
                                        type="button"
                                        onClick={() =>
                                            setStep(
                                                'form',
                                            )
                                        }
                                        className="min-h-12 rounded-xl border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                                    >
                                        Back
                                    </button>

                                    <button
                                        type="button"
                                        onClick={
                                            handleSubmitComplaint
                                        }
                                        className="min-h-12 rounded-xl bg-[#3f6f3d] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#315b30]"
                                    >
                                        Submit Complaint
                                    </button>

                                </div>

                            </div>
                        )}

                        {/* =================================================
                            SUCCESS
                        ================================================= */}

                        {step === 'success' &&
                            submittedComplaint && (
                                <div className="mx-auto max-w-2xl">

                                    <div className="rounded-2xl border border-gray-200 bg-white px-5 py-8 text-center shadow-sm sm:px-8 sm:py-10">

                                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e7f2e3] text-[#3f6f3d]">
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

                                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                Case Reference
                                            </p>

                                            <p className="mt-1 text-lg font-bold text-[#3f6f3d]">
                                                {
                                                    submittedComplaint.reference
                                                }
                                            </p>

                                            <div className="my-4 border-t border-gray-200" />

                                            <div className="flex items-center justify-between gap-4 text-left">

                                                <div>
                                                    <p className="text-xs text-gray-400">
                                                        Current Status
                                                    </p>

                                                    <p className="mt-1 text-sm font-semibold text-gray-800">
                                                        Submitted
                                                    </p>
                                                </div>

                                                <StatusBadge
                                                    status="Submitted"
                                                />

                                            </div>

                                        </div>

                                        <p className="mx-auto mt-5 max-w-lg text-xs leading-5 text-gray-400">
                                            Keep your case reference number.
                                            You can view future case updates
                                            under My Complaints.
                                        </p>

                                        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">

                                            <button
                                                type="button"
                                                onClick={
                                                    openMyComplaints
                                                }
                                                className="min-h-11 rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30]"
                                            >
                                                View My Complaints
                                            </button>

                                            <button
                                                type="button"
                                                onClick={
                                                    handleNewComplaint
                                                }
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

                {/* ========================================================
                    MY COMPLAINTS
                ======================================================== */}

                {activeTab ===
                    'complaints' && (
                    <>
                        {!selectedComplaint && (
                            <div className="space-y-4">

                                <div className="flex items-center justify-between gap-4">

                                    <div>
                                        <h2 className="text-base font-bold text-gray-900 sm:text-lg">
                                            My Complaints
                                        </h2>

                                        <p className="mt-1 text-sm text-gray-500">
                                            View your submitted cases and current
                                            status.
                                        </p>
                                    </div>

                                </div>

                                {complaints.length >
                                0 ? (
                                    <div className="grid gap-4">

                                        {complaints.map(
                                            (
                                                complaint,
                                            ) => (
                                                <ComplaintCard
                                                    key={
                                                        complaint.id
                                                    }
                                                    complaint={
                                                        complaint
                                                    }
                                                    onView={() => {
                                                        setSelectedComplaint(
                                                            complaint,
                                                        );

                                                        setShowTracking(
                                                            false,
                                                        );

                                                        window.scrollTo({
                                                            top: 0,
                                                            behavior:
                                                                'smooth',
                                                        });
                                                    }}
                                                />
                                            ),
                                        )}

                                    </div>
                                ) : (
                                    <EmptyComplaints
                                        onFile={() =>
                                            setActiveTab(
                                                'file',
                                            )
                                        }
                                    />
                                )}

                            </div>
                        )}

                        {/* =================================================
                            COMPLAINT DETAILS
                        ================================================= */}

                        {selectedComplaint &&
                            !showTracking && (
                                <div className="space-y-5">

                                    <button
                                        type="button"
                                        onClick={() =>
                                            setSelectedComplaint(
                                                null,
                                            )
                                        }
                                        className="inline-flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
                                    >
                                        <BackIcon />

                                        Back to My Complaints
                                    </button>

                                    <SectionCard>

                                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                                            <div>

                                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                    Case Reference
                                                </p>

                                                <h2 className="mt-1 text-xl font-bold text-gray-900">
                                                    {
                                                        selectedComplaint.reference
                                                    }
                                                </h2>

                                                <p className="mt-1 text-sm text-gray-500">
                                                    Submitted{' '}
                                                    {
                                                        selectedComplaint.submittedDate
                                                    }
                                                </p>

                                            </div>

                                            <StatusBadge
                                                status={
                                                    selectedComplaint.status
                                                }
                                            />

                                        </div>

                                    </SectionCard>

                                    {/* HEARING NOTICE */}

                                    {selectedComplaint.hearing && (
                                        <div className="rounded-2xl border border-[#d8e6d3] bg-[#f4f8f2] p-4 shadow-sm sm:p-5">

                                            <div className="flex items-start gap-3">

                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">
                                                    <CalendarIcon />
                                                </div>

                                                <div className="min-w-0">

                                                    <p className="font-bold text-gray-900">
                                                        Next Hearing
                                                    </p>

                                                    <p className="mt-2 text-sm font-semibold text-[#3f6f3d]">
                                                        {
                                                            selectedComplaint
                                                                .hearing
                                                                .date
                                                        }
                                                        {' • '}
                                                        {
                                                            selectedComplaint
                                                                .hearing
                                                                .time
                                                        }
                                                    </p>

                                                    <p className="mt-1 text-sm leading-5 text-gray-600">
                                                        {
                                                            selectedComplaint
                                                                .hearing
                                                                .location
                                                        }
                                                    </p>

                                                    <p className="mt-2 text-xs leading-5 text-gray-500">
                                                        Please arrive at least
                                                        15 minutes before the
                                                        scheduled hearing.
                                                    </p>

                                                </div>

                                            </div>

                                        </div>
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

                                            <p className="text-sm font-bold text-gray-900">
                                                Complaint Details
                                            </p>

                                            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-600">
                                                {
                                                    selectedComplaint.narrative
                                                }
                                            </p>

                                        </div>

                                        <Divider />

                                        <div>

                                            <p className="text-sm font-bold text-gray-900">
                                                Requested Action
                                            </p>

                                            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-600">
                                                {
                                                    selectedComplaint.requestedAction
                                                }
                                            </p>

                                        </div>

                                    </SectionCard>

                                    <div className="flex justify-end">

                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowTracking(
                                                    true,
                                                );

                                                window.scrollTo({
                                                    top: 0,
                                                    behavior:
                                                        'smooth',
                                                });
                                            }}
                                            className="min-h-11 w-full rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30] sm:w-auto"
                                        >
                                            Track Case Status
                                        </button>

                                    </div>

                                </div>
                            )}

                        {/* =================================================
                            TRACKING
                        ================================================= */}

                        {selectedComplaint &&
                            showTracking && (
                                <ComplaintTracking
                                    complaint={
                                        selectedComplaint
                                    }
                                    onBack={() => {
                                        setShowTracking(
                                            false,
                                        );

                                        window.scrollTo({
                                            top: 0,
                                            behavior:
                                                'smooth',
                                        });
                                    }}
                                />
                            )}
                    </>
                )}

            </div>

        </ResidentLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Complaint Tracking
|--------------------------------------------------------------------------
*/

function ComplaintTracking({
    complaint,
    onBack,
}: {
    complaint: Complaint;
    onBack: () => void;
}) {
    const steps =
        getTrackingSteps(
            complaint.status,
        );

    return (
        <div className="space-y-5">

            <button
                type="button"
                onClick={
                    onBack
                }
                className="inline-flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100"
            >
                <BackIcon />

                Back to Case Details
            </button>

            {/* HEADER */}

            <SectionCard>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                    <div>

                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                            Case Tracking
                        </p>

                        <h2 className="mt-1 text-xl font-bold text-gray-900">
                            {
                                complaint.reference
                            }
                        </h2>

                        <p className="mt-1 text-sm text-gray-500">
                            {
                                complaint.complaintType
                            }
                        </p>

                    </div>

                    <StatusBadge
                        status={
                            complaint.status
                        }
                    />

                </div>

            </SectionCard>

            {/* CURRENT STATUS */}

            <div className="rounded-2xl border border-[#d8e6d3] bg-[#f4f8f2] p-4 shadow-sm sm:p-5">

                <p className="text-xs font-semibold uppercase tracking-wide text-[#5d8058]">
                    Current Status
                </p>

                <p className="mt-1 text-lg font-bold text-[#315b30]">
                    {
                        getFriendlyStatus(
                            complaint.status,
                        )
                    }
                </p>

                <p className="mt-2 text-sm leading-6 text-gray-600">
                    {
                        getStatusDescription(
                            complaint.status,
                        )
                    }
                </p>

            </div>

            {/* NEXT HEARING */}

            {complaint.hearing && (
                <SectionCard>

                    <div className="flex items-start gap-3">

                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                            <CalendarIcon />
                        </div>

                        <div>

                            <p className="font-bold text-gray-900">
                                Upcoming Hearing
                            </p>

                            <p className="mt-2 text-sm font-semibold text-[#3f6f3d]">
                                {
                                    complaint.hearing.date
                                }
                                {' • '}
                                {
                                    complaint.hearing.time
                                }
                            </p>

                            <p className="mt-1 text-sm text-gray-600">
                                {
                                    complaint.hearing.location
                                }
                            </p>

                        </div>

                    </div>

                </SectionCard>
            )}

            {/* ============================================================
                TRACKING TIMELINE

                ✓ = COMPLETED
                ● = CURRENT
                ○ = FUTURE

                This fixes the issue where every step appeared checked.
            ============================================================ */}

            <SectionCard>

                <div className="mb-6">

                    <h3 className="font-bold text-gray-900">
                        Case Progress
                    </h3>

                    <p className="mt-1 text-sm text-gray-500">
                        Follow the progress of your complaint.
                    </p>

                </div>

                <div className="mx-auto max-w-2xl">

                    {steps.map(
                        (
                            item,
                            index,
                        ) => {
                            const isLast =
                                index ===
                                steps.length -
                                    1;

                            return (
                                <div
                                    key={
                                        item.label
                                    }
                                    className="relative flex gap-4"
                                >

                                    {/* -------------------------------------
                                        LINE
                                    ------------------------------------- */}

                                    {!isLast && (
                                        <div
                                            className={`absolute left-[17px] top-9 h-[calc(100%-12px)] w-0.5 ${
                                                item.state ===
                                                    'completed'
                                                    ? 'bg-[#7da477]'
                                                    : 'bg-gray-200'
                                            }`}
                                        />
                                    )}

                                    {/* -------------------------------------
                                        INDICATOR
                                    ------------------------------------- */}

                                    <div className="relative z-10 shrink-0">

                                        {item.state ===
                                            'completed' && (
                                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-[#3f6f3d] text-white shadow-sm">
                                                <CheckIcon />
                                            </div>
                                        )}

                                        {item.state ===
                                            'current' && (
                                            <div className="flex h-9 w-9 items-center justify-center rounded-full border-[3px] border-[#3f6f3d] bg-white">
                                                <span className="h-3 w-3 rounded-full bg-[#3f6f3d]" />
                                            </div>
                                        )}

                                        {item.state ===
                                            'future' && (
                                            <div className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-gray-300 bg-white">
                                                <span className="h-2.5 w-2.5 rounded-full bg-gray-200" />
                                            </div>
                                        )}

                                    </div>

                                    {/* -------------------------------------
                                        TEXT
                                    ------------------------------------- */}

                                    <div
                                        className={`min-w-0 flex-1 ${
                                            isLast
                                                ? 'pb-0'
                                                : 'pb-7'
                                        }`}
                                    >

                                        <div className="flex flex-wrap items-center gap-2">

                                            <p
                                                className={`text-sm font-semibold ${
                                                    item.state ===
                                                    'future'
                                                        ? 'text-gray-400'
                                                        : 'text-gray-900'
                                                }`}
                                            >
                                                {
                                                    item.label
                                                }
                                            </p>

                                            {item.state ===
                                                'current' && (
                                                <span className="rounded-full bg-[#e8f1e4] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#3f6f3d]">
                                                    Current
                                                </span>
                                            )}

                                        </div>

                                        <p
                                            className={`mt-1 text-xs leading-5 ${
                                                item.state ===
                                                'future'
                                                    ? 'text-gray-400'
                                                    : 'text-gray-500'
                                            }`}
                                        >
                                            {
                                                item.description
                                            }
                                        </p>

                                    </div>

                                </div>
                            );
                        },
                    )}

                </div>

            </SectionCard>

            {/* UNRESOLVED MESSAGE */}

            {complaint.status ===
                'Unresolved' && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:p-5">

                    <p className="font-semibold text-amber-900">
                        Further coordination required
                    </p>

                    <p className="mt-1 text-sm leading-6 text-amber-800">
                        The case remains unresolved after the
                        barangay proceedings. Please coordinate
                        directly with the Lupong Tagapamayapa
                        office regarding the appropriate next
                        action.
                    </p>

                </div>
            )}

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Tracking Logic
|--------------------------------------------------------------------------
*/

type TrackingState =
    | 'completed'
    | 'current'
    | 'future';

type TrackingStep = {
    label: string;
    description: string;
    state: TrackingState;
};

function getTrackingSteps(
    status: ComplaintStatus,
): TrackingStep[] {
    /*
    |--------------------------------------------------------------------------
    | Base Progress
    |--------------------------------------------------------------------------
    */

    const order: ComplaintStatus[] = [
        'Submitted',
        'Under Review',
        'Hearing Scheduled',
        'First Hearing',
        'Second Hearing',
        'Third Hearing',
    ];

    /*
    |--------------------------------------------------------------------------
    | Resolved / Unresolved
    |--------------------------------------------------------------------------
    |
    | For the UI prototype, resolved means the proceedings have finished.
    |
    */

    let currentIndex =
        order.indexOf(
            status,
        );

    if (
        status ===
            'Resolved' ||
        status ===
            'Unresolved'
    ) {
        currentIndex =
            order.length;
    }

    const baseSteps = [
        {
            status:
                'Submitted' as ComplaintStatus,
            label:
                'Complaint Submitted',
            description:
                'Your complaint was successfully submitted to the barangay.',
        },
        {
            status:
                'Under Review' as ComplaintStatus,
            label:
                'Under Review',
            description:
                'Lupon staff is reviewing the complaint information.',
        },
        {
            status:
                'Hearing Scheduled' as ComplaintStatus,
            label:
                'Hearing Scheduled',
            description:
                'A hearing schedule has been or will be assigned by Lupon staff.',
        },
        {
            status:
                'First Hearing' as ComplaintStatus,
            label:
                'First Hearing',
            description:
                'The first hearing or mediation proceeding is conducted.',
        },
        {
            status:
                'Second Hearing' as ComplaintStatus,
            label:
                'Second Hearing',
            description:
                'A second hearing may be conducted when further mediation is needed.',
        },
        {
            status:
                'Third Hearing' as ComplaintStatus,
            label:
                'Third Hearing',
            description:
                'A third hearing may be conducted when the case remains unresolved.',
        },
    ];

    const steps: TrackingStep[] =
        baseSteps.map(
            (
                item,
                index,
            ) => {
                let state: TrackingState =
                    'future';

                if (
                    status ===
                        'Resolved' ||
                    status ===
                        'Unresolved'
                ) {
                    state =
                        'completed';
                } else if (
                    index <
                    currentIndex
                ) {
                    state =
                        'completed';
                } else if (
                    index ===
                    currentIndex
                ) {
                    state =
                        'current';
                }

                return {
                    label:
                        item.label,
                    description:
                        item.description,
                    state,
                };
            },
        );

    /*
    |--------------------------------------------------------------------------
    | Final Step
    |--------------------------------------------------------------------------
    */

    if (
        status ===
        'Resolved'
    ) {
        steps.push({
            label:
                'Resolved / Closed',
            description:
                'The complaint has been resolved and the barangay case is closed.',
            state:
                'current',
        });
    } else if (
        status ===
        'Unresolved'
    ) {
        steps.push({
            label:
                'Unresolved',
            description:
                'The barangay proceedings ended without a settlement.',
            state:
                'current',
        });
    } else {
        steps.push({
            label:
                'Resolved / Closed',
            description:
                'The case will be marked resolved when an agreement or resolution is reached.',
            state:
                'future',
        });
    }

    return steps;
}

/*
|--------------------------------------------------------------------------
| Complaint Card
|--------------------------------------------------------------------------
*/

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
                            {
                                complaint.reference
                            }
                        </p>

                        <StatusBadge
                            status={
                                complaint.status
                            }
                        />

                    </div>

                    <p className="mt-2 text-sm font-semibold text-gray-800">
                        {
                            complaint.complaintType
                        }
                    </p>

                    <div className="mt-3 grid gap-2 text-sm text-gray-500 sm:grid-cols-2 sm:gap-x-8">

                        <p>
                            <span className="text-gray-400">
                                Respondent:
                            </span>{' '}
                            {
                                complaint.respondent
                            }
                        </p>

                        <p>
                            <span className="text-gray-400">
                                Submitted:
                            </span>{' '}
                            {
                                complaint.submittedDate
                            }
                        </p>

                    </div>

                </div>

                <button
                    type="button"
                    onClick={
                        onView
                    }
                    className="min-h-10 shrink-0 rounded-xl border border-[#bcd2b6] bg-white px-4 py-2 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#f0f6ee]"
                >
                    View Details
                </button>

            </div>

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
    status: ComplaintStatus;
}) {
    const styles: Record<
        ComplaintStatus,
        string
    > = {
        Submitted:
            'bg-blue-50 text-blue-700 border-blue-200',

        'Under Review':
            'bg-amber-50 text-amber-700 border-amber-200',

        'Hearing Scheduled':
            'bg-purple-50 text-purple-700 border-purple-200',

        'First Hearing':
            'bg-purple-50 text-purple-700 border-purple-200',

        'Second Hearing':
            'bg-purple-50 text-purple-700 border-purple-200',

        'Third Hearing':
            'bg-purple-50 text-purple-700 border-purple-200',

        Resolved:
            'bg-green-50 text-green-700 border-green-200',

        Unresolved:
            'bg-red-50 text-red-700 border-red-200',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[11px] font-bold ${styles[status]}`}
        >
            {status}
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Section Card
|--------------------------------------------------------------------------
*/

function SectionCard({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 lg:p-6">
            {children}
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Section Heading
|--------------------------------------------------------------------------
*/

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

            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-sm font-bold text-[#3f6f3d]">
                {number}
            </div>

            <div>

                <h2 className="font-bold text-gray-900">
                    {title}
                </h2>

                <p className="mt-1 text-sm leading-5 text-gray-500">
                    {description}
                </p>

            </div>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Form Field
|--------------------------------------------------------------------------
*/

function Field({
    label,
    required = false,
    optional = false,
    children,
}: {
    label: string;
    required?: boolean;
    optional?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div>

            <label className="mb-1.5 block text-sm font-semibold text-gray-700">

                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}

                {optional && (
                    <span className="ml-1 text-xs font-normal text-gray-400">
                        (Optional)
                    </span>
                )}

            </label>

            {children}

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Review Section
|--------------------------------------------------------------------------
*/

function ReviewSection({
    title,
    items,
}: {
    title: string;
    items: [string, string][];
}) {
    return (
        <div>

            <h3 className="text-sm font-bold text-gray-900">
                {title}
            </h3>

            <div className="mt-3 grid gap-4 sm:grid-cols-2">

                {items.map(
                    ([label, value]) => (
                        <div
                            key={
                                label
                            }
                        >

                            <p className="text-xs text-gray-400">
                                {
                                    label
                                }
                            </p>

                            <p className="mt-1 break-words text-sm font-medium leading-5 text-gray-700">
                                {
                                    value
                                }
                            </p>

                        </div>
                    ),
                )}

            </div>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Divider
|--------------------------------------------------------------------------
*/

function Divider() {
    return (
        <div className="my-5 border-t border-gray-100" />
    );
}

/*
|--------------------------------------------------------------------------
| Empty Complaints
|--------------------------------------------------------------------------
*/

function EmptyComplaints({
    onFile,
}: {
    onFile: () => void;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-sm">

            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <ComplaintIcon />
            </div>

            <h3 className="mt-4 font-bold text-gray-900">
                No complaints yet
            </h3>

            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
                Complaints you submit will appear here
                together with their current status.
            </p>

            <button
                type="button"
                onClick={
                    onFile
                }
                className="mt-5 min-h-11 rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30]"
            >
                File a Complaint
            </button>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Status Helpers
|--------------------------------------------------------------------------
*/

function getFriendlyStatus(
    status: ComplaintStatus,
) {
    if (
        status ===
        'Resolved'
    ) {
        return 'Case Resolved';
    }

    if (
        status ===
        'Unresolved'
    ) {
        return 'Unresolved After Barangay Proceedings';
    }

    return status;
}

function getStatusDescription(
    status: ComplaintStatus,
) {
    const descriptions: Record<
        ComplaintStatus,
        string
    > = {
        Submitted:
            'Your complaint has been submitted and is waiting for Lupon review.',

        'Under Review':
            'Lupon staff is currently reviewing the information in your complaint.',

        'Hearing Scheduled':
            'Your complaint has been reviewed and a hearing has been scheduled.',

        'First Hearing':
            'The case is currently at the first hearing or mediation stage.',

        'Second Hearing':
            'Further mediation is required and the case has proceeded to a second hearing.',

        'Third Hearing':
            'The case has proceeded to a third hearing because it remains unresolved.',

        Resolved:
            'The parties reached a resolution and the barangay case has been closed.',

        Unresolved:
            'The barangay proceedings ended without a settlement. Please coordinate with the Lupon office for the appropriate next action.',
    };

    return descriptions[
        status
    ];
}

/*
|--------------------------------------------------------------------------
| Input Styles
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Date / Time Helpers
|--------------------------------------------------------------------------
*/

function formatInputDate(
    value: string,
) {
    if (!value) {
        return '';
    }

    const date =
        new Date(
            `${value}T00:00:00`,
        );

    return date.toLocaleDateString(
        'en-US',
        {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        },
    );
}

function formatInputTime(
    value: string,
) {
    if (!value) {
        return '';
    }

    const [
        hourString,
        minute,
    ] = value.split(':');

    let hour =
        Number(
            hourString,
        );

    const period =
        hour >= 12
            ? 'PM'
            : 'AM';

    hour =
        hour % 12 ||
        12;

    return `${hour}:${minute} ${period}`;
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

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
            <circle
                cx="12"
                cy="7"
                r="4"
            />
        </svg>
    );
}

function UploadIcon() {
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
            <path d="M12 16V4" />
            <path d="m7 9 5-5 5 5" />
            <path d="M5 20h14" />
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

function CalendarIcon() {
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
            <rect
                x="3"
                y="5"
                width="18"
                height="16"
                rx="2"
            />
            <path d="M16 3v4" />
            <path d="M8 3v4" />
            <path d="M3 11h18" />
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