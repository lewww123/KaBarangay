import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';
import OfflineAttendance from '@/components/announcements/OfflineAttendance';
import AttendanceResidentSelect from '@/components/announcements/AttendanceResidentSelect';

type AttendanceStatus = 'present' | 'claimed' | 'absent';
type AttendanceTab = 'present' | 'claimed';
type FeedbackTone = 'success' | 'warning' | 'error' | 'info';
type AttendanceOutcome =
    | 'recorded'
    | 'already_present'
    | 'already_claimed'
    | 'existing_record_conflict';

interface Resident {
    id: number;
    first_name: string;
    last_name: string;
    middle_name?: string;
    suffix?: string;
    resident_number: string;
}

interface AttendanceItem {
    id: number;
    resident: Resident;
    status: AttendanceStatus;
    method: string;
    attended_at: string;
}

interface Announcement {
    id: number;
    title: string;
    category: string;
    location?: string;
}

interface QrVerificationResponse {
    resident?: {
        id?: number;
        full_name?: string;
        resident_number?: string;
    };
    qr_proof?: string | null;
    message?: string;
}

interface AttendanceFlash {
    success?: string | null;
    warning?: string | null;
    error?: string | null;
    info?: string | null;
    attendance_outcome?: AttendanceOutcome | null;
}

interface InertiaPageProps {
    flash?: AttendanceFlash;
}

interface Props {
    announcement: Announcement;
    attendances: AttendanceItem[];
    residents?: Resident[];
    user?: { name?: string; staff_id?: string; department?: string };
}

const feedbackToneClasses: Record<FeedbackTone, string> = {
    success: 'border-brand-muted bg-brand-soft text-brand-deep',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-red-200 bg-red-50 text-red-800',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
};

const feedbackDotClasses: Record<FeedbackTone, string> = {
    success: 'bg-brand-soft0',
    warning: 'bg-amber-500',
    error: 'bg-red-500',
    info: 'bg-sky-500',
};

function normalizeQrToken(rawToken: string): string {
    const trimmed = rawToken.trim();

    if (!trimmed || !trimmed.includes('/')) {
        return trimmed;
    }

    const parts = trimmed.split('/').filter(Boolean);

    return parts[parts.length - 1] ?? '';
}

function attendanceFeedbackFromPage(page: {
    props: Record<string, unknown>;
}): { text: string; type: Exclude<FeedbackTone, 'info'> } | null {
    const props = page.props as InertiaPageProps & Record<string, unknown>;
    const flash = props.flash;
    const outcome = flash?.attendance_outcome;
    const message =
        outcome === 'recorded'
            ? flash?.success
            : (flash?.warning ?? flash?.info ?? flash?.error);

    if (!message?.trim()) {
        return null;
    }

    if (outcome === 'recorded') {
        return { text: message, type: 'success' };
    }

    if (
        outcome === 'already_present' ||
        outcome === 'already_claimed' ||
        outcome === 'existing_record_conflict'
    ) {
        return { text: message, type: 'warning' };
    }

    return null;
}

function extractServerMessage(
    data: string | Record<string, unknown>,
): string | undefined {
    if (typeof data !== 'object' || data === null) {
        return undefined;
    }

    const message = data.message;

    return typeof message === 'string' && message.trim() !== ''
        ? message
        : undefined;
}

function verificationErrorMessage(
    status: number,
    serverMessage?: string,
): string {
    if (serverMessage?.trim()) {
        return serverMessage;
    }

    if (status === 401 || status === 419) {
        return 'Nag-expire ang session. I-refresh ang page at mag-login muli.';
    }

    if (status === 403) {
        return 'Wala kang pahintulot na gawin ang aksyong ito.';
    }

    if (status === 404) {
        return 'Hindi makita ang residente o hindi aktibo ang account.';
    }

    if (status === 422) {
        return 'Mali, expired, o hindi kabilang sa event ang QR code.';
    }

    if (status === 429) {
        return 'Masyadong maraming scan. Maghintay sandali bago subukan muli.';
    }

    return 'Hindi ma-verify ang QR code. Subukang muli.';
}

