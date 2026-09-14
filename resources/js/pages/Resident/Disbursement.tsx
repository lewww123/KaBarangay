import { useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

type DisbursementStatus =
    | 'Included'
    | 'Scheduled'
    | 'Ready for Claim'
    | 'Received';

type Disbursement = {
    id: number;
    reference: string;
    program: string;
    amount: number;
    status: DisbursementStatus;
    includedDate: string;
    releaseDate?: string;
    releaseTime?: string;
    location?: string;
    requirements?: string[];
    receivedDate?: string;
    receivedTime?: string;
};

type TabType = 'current' | 'history';

/*
|--------------------------------------------------------------------------
| Mock Resident
|--------------------------------------------------------------------------
*/

const resident = {
    name: 'Juan Dela Cruz',
    residentId: 'BSJ-2026-0001',
};

/*
|--------------------------------------------------------------------------
| Mock Disbursement Data
|--------------------------------------------------------------------------
|
| UI prototype only.
| Later this will come from the database and only records belonging to
| the logged-in resident will be shown.
|
*/

const currentDisbursements: Disbursement[] = [
    {
        id: 1,
        reference: 'DSB-2026-00124',
        program: 'Cash Assistance Program',
        amount: 2000,
        status: 'Ready for Claim',
        includedDate: 'September 5, 2026',
        releaseDate: 'September 15, 2026',
        releaseTime: '9:00 AM - 3:00 PM',
        location: 'Barangay San Jose Hall',
        requirements: [
            'Barangay ID',
            'Valid government-issued ID',
        ],
    },
    {
        id: 2,
        reference: 'DSB-2026-00131',
        program: 'Medical Assistance',
        amount: 1500,
        status: 'Scheduled',
        includedDate: 'September 7, 2026',
        releaseDate: 'September 20, 2026',
        releaseTime: '8:30 AM - 12:00 PM',
        location: 'Barangay San Jose Hall',
        requirements: [
            'Barangay ID',
            'Valid ID',
        ],
    },
];

const disbursementHistory: Disbursement[] = [
    {
        id: 3,
        reference: 'DSB-2026-00098',
        program: 'Emergency Cash Assistance',
        amount: 3000,
        status: 'Received',
        includedDate: 'August 1, 2026',
        releaseDate: 'August 10, 2026',
        location: 'Barangay San Jose Hall',
        receivedDate: 'August 10, 2026',
        receivedTime: '10:42 AM',
        requirements: [
            'Barangay ID',
            'Valid ID',
        ],
    },
];

/*
|--------------------------------------------------------------------------
| Main Component
|--------------------------------------------------------------------------
*/

export default function Disbursement() {
    const [activeTab, setActiveTab] =
        useState<TabType>('current');

    const [
        selectedDisbursement,
        setSelectedDisbursement,
    ] = useState<Disbursement | null>(null);

    /*
    |--------------------------------------------------------------------------
    | Detail View
    |--------------------------------------------------------------------------
    */

    if (selectedDisbursement) {
        return (
            <ResidentLayout active="disbursement">
                <div className="mx-auto w-full max-w-5xl">

                    {/* BACK */}

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedDisbursement(null);

                            window.scrollTo({
                                top: 0,
                                behavior: 'smooth',
                            });
                        }}
                        className="
                            mb-4
                            inline-flex
                            min-h-10
                            items-center
                            gap-2
                            rounded-lg
                            px-2
                            text-sm
                            font-semibold
                            text-gray-600
                            transition
                            hover:bg-gray-100
                            hover:text-gray-900
                        "
                    >
                        <BackIcon />
                        Back to Disbursement
                    </button>

                    {/* HEADER CARD */}

                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Disbursement Reference
                                </p>

                                <h1 className="mt-1 text-xl font-bold text-gray-900 sm:text-2xl">
                                    {selectedDisbursement.reference}
                                </h1>

                                <p className="mt-2 text-sm font-semibold text-gray-700">
                                    {selectedDisbursement.program}
                                </p>
                            </div>

                            <StatusBadge
                                status={selectedDisbursement.status}
                            />

                        </div>

                        <div className="mt-5 rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-4">

                            <p className="text-xs font-semibold uppercase tracking-wide text-[#5c7d57]">
                                Amount
                            </p>

                            <p className="mt-1 text-2xl font-bold text-[#315b30]">
                                ₱
                                {selectedDisbursement.amount.toLocaleString()}
                            </p>

                        </div>

                    </div>

                    {/* TRACKING */}

                    <div className="mt-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                        <div className="mb-6">
                            <h2 className="font-bold text-gray-900">
                                Disbursement Progress
                            </h2>

                            <p className="mt-1 text-sm text-gray-500">
                                Track the status of your assistance.
                            </p>
                        </div>

                        <DisbursementTracker
                            status={selectedDisbursement.status}
                        />

                    </div>

                    {/* DETAILS */}

                    <div className="mt-5 grid gap-5 lg:grid-cols-2">

                        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                            <h2 className="font-bold text-gray-900">
                                Release Details
                            </h2>

                            <div className="mt-5 space-y-4">

                                <DetailRow
                                    label="Resident"
                                    value={resident.name}
                                />

                                <DetailRow
                                    label="Resident ID"
                                    value={resident.residentId}
                                />

                                <DetailRow
                                    label="Program"
                                    value={selectedDisbursement.program}
                                />

                                <DetailRow
                                    label="Included Date"
                                    value={selectedDisbursement.includedDate}
                                />

                                <DetailRow
                                    label="Release Date"
                                    value={
                                        selectedDisbursement.releaseDate ||
                                        'Not yet scheduled'
                                    }
                                />

                                <DetailRow
                                    label="Release Time"
                                    value={
                                        selectedDisbursement.releaseTime ||
                                        'Not yet available'
                                    }
                                />

                                <DetailRow
                                    label="Claim Location"
                                    value={
                                        selectedDisbursement.location ||
                                        'Not yet available'
                                    }
                                />

                            </div>

                        </div>

                        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                            <h2 className="font-bold text-gray-900">
                                Claim Requirements
                            </h2>

                            {selectedDisbursement.requirements &&
                            selectedDisbursement.requirements.length >
                                0 ? (
                                <div className="mt-5 space-y-3">

                                    {selectedDisbursement.requirements.map(
                                        (requirement) => (
                                            <div
                                                key={requirement}
                                                className="flex items-start gap-3"
                                            >
                                                <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                                                    <SmallCheckIcon />
                                                </div>

                                                <p className="text-sm leading-6 text-gray-600">
                                                    {requirement}
                                                </p>
                                            </div>
                                        ),
                                    )}

                                </div>
                            ) : (
                                <p className="mt-4 text-sm text-gray-500">
                                    No additional requirements are listed.
                                </p>
                            )}

                            {selectedDisbursement.status ===
                                'Ready for Claim' && (
                                <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">

                                    <div className="flex items-start gap-3">

                                        <div className="mt-0.5 shrink-0 text-amber-700">
                                            <AlertIcon />
                                        </div>

                                        <div>
                                            <p className="text-sm font-semibold text-amber-900">
                                                Ready for Claim
                                            </p>

                                            <p className="mt-1 text-sm leading-6 text-amber-800">
                                                Please bring the listed
                                                identification documents when
                                                claiming your assistance.
                                            </p>
                                        </div>

                                    </div>

                                </div>
                            )}

                            {selectedDisbursement.status ===
                                'Received' && (
                                <div className="mt-5 rounded-xl border border-green-200 bg-green-50 p-4">

                                    <p className="text-sm font-semibold text-green-800">
                                        Assistance Received
                                    </p>

                                    <p className="mt-1 text-sm leading-6 text-green-700">
                                        Recorded on{' '}
                                        {selectedDisbursement.receivedDate}
                                        {selectedDisbursement.receivedTime
                                            ? ` at ${selectedDisbursement.receivedTime}`
                                            : ''}
                                        .
                                    </p>

                                </div>
                            )}

                        </div>

                    </div>

                </div>
            </ResidentLayout>
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Main List
    |--------------------------------------------------------------------------
    */

    return (
        <ResidentLayout active="disbursement">
            <div className="mx-auto w-full max-w-7xl">

                {/* HEADER */}

                <div className="mb-5 sm:mb-6">

                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        My Assistance & Disbursement
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        View financial assistance, allowances, or
                        benefits assigned to you and track their
                        release status.
                    </p>

                </div>

                {/* INFO NOTICE */}

                <div className="mb-5 rounded-2xl border border-[#dce8d8] bg-[#f5f9f3] p-4 shadow-sm sm:p-5">

                    <div className="flex items-start gap-3">

                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e1eddd] text-[#3f6f3d]">
                            <InfoIcon />
                        </div>

                        <div>

                            <p className="text-sm font-semibold text-gray-800">
                                Your personal disbursement records
                            </p>

                            <p className="mt-1 text-sm leading-6 text-gray-600">
                                Only assistance or benefits assigned
                                to your resident account will appear
                                here.
                            </p>

                        </div>

                    </div>

                </div>

                {/* TABS */}

                <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-sm">

                    <div className="grid grid-cols-2 gap-1.5">

                        <button
                            type="button"
                            onClick={() =>
                                setActiveTab('current')
                            }
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab === 'current'
                                    ? 'bg-[#3f6f3d] text-white shadow-sm'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            Current
                        </button>

                        <button
                            type="button"
                            onClick={() =>
                                setActiveTab('history')
                            }
                            className={`min-h-11 rounded-xl px-3 py-2.5 text-sm font-semibold transition ${
                                activeTab === 'history'
                                    ? 'bg-[#3f6f3d] text-white shadow-sm'
                                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800'
                            }`}
                        >
                            History
                        </button>

                    </div>

                </div>

                {/* CURRENT */}

                {activeTab === 'current' && (
                    <>

                        <div className="mb-3">

                            <h2 className="font-bold text-gray-900">
                                Current Disbursement
                            </h2>

                            <p className="mt-1 text-xs text-gray-400">
                                {currentDisbursements.length}{' '}
                                {currentDisbursements.length === 1
                                    ? 'record'
                                    : 'records'}
                            </p>

                        </div>

                        {currentDisbursements.length > 0 ? (
                            <div className="grid gap-4 lg:grid-cols-2">

                                {currentDisbursements.map(
                                    (item) => (
                                        <DisbursementCard
                                            key={item.id}
                                            item={item}
                                            onView={() => {
                                                setSelectedDisbursement(
                                                    item,
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
                            <EmptyCurrent />
                        )}

                    </>
                )}

                {/* HISTORY */}

                {activeTab === 'history' && (
                    <>

                        <div className="mb-3">

                            <h2 className="font-bold text-gray-900">
                                Disbursement History
                            </h2>

                            <p className="mt-1 text-xs text-gray-400">
                                Previously received assistance.
                            </p>

                        </div>

                        {disbursementHistory.length > 0 ? (
                            <div className="grid gap-4 lg:grid-cols-2">

                                {disbursementHistory.map(
                                    (item) => (
                                        <DisbursementCard
                                            key={item.id}
                                            item={item}
                                            onView={() => {
                                                setSelectedDisbursement(
                                                    item,
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
                            <EmptyHistory />
                        )}

                    </>
                )}

            </div>
        </ResidentLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Disbursement Card
|--------------------------------------------------------------------------
*/

function DisbursementCard({
    item,
    onView,
}: {
    item: Disbursement;
    onView: () => void;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 hover:shadow-md sm:p-5">

            <div className="flex flex-col gap-4">

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                    <div className="min-w-0">

                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                            {item.reference}
                        </p>

                        <h3 className="mt-1 text-base font-bold text-gray-900">
                            {item.program}
                        </h3>

                    </div>

                    <StatusBadge
                        status={item.status}
                    />

                </div>

                <div className="rounded-xl bg-[#f7f9f5] p-4">

                    <p className="text-xs text-gray-500">
                        Amount
                    </p>

                    <p className="mt-1 text-xl font-bold text-[#3f6f3d]">
                        ₱{item.amount.toLocaleString()}
                    </p>

                </div>

                <div className="grid gap-3 text-sm sm:grid-cols-2">

                    <div>
                        <p className="text-xs text-gray-400">
                            Release Date
                        </p>

                        <p className="mt-1 font-medium text-gray-700">
                            {item.releaseDate ||
                                'Not yet scheduled'}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs text-gray-400">
                            Location
                        </p>

                        <p className="mt-1 font-medium text-gray-700">
                            {item.location ||
                                'Not yet available'}
                        </p>
                    </div>

                </div>

                <div className="border-t border-gray-100 pt-4">

                    <button
                        type="button"
                        onClick={onView}
                        className="
                            min-h-10
                            w-full
                            rounded-xl
                            border
                            border-[#bcd2b6]
                            bg-white
                            px-4
                            py-2
                            text-sm
                            font-semibold
                            text-[#3f6f3d]
                            transition
                            hover:bg-[#f0f6ee]

                            sm:w-auto
                        "
                    >
                        View Details
                    </button>

                </div>

            </div>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Tracker
|--------------------------------------------------------------------------
*/

function DisbursementTracker({
    status,
}: {
    status: DisbursementStatus;
}) {
    const steps = [
        {
            key: 'Included' as DisbursementStatus,
            label: 'Included',
            description:
                'You are included in this disbursement program.',
        },
        {
            key: 'Scheduled' as DisbursementStatus,
            label: 'Scheduled',
            description:
                'A release date has been scheduled.',
        },
        {
            key: 'Ready for Claim' as DisbursementStatus,
            label: 'Ready for Claim',
            description:
                'Your assistance is available for claiming.',
        },
        {
            key: 'Received' as DisbursementStatus,
            label: 'Received',
            description:
                'The assistance has been released and received.',
        },
    ];

    const currentIndex =
        steps.findIndex(
            (step) =>
                step.key === status,
        );

    return (
        <div className="mx-auto max-w-2xl">

            {steps.map((step, index) => {
                const isCompleted =
                    index < currentIndex;

                const isCurrent =
                    index === currentIndex;

                const isFuture =
                    index > currentIndex;

                const isLast =
                    index ===
                    steps.length - 1;

                return (
                    <div
                        key={step.key}
                        className="relative flex gap-4"
                    >
                        {!isLast && (
                            <div
                                className={`absolute left-[17px] top-9 h-[calc(100%-12px)] w-0.5 ${
                                    isCompleted
                                        ? 'bg-[#7da477]'
                                        : 'bg-gray-200'
                                }`}
                            />
                        )}

                        <div className="relative z-10 shrink-0">

                            {isCompleted && (
                                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-[#3f6f3d] text-white">
                                    <CheckIcon />
                                </div>
                            )}

                            {isCurrent && (
                                <div className="flex h-9 w-9 items-center justify-center rounded-full border-[3px] border-[#3f6f3d] bg-white">
                                    <span className="h-3 w-3 rounded-full bg-[#3f6f3d]" />
                                </div>
                            )}

                            {isFuture && (
                                <div className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-gray-300 bg-white">
                                    <span className="h-2.5 w-2.5 rounded-full bg-gray-200" />
                                </div>
                            )}

                        </div>

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
                                        isFuture
                                            ? 'text-gray-400'
                                            : 'text-gray-900'
                                    }`}
                                >
                                    {step.label}
                                </p>

                                {isCurrent && (
                                    <span className="rounded-full bg-[#e8f1e4] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#3f6f3d]">
                                        Current
                                    </span>
                                )}

                            </div>

                            <p
                                className={`mt-1 text-xs leading-5 ${
                                    isFuture
                                        ? 'text-gray-400'
                                        : 'text-gray-500'
                                }`}
                            >
                                {step.description}
                            </p>

                        </div>

                    </div>
                );
            })}

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
    status: DisbursementStatus;
}) {
    const styles: Record<
        DisbursementStatus,
        string
    > = {
        Included:
            'border-blue-200 bg-blue-50 text-blue-700',

        Scheduled:
            'border-purple-200 bg-purple-50 text-purple-700',

        'Ready for Claim':
            'border-amber-200 bg-amber-50 text-amber-700',

        Received:
            'border-green-200 bg-green-50 text-green-700',
    };

    return (
        <span
            className={`inline-flex w-fit rounded-full border px-2.5 py-1 text-[11px] font-bold ${styles[status]}`}
        >
            {status}
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Detail Row
|--------------------------------------------------------------------------
*/

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>
            <p className="text-xs text-gray-400">
                {label}
            </p>

            <p className="mt-1 break-words text-sm font-medium leading-5 text-gray-700">
                {value}
            </p>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Empty States
|--------------------------------------------------------------------------
*/

function EmptyCurrent() {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-sm">

            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <MoneyIcon />
            </div>

            <h3 className="mt-4 font-bold text-gray-900">
                No active disbursement
            </h3>

            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
                You currently have no assistance,
                allowance, or benefit scheduled for
                release.
            </p>

            <p className="mx-auto mt-2 max-w-md text-xs leading-5 text-gray-400">
                Any disbursement assigned to your
                resident account will appear here.
            </p>

        </div>
    );
}

function EmptyHistory() {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-sm">

            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <HistoryIcon />
            </div>

            <h3 className="mt-4 font-bold text-gray-900">
                No disbursement history
            </h3>

            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
                Completed disbursements will appear
                here after they are released and
                recorded as received.
            </p>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

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

function SmallCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-3 w-3"
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
            <path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
    );
}

function InfoIcon() {
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
            <circle
                cx="12"
                cy="12"
                r="9"
            />

            <path d="M12 11v6" />
            <path d="M12 7h.01" />
        </svg>
    );
}

function MoneyIcon() {
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
            <rect
                x="3"
                y="6"
                width="18"
                height="12"
                rx="2"
            />

            <circle
                cx="12"
                cy="12"
                r="2"
            />

            <path d="M7 9h.01" />
            <path d="M17 15h.01" />
        </svg>
    );
}

function HistoryIcon() {
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
            <path d="M3 12a9 9 0 1 0 3-6.7" />
            <path d="M3 4v6h6" />
            <path d="M12 7v5l3 2" />
        </svg>
    );
}