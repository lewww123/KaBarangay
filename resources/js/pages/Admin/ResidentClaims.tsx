import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useEffect, useRef, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type ClaimRow = {
    id: number;
    reference: string;
    residentName: string | null;
    residentNumber: string | null;
    email: string;
    emailVerified: boolean;
    status: string;
    submittedAt: string | null;
    reviewedAt: string | null;
};

type RegistrationRow = {
    id: number;
    reference: string;
    applicantName: string;
    email: string | null;
    status: string;
    submittedAt: string | null;
    residentNumber: string | null;
};

type Candidate = {
    id: number;
    resident_number: string;
    full_name: string;
    birthdate: string | null;
    purok: string | null;
    email: string | null;
    account_exists: boolean;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Counts = {
    pending: number;
    rejected: number;
    needs_correction: number;
    registration_pending: number;
    registration_rejected: number;
};

type Notification = {
    id: number;
    type: string;
    status: string;
    attempts: number;
    sent_at: string | null;
    failed_at: string | null;
};

type ClaimDetails = {
    claim: {
        reference: string;
        email: string;
        status: string;
        identity_checked_at: string | null;
        identity_method: string | null;
        review_notes: string | null;
        submitted_at: string | null;
        reviewed_at: string | null;
        reviewer: string | null;
    };
    resident: {
        resident_number: string | null;
        full_name: string | null;
        birthdate: string | null;
        sex: string | null;
        civil_status: string | null;
        address_line: string | null;
        mobile: string | null;
        email: string | null;
        email_verified: boolean;
        status: string | null;
        purok: string | null;
    };
    notification: Notification | null;
};

type Props = {
    claims: Paginator<ClaimRow>;
    registrations: Paginator<RegistrationRow>;
    status: string;
    search: string;
    counts: Counts;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};

export default function ResidentClaims({
    claims,
    registrations,
    status,
    search,
    counts,
    flash,
}: Props) {
    const [details, setDetails] = useState<ClaimDetails | null>(null);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [detailsError, setDetailsError] = useState('');
    const [actionError, setActionError] = useState('');
    const [working, setWorking] = useState(false);
    const [reason, setReason] = useState('');
    const [application, setApplication] = useState<Record<string, unknown> | null>(null);
    const [applicationId, setApplicationId] = useState<number | null>(null);
    const [applicationLoading, setApplicationLoading] = useState(false);
    const [applicationError, setApplicationError] = useState('');
    const [candidateQuery, setCandidateQuery] = useState('');
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [candidateId, setCandidateId] = useState<number | null>(null);
    const [candidateLoading, setCandidateLoading] = useState(false);
    const [candidateError, setCandidateError] = useState('');
    const [approvalBusy, setApprovalBusy] = useState(false);
    const actionInFlight = useRef(false);
    const [applicationRejectReason, setApplicationRejectReason] = useState('');

    const openApplication = async (row: RegistrationRow) => {
        setApplicationId(row.id);
        setApplication(null);
        setApplicationLoading(true);
        setApplicationError('');
        setCandidateId(null);
        setCandidateQuery('');
        setCandidates([]);
        setCandidateLoading(false);
        setCandidateError('');
        setApplicationRejectReason('');
        try {
            const response = await fetch(`/admin/resident-registrations/${row.id}/details`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json();
            if (!response.ok || payload?.type !== 'registration' || !payload?.data) {
                throw new Error(`Unable to load application details (HTTP ${response.status}).`);
            }
            setApplication(payload.data as Record<string, unknown>);
        } catch (error) {
            setApplicationError(error instanceof Error ? error.message : 'Unable to load application details.');
        } finally {
            setApplicationLoading(false);
        }
    };

    useEffect(() => {
        const query = candidateQuery.trim();

        if (applicationId === null || query.length < 2) {
            setCandidates([]);
            setCandidateLoading(false);
            setCandidateError('');
            return;
        }

        const controller = new AbortController();
        setCandidates([]);
        setCandidateLoading(true);
        setCandidateError('');
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(`/admin/resident-registrations/${applicationId}/candidates?q=${encodeURIComponent(query)}`, {
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const payload = await response.json();
                if (!response.ok || !Array.isArray(payload?.data)) throw new Error('Unable to search eligible Residents.');
                setCandidates(payload.data as Candidate[]);
            } catch (error) {
                if (!controller.signal.aborted) setCandidateError(error instanceof Error ? error.message : 'Unable to search eligible Residents.');
            } finally {
                if (!controller.signal.aborted) setCandidateLoading(false);
            }
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [applicationId, candidateQuery]);

    const approveApplication = () => {
        if (actionInFlight.current) return;
        if (!applicationId || !candidateId) {
            setApplicationError('Select an existing Resident before linking this application.');
            return;
        }
        actionInFlight.current = true;
        setApprovalBusy(true);
        setApplicationError('');
        router.post(`/admin/residents/${applicationId}/approve`, { resident_id: candidateId }, {
            preserveScroll: true,
            onSuccess: () => { setApplication(null); setApplicationId(null); },
            onError: (errors) => setApplicationError((typeof errors.resident_id === 'string' && errors.resident_id) || (typeof errors.approval === 'string' && errors.approval) || 'Unable to link this application.'),
            onFinish: () => { actionInFlight.current = false; setApprovalBusy(false); },
        });
    };

    const rejectApplication = () => {
        if (actionInFlight.current) return;
        if (!applicationId || applicationRejectReason.trim().length < 5) {
            setApplicationError('Enter a clear rejection reason of at least five characters.');
            return;
        }
        actionInFlight.current = true;
        setApprovalBusy(true);
        setApplicationError('');
        router.post(`/admin/residents/${applicationId}/reject`, { reason: applicationRejectReason.trim() }, {
            preserveScroll: true,
            onSuccess: () => { setApplication(null); setApplicationId(null); },
            onError: (errors) => setApplicationError(typeof errors.reason === 'string' ? errors.reason : 'Unable to reject this application.'),
            onFinish: () => { actionInFlight.current = false; setApprovalBusy(false); },
        });
    };

    const openDetails = async (claim: ClaimRow) => {
        setDetailsLoading(true);
        setDetailsError('');
        setActionError('');
        setReason('');

        try {
            const response = await fetch(
                `/admin/resident-claims/${claim.id}/details`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            const payload = await response.json();

            if (!response.ok) {
                throw new Error('Unable to load claim details.');
            }

            setDetails(payload as ClaimDetails);
        } catch (error) {
            setDetailsError(
                error instanceof Error
                    ? error.message
                    : 'Unable to load claim details.',
            );
        } finally {
            setDetailsLoading(false);
        }
    };

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !working) {
                setDetails(null);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [working]);

    const approveClaim = () => {
        if (!details) {
            return;
        }

        const claimId = claims.data.find(
            (claim) => claim.reference === details.claim.reference,
        )?.id;

        if (!claimId) {
            setActionError('Unable to identify this claim. Reload the page.');
            return;
        }

        if (
            !window.confirm(
                `Approve this claim and link the account to ${details.resident.full_name}?`,
            )
        ) {
            return;
        }

        setWorking(true);
        setActionError('');

        router.post(
            `/admin/resident-claims/${claimId}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setDetails(null),
                onError: (errors) => {
                    setActionError(
                        typeof errors.claim === 'string'
                            ? errors.claim
                            : 'Unable to approve this claim.',
                    );
                },
                onFinish: () => setWorking(false),
            },
        );
    };

    const rejectClaim = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!details) {
            return;
        }

        const claimId = claims.data.find(
            (claim) => claim.reference === details.claim.reference,
        )?.id;

        if (!claimId) {
            setActionError('Unable to identify this claim. Reload the page.');
            return;
        }

        if (reason.trim().length < 5) {
            setActionError('Enter a clear rejection reason.');
            return;
        }

        setWorking(true);
        setActionError('');

        router.post(
            `/admin/resident-claims/${claimId}/reject`,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => setDetails(null),
                onError: (errors) => {
                    setActionError(
                        typeof errors.reason === 'string'
                            ? errors.reason
                            : 'Unable to reject this claim.',
                    );
                },
                onFinish: () => setWorking(false),
            },
        );
    };

    const retryNotification = () => {
        if (!details?.notification) {
            return;
        }

        const claimId = claims.data.find(
            (claim) => claim.reference === details.claim.reference,
        )?.id;

        if (!claimId) {
            setActionError('Unable to identify this claim. Reload the page.');
            return;
        }

        setWorking(true);
        setActionError('');

        router.post(
            `/admin/resident-claims/${claimId}/notification/retry`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setDetails(null),
                onError: () => {
                    setActionError('Unable to retry the notification.');
                },
                onFinish: () => setWorking(false),
            },
        );
    };

    return (
        <>
            <Head title="Online Accounts" />

            <AdminLayout active="residents">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">
                            Online Accounts
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Review registration applications and the remaining legacy Claim queue.
                            Registration approval requires an explicit selection of an existing Resident.
                        </p>
                    </div>

                    <Link
                        href="/admin/residents"
                        className="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Back to Residents
                    </Link>
                </div>

                {flash?.success && (
                    <div className="mb-4 rounded-lg border border-[#b7d8b2] bg-[#edf4ea] px-4 py-3 text-sm font-medium text-[#3f6f3d]">
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                        {flash.error}
                    </div>
                )}

                <div className="mb-5 flex overflow-x-auto border-b border-gray-200">
                    <StatusTab
                        href={`/admin/resident-claims?status=pending${search ? `&search=${encodeURIComponent(search)}` : ''}`}
                        label="Pending"
                        count={counts.pending + counts.registration_pending}
                        active={status === 'pending'}
                    />
                    <StatusTab
                        href={`/admin/resident-claims?status=needs_correction${search ? `&search=${encodeURIComponent(search)}` : ''}`}
                        label="Needs Correction"
                        count={counts.needs_correction}
                        active={status === 'needs_correction'}
                    />
                    <StatusTab
                        href={`/admin/resident-claims?status=rejected${search ? `&search=${encodeURIComponent(search)}` : ''}`}
                        label="Rejected"
                        count={counts.rejected + counts.registration_rejected}
                        active={status === 'rejected'}
                    />
                </div>

                <form method="get" action="/admin/resident-claims" className="mb-4 flex gap-2">
                    <input type="hidden" name="status" value={status} />
                    <input name="search" defaultValue={search} placeholder="Search applications and claims..." className="w-full max-w-md rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    <button className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold">Search</button>
                </form>

                <section className="mb-8 overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="border-b border-gray-200 px-4 py-3">
                        <h2 className="text-sm font-semibold text-gray-800">Resident registrations</h2>
                        <p className="mt-1 text-xs text-gray-500">Registrations and historical Claims remain separate review records.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-[#fafaf7] text-[10px] tracking-wide text-gray-500 uppercase"><tr><th className="px-4 py-3">Applicant</th><th className="px-4 py-3">Application</th><th className="px-4 py-3">Resident ID</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Submitted</th><th className="px-4 py-3 text-right">Action</th></tr></thead>
                            <tbody className="divide-y divide-gray-100">
                                {registrations.data.map((row) => <tr key={row.id}>
                                    <td className="px-4 py-3 font-medium text-gray-800">{row.applicantName || 'Applicant name unavailable'}<div className="text-xs font-normal text-gray-500">{row.email || 'No email'}</div></td>
                                    <td className="px-4 py-3 font-mono text-xs">{row.reference}</td>
                                    <td className="px-4 py-3 font-mono text-xs">{row.residentNumber || 'Not linked'}</td>
                                    <td className="px-4 py-3"><StatusPill status={row.status === 'approved' ? 'linked' : row.status.replace('_', ' ')} /></td>
                                    <td className="px-4 py-3 text-xs text-gray-500">{row.submittedAt || '—'}</td>
                                    <td className="px-4 py-3 text-right"><button type="button" onClick={() => openApplication(row)} className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold">{row.status === 'pending' || row.status === 'needs_correction' ? 'Review' : 'Details'}</button></td>
                                </tr>)}
                                {registrations.data.length === 0 && <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-500">No registration applications in this status.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-xs text-gray-500"><span>{registrations.total} registration{registrations.total === 1 ? '' : 's'}</span><div className="flex gap-2">{registrations.prev_page_url && <Link href={registrations.prev_page_url} className="rounded-md border px-3 py-1.5">Previous</Link>}{registrations.next_page_url && <Link href={registrations.next_page_url} className="rounded-md border px-3 py-1.5">Next</Link>}</div></div>
                </section>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-[#fafaf7] text-[10px] tracking-wide text-gray-500 uppercase">
                                <tr>
                                    <th className="px-4 py-3">Resident</th>
                                    <th className="px-4 py-3">Resident ID</th>
                                    <th className="px-4 py-3">Email</th>
                                    <th className="px-4 py-3">Email Status</th>
                                    <th className="px-4 py-3">Submitted</th>
                                    <th className="px-4 py-3 text-right">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {claims.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-8 text-center text-sm text-gray-500"
                                        >
                                            No {status} claims.
                                        </td>
                                    </tr>
                                )}

                                {claims.data.map((claim) => (
                                    <tr
                                        key={claim.id}
                                        className="hover:bg-[#fafaf7]"
                                    >
                                        <td className="px-4 py-3 font-medium text-gray-800">
                                            {claim.residentName ||
                                                'Unknown Resident'}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs text-gray-600">
                                            {claim.residentNumber || '-'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-600">
                                            {claim.email}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-1 text-[10px] font-semibold ${claim.emailVerified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
                                            >
                                                {claim.emailVerified
                                                    ? 'Verified'
                                                    : 'Unverified'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">
                                            {claim.submittedAt || '-'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    openDetails(claim)
                                                }
                                                className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                            >
                                                Review
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-xs text-gray-500">
                        <span>
                            {claims.total} claim{claims.total === 1 ? '' : 's'}
                        </span>
                        <div className="flex gap-2">
                            {claims.prev_page_url && (
                                <Link
                                    href={claims.prev_page_url}
                                    className="rounded-md border border-gray-300 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                                >
                                    Previous
                                </Link>
                            )}
                            {claims.next_page_url && (
                                <Link
                                    href={claims.next_page_url}
                                    className="rounded-md border border-gray-300 px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50"
                                >
                                    Next
                                </Link>
                            )}
                        </div>
                    </div>
                </div>

                {detailsLoading && (
                    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/30">
                        <div className="rounded-lg bg-white px-5 py-4 text-sm text-gray-600 shadow-lg">
                            Loading claim...
                        </div>
                    </div>
                )}

                {detailsError && (
                    <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {detailsError}
                    </div>
                )}

                {details && (
                    <Modal onClose={() => !working && setDetails(null)}>
                        <div className="my-auto w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
                            <div className="flex items-start justify-between border-b border-gray-200 px-5 py-4">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-800">
                                        Claim Review
                                    </h2>
                                    <p className="mt-1 font-mono text-xs text-gray-500">
                                        {details.claim.reference}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => !working && setDetails(null)}
                                    className="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100"
                                    aria-label="Close"
                                >
                                    X
                                </button>
                            </div>

                            <div className="max-h-[65vh] space-y-5 overflow-y-auto px-5 py-5">
                                {actionError && (
                                    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
                                        {actionError}
                                    </div>
                                )}

                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusPill status={details.claim.status} />
                                    <span
                                        className={`rounded-full px-2 py-1 text-[10px] font-semibold ${details.resident.email_verified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}
                                    >
                                        Email{' '}
                                        {details.resident.email_verified
                                            ? 'verified'
                                            : 'unverified'}
                                    </span>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Info
                                        label="Resident"
                                        value={
                                            details.resident.full_name || '-'
                                        }
                                    />
                                    <Info
                                        label="Resident ID"
                                        value={
                                            details.resident.resident_number ||
                                            '-'
                                        }
                                    />
                                    <Info
                                        label="Birthdate"
                                        value={
                                            details.resident.birthdate || '-'
                                        }
                                    />
                                    <Info
                                        label="Purok"
                                        value={details.resident.purok || '-'}
                                    />
                                    <Info
                                        label="Record Email"
                                        value={details.resident.email || '-'}
                                    />
                                    <Info
                                        label="Claim Email"
                                        value={details.claim.email}
                                    />
                                    <Info
                                        label="Mobile"
                                        value={details.resident.mobile || '-'}
                                    />
                                    <Info
                                        label="Resident Status"
                                        value={details.resident.status || '-'}
                                    />
                                    <Info
                                        label="Identity Check"
                                        value={
                                            details.claim.identity_checked_at ||
                                            '-'
                                        }
                                    />
                                    <Info
                                        label="Identity Method"
                                        value={
                                            details.claim.identity_method || '-'
                                        }
                                    />
                                </div>

                                <div className="rounded-lg border border-gray-200 bg-[#fafaf7] p-4">
                                    <p className="text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                                        Approval Notification
                                    </p>
                                    {details.notification ? (
                                        <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-600">
                                            <StatusPill
                                                status={
                                                    details.notification.status
                                                }
                                            />
                                            <span>
                                                Attempts:{' '}
                                                {details.notification.attempts}
                                            </span>
                                            {details.notification.sent_at && (
                                                <span>
                                                    Sent:{' '}
                                                    {
                                                        details.notification
                                                            .sent_at
                                                    }
                                                </span>
                                            )}
                                            {details.notification.failed_at && (
                                                <span>
                                                    Failed:{' '}
                                                    {
                                                        details.notification
                                                            .failed_at
                                                    }
                                                </span>
                                            )}
                                            {details.notification.status !==
                                                'sent' && (
                                                <button
                                                    type="button"
                                                    onClick={retryNotification}
                                                    disabled={working}
                                                    className="rounded-md border border-gray-300 px-3 py-1.5 font-semibold text-gray-700 hover:bg-white disabled:opacity-60"
                                                >
                                                    Retry Email
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="mt-2 text-xs text-gray-500">
                                            No notification is queued yet. It is
                                            created when the claim is approved.
                                        </p>
                                    )}
                                </div>

                                {details.claim.review_notes && (
                                    <div className="rounded-lg border border-gray-200 bg-white p-4">
                                        <p className="text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                                            Review Notes
                                        </p>
                                        <p className="mt-1 text-sm whitespace-pre-line text-gray-700">
                                            {details.claim.review_notes}
                                        </p>
                                    </div>
                                )}

                                {details.claim.status === 'pending' && (
                                    <form
                                        onSubmit={rejectClaim}
                                        className="rounded-lg border border-red-200 bg-red-50/60 p-4"
                                    >
                                        <label className="block text-xs font-semibold text-red-800">
                                            Rejection Reason
                                        </label>
                                        <textarea
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                            rows={3}
                                            className="mt-1 w-full rounded-md border border-red-200 bg-white px-3 py-2 text-sm"
                                            placeholder="Explain why this claim cannot be approved."
                                        />
                                        <div className="mt-3 flex flex-wrap justify-end gap-2">
                                            <button
                                                type="submit"
                                                disabled={working}
                                                className="rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-60"
                                            >
                                                Reject Claim
                                            </button>
                                            <button
                                                type="button"
                                                onClick={approveClaim}
                                                disabled={
                                                    working ||
                                                    !details.resident
                                                        .email_verified
                                                }
                                                className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {working
                                                    ? 'Working...'
                                                    : 'Approve & Link Account'}
                                            </button>
                                        </div>
                                        {!details.resident.email_verified && (
                                            <p className="mt-2 text-xs text-amber-700">
                                                The Resident must verify the
                                                recorded email before approval.
                                            </p>
                                        )}
                                    </form>
                                )}
                            </div>
                        </div>
                    </Modal>
                )}

                {applicationId !== null && (
                    <Modal onClose={() => { if (!approvalBusy) { setApplicationId(null); setApplication(null); } }}>
                        <div className="max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                            <div className="flex items-start justify-between gap-4">
                                <div><h2 className="text-lg font-semibold text-gray-800">Resident application review</h2><p className="mt-1 text-xs text-gray-500">Application #{applicationId}. Linking requires an explicit selection of an existing Resident.</p></div>
                                <button type="button" onClick={() => { if (!approvalBusy) { setApplicationId(null); setApplication(null); } }} disabled={approvalBusy} className="text-sm text-gray-500 disabled:cursor-not-allowed disabled:opacity-50">Close</button>
                            </div>
                            {applicationLoading && <p className="py-8 text-sm text-gray-500">Loading application details…</p>}
                            {applicationError && <p className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{applicationError}</p>}
                            {application && <div className="mt-5 space-y-5">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {(['reference', 'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'sex', 'civil_status', 'address_line', 'mobile', 'email', 'status', 'submitted_at', 'reviewed_at', 'reviewer', 'barangay', 'purok'] as const).map((field) => <Info key={field} label={field.replaceAll('_', ' ')} value={typeof application[field] === 'string' ? application[field] as string : '—'} />)}
                                </div>
                                {Array.isArray(application.documents) && application.documents.length > 0 && <div className="rounded-md border p-4"><h3 className="text-sm font-semibold">Submitted documents</h3><div className="mt-2 flex flex-wrap gap-2">{application.documents.map((raw, index) => { const doc = raw as { id?: number; label?: string; url?: string }; return <a key={doc.id ?? index} href={doc.url} target="_blank" rel="noreferrer" className="rounded-md border px-3 py-2 text-xs font-semibold text-[#3f6f3d]">{doc.label || 'View document'}</a>; })}</div></div>}
                                {Boolean(application.matched_resident) && typeof application.matched_resident === 'object' && <p className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">Already linked to Resident {(application.matched_resident as { resident_number?: string }).resident_number || ''}.</p>}
                                {application.status === 'needs_correction' && <p className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">This application is waiting for the applicant to correct and resubmit it. Admin decisions are available after it returns to Pending.</p>}
                                {application.status === 'pending' && !application.matched_resident && <div className="rounded-lg border border-gray-200 p-4">
                                    <label htmlFor="resident-candidate-query" className="block text-sm font-semibold">Find an existing Resident</label>
                                    <p className="mt-1 text-xs text-gray-500">Suggestions are not automatic matches. Review the details and select one candidate yourself.</p>
                                    <input id="resident-candidate-query" autoFocus value={candidateQuery} onChange={(event) => { setCandidateQuery(event.target.value); setCandidateId(null); setApplicationError(''); }} placeholder="Search by name, email, or Resident ID" className="mt-3 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                                    {candidateError && <p className="mt-2 text-sm text-red-700">{candidateError}</p>}
                                    <div className="mt-3 max-h-56 space-y-2 overflow-y-auto">{candidates.map((candidate) => {
                                        const emailMismatch = typeof application.email !== 'string' || !candidate.email || candidate.email.trim().toLowerCase() !== application.email.trim().toLowerCase();
                                        const conflict = candidate.account_exists || emailMismatch;
                                        return <label key={candidate.id} className={`flex items-start gap-3 rounded-md border p-3 ${conflict ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'} ${candidateId === candidate.id ? 'border-[#3f6f3d] bg-[#edf4ea]' : 'border-gray-200'}`}><input type="radio" name="resident_candidate" disabled={conflict} checked={candidateId === candidate.id} onChange={() => setCandidateId(candidate.id)} /><span className="text-sm"><strong>{candidate.full_name}</strong><span className="block font-mono text-xs text-gray-500">{candidate.resident_number} · {candidate.purok || 'Purok unavailable'}</span><span className="block text-xs text-gray-500">{candidate.email || 'No email'}{candidate.account_exists ? ' · existing account conflict' : emailMismatch ? ' · email does not match' : ''}</span></span></label>;
                                    })}</div>
                                    {candidateQuery.trim().length < 2 && <p className="mt-2 text-xs text-gray-500">Enter at least two characters to search.</p>}
                                    {candidateLoading && candidateQuery.trim().length >= 2 && <p role="status" className="mt-2 text-xs text-gray-500">Searching eligible Residents…</p>}
                                    {candidateQuery.trim().length >= 2 && !candidateLoading && candidates.length === 0 && !candidateError && <p className="mt-2 text-xs text-gray-500">No eligible candidate suggested. The application remains pending; no Resident or account will be created.</p>}
                                    <button type="button" onClick={approveApplication} disabled={!candidateId || approvalBusy} className="mt-4 rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{approvalBusy ? 'Linking…' : 'Select and link Resident'}</button>
                                </div>}
                                {application.status === 'pending' && <div className="rounded-lg border border-red-200 bg-red-50/60 p-4"><label htmlFor="resident-application-rejection-reason" className="block text-sm font-semibold text-red-800">Rejection reason</label><textarea id="resident-application-rejection-reason" value={applicationRejectReason} onChange={(event) => setApplicationRejectReason(event.target.value)} rows={2} className="mt-2 w-full rounded-md border border-red-200 bg-white px-3 py-2 text-sm" placeholder="Explain why the application is rejected." /><button type="button" onClick={rejectApplication} disabled={approvalBusy || applicationRejectReason.trim().length < 5} className="mt-3 rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 disabled:opacity-50">{approvalBusy ? 'Rejecting…' : 'Reject application'}</button></div>}
                            </div>}
                        </div>
                    </Modal>
                )}

            </AdminLayout>
        </>
    );
}

function StatusTab({
    href,
    label,
    count,
    active,
}: {
    href: string;
    label: string;
    count: number;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            className={`border-b-2 px-4 py-3 text-sm font-semibold whitespace-nowrap ${active ? 'border-[#3f6f3d] text-[#3f6f3d]' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
        >
            {label} ({count})
        </Link>
    );
}

function StatusPill({ status }: { status: string }) {
    const classes =
        status === 'approved' || status === 'linked' || status === 'sent' || status === 'verified' || status === 'active'
            ? 'bg-emerald-50 text-emerald-700'
            : status === 'rejected' || status === 'failed' || status === 'disabled'
              ? 'bg-red-50 text-red-700'
              : 'bg-amber-50 text-amber-700';

    return (
        <span
            className={`rounded-full px-2 py-1 text-[10px] font-semibold tracking-wide uppercase ${classes}`}
        >
            {status}
        </span>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm font-medium break-words text-gray-800">
                {value}
            </p>
        </div>
    );
}

function Modal({
    children,
    onClose,
}: {
    children: ReactNode;
    onClose: () => void;
}) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/40 p-4"
            onClick={onClose}
        >
            <div
                className="w-full max-w-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                {children}
            </div>
        </div>
    );
}