function recordingErrorMessage(status: number, serverMessage?: string): string {
    if (serverMessage?.trim()) {
        return serverMessage;
    }

    if (status === 401 || status === 419) {
        return 'Nag-expire ang session. I-refresh ang page at mag-login muli.';
    }

    if (status === 403) {
        return 'Hindi awtorisado ang aksyon o hindi kasama sa event ang residente.';
    }

    if (status === 404) {
        return 'Hindi makita ang residente o announcement.';
    }

    if (status === 422) {
        return 'Hindi tinanggap ang attendance. Subukang muli.';
    }

    if (status === 429) {
        return 'Masyadong maraming scan. Maghintay sandali bago subukan muli.';
    }

    return 'May problema sa server. Subukang muli.';
}

function firstValidationError(
    errors: Record<string, string | string[]>,
): string | undefined {
    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value.length > 0) {
            return value[0];
        }

        if (typeof value === 'string' && value !== '') {
            return value;
        }
    }

    return undefined;
}

export default withDeferredPage(
    ClerkAttendanceSheet,
    ['attendances', 'residents'],
    (props) => (
        <ClerkLayout active="announcements" user={props.user}>
            <PortalPageSkeleton metrics={3} />
        </ClerkLayout>
    ),
);

function ClerkAttendanceSheet({
    announcement,
    attendances,
    residents = [],
    user,
}: Props) {
    const [qrInput, setQrInput] = useState('');
    const [feedback, setFeedback] = useState<{
        text: string;
        type: Exclude<FeedbackTone, 'info'>;
    } | null>(null);
    const [activeTab, setActiveTab] = useState<AttendanceTab>('present');
    const [manualResidentId, setManualResidentId] = useState('');
    const [isProcessingScan, setIsProcessingScan] = useState(false);
    const [manualProcessing, setManualProcessing] = useState(false);
    // Advances only when a scan attempt finishes or the target tab changes,
    // so the scan field is focused again without stealing it on other edits.
    const [scanEvent, setScanEvent] = useState(0);
    const qrInputRef = useRef<HTMLInputElement | null>(null);
    const operationInFlightRef = useRef(false);

    /*
    |--------------------------------------------------------------------------
    | Scanner focus
    |--------------------------------------------------------------------------
    |
    | A handheld 2D scanner types into whatever has focus, so the QR field
    | has to hold the focus again after every scan attempt and whenever the
    | target tab changes. The counter makes repeated identical results still
    | restore the focus, and preventScroll keeps the page from jumping.
    |
    */

    useEffect(() => {
        qrInputRef.current?.focus({ preventScroll: true });
    }, [scanEvent, activeTab]);

    const processingLabel = isProcessingScan
        ? 'Vine-verify at itinatala ang QR attendance...'
        : manualProcessing
          ? 'Itinatala ang manual attendance...'
          : null;
    const statusTone: FeedbackTone | undefined = processingLabel
        ? 'info'
        : feedback?.type;
    const statusText = processingLabel ?? feedback?.text;

    const resetScanState = () => {
        setIsProcessingScan(false);
        operationInFlightRef.current = false;
        setScanEvent((event) => event + 1);
    };

    const submitScan = async () => {
        if (operationInFlightRef.current) {
            return;
        }

        const token = normalizeQrToken(qrInput);

        if (!token) {
            setFeedback({
                text: 'Walang QR token na na-scan. Subukang muli.',
                type: 'warning',
            });
            setScanEvent((event) => event + 1);
            return;
        }

        const targetStatus = activeTab;
        const markUrl = `/clerk/announcements/${announcement.id}/attendance/mark`;

        setQrInput('');
        setFeedback(null);
        setIsProcessingScan(true);
        operationInFlightRef.current = true;

        let response: Response;

        try {
            response = await fetch(
                `/api/qr/verify-token?token=${encodeURIComponent(token)}&announcement_id=${announcement.id}`,
                { headers: { Accept: 'application/json' } },
            );
        } catch {
            setFeedback({
                text: 'Nabigong i-verify ang QR code dahil sa network. Subukang muli.',
                type: 'error',
            });
            resetScanState();
            return;
        }

        let payload: QrVerificationResponse | null = null;

        try {
            payload = (await response.json()) as QrVerificationResponse;
        } catch {
            payload = null;
        }

        const residentId = payload?.resident?.id;
        const qrProof = payload?.qr_proof;

        if (
            !response.ok ||
            typeof residentId !== 'number' ||
            typeof qrProof !== 'string' ||
            qrProof === ''
        ) {
            setFeedback({
                text: `Hindi na-verify ang QR code. ${verificationErrorMessage(response.status, payload?.message)}`,
                type: 'error',
            });
            resetScanState();
            return;
        }

        if (
            targetStatus === 'claimed' &&
            !attendances.some(
                (item) =>
                    item.resident?.id === residentId && item.status === 'present',
            )
        ) {
            setFeedback({
                text: 'This resident is not present.',
                type: 'warning',
            });
            resetScanState();
            return;
        }

        let outcomeSettled = false;

        try {
            router.post(
                markUrl,
                {
                    resident_id: residentId,
                    status: targetStatus,
                    method: 'qr_scanner',
                    qr_proof: qrProof,
                },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (outcomeSettled) return;

                        outcomeSettled = true;
                        const serverFeedback = attendanceFeedbackFromPage(page);

                        if (serverFeedback) {
                            setFeedback(serverFeedback);
                        }

                        setQrInput('');
                        router.reload({ only: ['attendances'] });
                    },
                    onError: (errors) => {
                        if (outcomeSettled) return;

                        outcomeSettled = true;
                        const message = firstValidationError(errors);
                        setFeedback({
                            text: `Hindi naitala ang attendance.${message ? ` ${message}` : ' Subukang muli.'}`,
                            type: 'error',
                        });
                    },
                    onHttpException: (error) => {
                        if (outcomeSettled) return false;

                        outcomeSettled = true;
                        const message = recordingErrorMessage(
                            error.status,
                            extractServerMessage(error.data),
                        );
                        setFeedback({
                            text: `Hindi naitala ang attendance. ${message}`,
                            type: 'error',
                        });

                        return false;
                    },
                    onNetworkError: () => {
                        if (outcomeSettled) return false;

                        outcomeSettled = true;
                        setFeedback({
                            text: 'Hindi matiyak kung naitala ang attendance dahil sa network. I-check ang listahan bago mag-scan muli.',
                            type: 'warning',
                        });

                        return false;
                    },
                    onFinish: () => {
                        if (!outcomeSettled) {
                            outcomeSettled = true;
                            setFeedback({
                                text: 'Hindi matiyak kung naitala ang attendance. I-check ang listahan bago mag-scan muli.',
                                type: 'warning',
                            });
                        }

                        resetScanState();
                    },
                },
            );
        } catch {
            setFeedback({
                text: 'Hindi naipadala ang attendance. Subukang muli.',
                type: 'error',
            });
            resetScanState();
        }
    };

    const submitManual = () => {
        if (operationInFlightRef.current) {
            return;
        }

        const residentId = Number(manualResidentId);

        if (!Number.isInteger(residentId) || residentId <= 0) {
            setFeedback({
                text: 'Pumili muna ng residente bago itala ang manual attendance.',
                type: 'warning',
            });
            return;
        }

        const targetStatus = activeTab;

        if (
            targetStatus === 'claimed' &&
            !attendances.some(
                (item) =>
                    item.resident?.id === residentId && item.status === 'present',
            )
        ) {
            setFeedback({
                text: 'This resident is not present.',
                type: 'warning',
            });
            return;
        }

        const markUrl = `/clerk/announcements/${announcement.id}/attendance/mark`;

        setFeedback(null);
        setManualProcessing(true);
        operationInFlightRef.current = true;

        let outcomeSettled = false;

        try {
            router.post(
                markUrl,
                {
                    resident_id: residentId,
                    status: targetStatus,
                    method: 'manual',
                },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (outcomeSettled) return;

                        outcomeSettled = true;
                        const serverFeedback = attendanceFeedbackFromPage(page);

                        if (serverFeedback) {
                            setFeedback(serverFeedback);
                        }

                        setManualResidentId('');
                        setScanEvent((event) => event + 1);
                        router.reload({ only: ['attendances'] });
                    },
                    onError: (errors) => {
                        if (outcomeSettled) return;

                        outcomeSettled = true;
                        const message = firstValidationError(errors);
                        setFeedback({
                            text: `Hindi naitala ang attendance.${message ? ` ${message}` : ' Subukang muli.'}`,
                            type: 'error',
                        });
                    },
                    onHttpException: (error) => {
                        if (outcomeSettled) return false;

                        outcomeSettled = true;
                        const message = recordingErrorMessage(
                            error.status,
                            extractServerMessage(error.data),
                        );
                        setFeedback({
                            text: `Hindi naitala ang attendance. ${message}`,
                            type: 'error',
                        });

                        return false;
                    },
                    onNetworkError: () => {
                        if (outcomeSettled) return false;

                        outcomeSettled = true;
                        setFeedback({
                            text: 'Hindi matiyak kung naitala ang attendance dahil sa network. I-check ang listahan bago subukan muli.',
                            type: 'warning',
                        });

                        return false;
                    },
                    onFinish: () => {
                        if (!outcomeSettled) {
                            outcomeSettled = true;
                            setFeedback({
                                text: 'Hindi matiyak kung naitala ang attendance. I-check ang listahan bago subukan muli.',
                                type: 'warning',
                            });
                        }

                        setManualProcessing(false);
                        operationInFlightRef.current = false;
                    },
                },
            );
        } catch {
            setFeedback({
                text: 'Hindi naipadala ang attendance. Subukang muli.',
                type: 'error',
            });
            setManualProcessing(false);
            operationInFlightRef.current = false;
        }
    };

    const filteredAttendances = attendances.filter((item) => {
        if (activeTab === 'claimed') return item.status === 'claimed';
        if (activeTab === 'present') return item.status === 'present';
        return true;
    });

    return (
        <ClerkLayout active="announcements" user={user}>
            <Head title={`Attendance - ${announcement.title}`} />
            <OfflineAttendance
                announcementId={announcement.id}
                department="clerk"
            />

            <div className="mx-auto max-w-7xl space-y-6 pb-10">
                <div className="flex flex-col gap-4 rounded-xl border border-line-soft/80 bg-white p-5 shadow-card sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="rounded-full border border-brand-muted bg-brand-soft px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-brand uppercase">
                                {announcement.category}
                            </span>
                            <span className="text-xs text-ink-faint">•</span>
                            <span className="text-xs font-medium text-ink-muted">
                                {announcement.location
                                    ? `${announcement.location}`
                                    : 'Walang nakasaad na lokasyon'}
                            </span>
                        </div>
                        <h1 className="text-xl font-semibold tracking-tight text-ink-strong">
                            {announcement.title}
                        </h1>
                    </div>

                    <a
                        href="/clerk/announcements"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-line bg-white px-4 py-2 text-xs font-semibold text-ink shadow-card transition hover:bg-canvas"
                    >
                        <span></span> Bumalik sa Announcements
                    </a>
                </div>

                {statusText && statusTone && (
                    <div
                        role={statusTone === 'error' ? 'alert' : 'status'}
                        aria-live="polite"
                        aria-atomic="true"
                        className={`flex items-start gap-2.5 rounded-xl border p-3.5 text-xs font-semibold ${feedbackToneClasses[statusTone]}`}
                    >
                        <span
                            className={`mt-1 h-2 w-2 shrink-0 rounded-full ${feedbackDotClasses[statusTone]} ${statusTone === 'info' ? 'animate-pulse' : ''}`}
                            aria-hidden="true"
                        />
                        <span>{statusText}</span>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    <div className="flex flex-col justify-between space-y-4 rounded-xl border border-line-soft/80 bg-white p-6 shadow-card lg:col-span-7">
                        <div className="space-y-4">
                            <div className="flex items-center justify-between border-b border-line-soft pb-3">
                                <div className="flex items-center gap-2.5">
                                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-soft text-sm text-brand">
                                        
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-semibold text-ink-strong">
                                            QR Code / Barcode Scanner
                                        </h3>
                                        <p className="text-xs text-ink-muted">
                                            Kasalukuyang mode:{' '}
                                            <strong className="text-brand uppercase">
                                                {activeTab}
                                            </strong>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    void submitScan();
                                }}
                                className="space-y-3 pt-1"
                            >
                                <div className="space-y-1.5">
                                    <label
                                        htmlFor="qr-token-input"
                                        className="block text-xs font-semibold text-ink"
                                    >
                                        QR Token Input
                                    </label>
                                    <input
                                        id="qr-token-input"
                                        ref={qrInputRef}
                                        type="text"
                                        value={qrInput}
                                        onChange={(event) =>
                                            setQrInput(event.target.value)
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                void submitScan();
                                            }
                                        }}
                                        readOnly={
                                            isProcessingScan || manualProcessing
                                        }
                                        placeholder={`I-scan para itala bilang ${activeTab}...`}
                                        className="w-full rounded-lg border border-line bg-canvas/50 p-3 font-mono text-xs outline-none read-only:cursor-wait read-only:bg-secondary focus:border-brand focus:ring-1 focus:ring-[#3b6fa8]"
                                        autoFocus
                                    />
                                    <p className="text-[11px] text-ink-muted">
                                        I-scan lang ang QR code. Awtomatikong
                                        vine-verify at itinatala kapag
                                        nakumpleto ng scanner ang entry.
                                    </p>
                                </div>
                                <button
                                    type="submit"
                                    disabled={
                                        isProcessingScan || manualProcessing
                                    }
                                    className="w-full rounded-lg bg-brand py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:cursor-wait disabled:opacity-70"
                                >
                                    {isProcessingScan
                                        ? 'Vine-verify at itinatala...'
                                        : `Prosesuhin ang QR Scan (${activeTab.toUpperCase()})`}
                                </button>
                            </form>
                        </div>
                    </div>

                    <div className="flex flex-col justify-between space-y-4 rounded-xl border border-line-soft/80 bg-white p-6 shadow-card lg:col-span-5">
                        <div className="space-y-4">
                            <div className="flex items-center gap-2.5 border-b border-line-soft pb-3">
                                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-warning-soft text-sm text-warning" aria-hidden="true"></span>
                                <div>
                                    <h3 className="text-sm font-semibold text-ink-strong">
                                        Manual Entry
                                    </h3>
                                    <p className="text-xs text-ink-muted">
                                        Mag-record para sa{' '}
                                        <strong className="text-brand uppercase">
                                            {activeTab}
                                        </strong>{' '}
                                        nang walang QR.
                                    </p>
                                </div>
                            </div>

                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    submitManual();
                                }}
                                className="space-y-3 pt-1"
                            >
                                <div className="space-y-1.5">
                                    <AttendanceResidentSelect
                                        residents={residents}
                                        value={manualResidentId}
                                        onChange={setManualResidentId}
                                        disabled={
                                            isProcessingScan || manualProcessing
                                        }
                                    />
                                    <p className="text-[11px] text-ink-muted">
                                        Para sa rehistradong residente na walang
                                        ma-scan na QR code.
                                    </p>
                                </div>

                                <button
                                    type="submit"
                                    disabled={
                                        isProcessingScan || manualProcessing
                                    }
                                    className="w-full rounded-lg bg-brand py-3 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:cursor-wait disabled:opacity-70"
                                >
                                    {manualProcessing
                                        ? 'Itinatala...'
                                        : `Itala bilang ${activeTab.toUpperCase()} Manually`}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-line-soft/80 bg-white shadow-card">
                    <div className="flex flex-col items-start justify-between gap-3 border-b border-line-soft bg-canvas/70 px-6 py-3 sm:flex-row sm:items-center">
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setActiveTab('present')}
                                disabled={isProcessingScan || manualProcessing}
                                aria-pressed={activeTab === 'present'}
                                className={`rounded-lg px-4 py-2 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60 ${
                                    activeTab === 'present'
                                        ? 'bg-brand text-white shadow-card'
                                        : 'border border-line bg-white text-ink-muted hover:bg-canvas'
                                }`}
                            >
                                Event Present / Dumalo (
                                {
                                    attendances.filter(
                                        (i) => i.status === 'present',
                                    ).length
                                }
                                )
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('claimed')}
                                disabled={isProcessingScan || manualProcessing}
                                aria-pressed={activeTab === 'claimed'}
                                className={`rounded-lg px-4 py-2 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60 ${
                                    activeTab === 'claimed'
                                        ? 'bg-brand text-white shadow-card'
                                        : 'border border-line bg-white text-ink-muted hover:bg-canvas'
                                }`}
                            >
                                Aid Claimed / Naka-receive (
                                {
                                    attendances.filter(
                                        (i) => i.status === 'claimed',
                                    ).length
                                }
                                )
                            </button>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-ink-muted">
                            <thead className="border-b border-line-soft bg-white text-[11px] font-semibold tracking-wider text-ink-faint uppercase">
                                <tr>
                                    <th className="px-6 py-3.5">
                                        Pangalan ng Residente
                                    </th>
                                    <th className="px-6 py-3.5">ID Number</th>
                                    <th className="px-6 py-3.5">Status</th>
                                    <th className="px-6 py-3.5">Paraan</th>
                                    <th className="px-6 py-3.5 text-right">
                                        Oras ng Pagtala
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line-soft text-xs">
                                {filteredAttendances.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="p-10 text-center text-ink-faint italic"
                                        >
                                            Wala pang nakatalang rekord para sa
                                            tab na ito ({activeTab}).
                                        </td>
                                    </tr>
                                ) : (
                                    filteredAttendances.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="hover:bg-canvas/80"
                                        >
                                            <td className="px-6 py-3.5 font-semibold text-ink-strong">
                                                {item.resident?.last_name},{' '}
                                                {item.resident?.first_name}
                                            </td>
                                            <td className="px-6 py-3.5 font-mono text-ink-muted">
                                                {item.resident?.resident_number}
                                            </td>
                                            <td className="px-6 py-3.5">
                                                <span
                                                    className={`rounded px-2.5 py-1 text-xs font-semibold uppercase ${
                                                        item.status ===
                                                        'claimed'
                                                            ? 'bg-brand-soft text-brand-deep'
                                                            : item.status ===
                                                                'present'
                                                              ? 'bg-[#edf4ea] text-brand'
                                                              : 'bg-red-100 text-red-800'
                                                    }`}
                                                >
                                                    {item.status}
                                                </span>
                                            </td>
                                            <td className="px-6 py-3.5 text-xs text-ink-muted uppercase">
                                                {item.method}
                                            </td>
                                            <td className="px-6 py-3.5 text-right text-xs text-ink-muted">
                                                {item.attended_at}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </ClerkLayout>
    );
}
