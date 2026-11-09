import { Deferred, Head, router } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';
import { useEffect, useRef, useState } from 'react';

interface Actor {
    id: number;
    name: string;
    department: string | null;
    position?: string;
    department_label?: string;
}

interface AuditLogEntry {
    id: number;
    event: string;
    actor: Actor | null;
    created_at: string | null;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface LoginAttemptEntry {
    id: number;
    actor_type: string;
    actor_label?: string;
    channel: string;
    status: string;
    attempted_at: string | null;
}

interface SuspiciousAlertEntry {
    id: number;
    channel: string;
    alert_type: string;
    attempt_count: number;
    window_seconds: number;
    first_attempt_at: string | null;
    last_attempt_at: string | null;
    created_at: string | null;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginatorLink[];
    next_page_url: string | null;
    prev_page_url: string | null;
}

type AuditLogPaginator = Paginator<AuditLogEntry>;

type Props = {
    tab?: string;
    logs?: AuditLogPaginator;
    events?: string[];
    filters?: { event?: string };
    loginAttempts?: Paginator<LoginAttemptEntry>;
    loginAttemptsAvailable?: boolean;
    alerts?: Paginator<SuspiciousAlertEntry>;
    alertsAvailable?: boolean;
};

/*
 * Sector label for an audit event.
 *
 * Sectors are derived purely from the real event name's domain prefix
 * (the segment before the first dot), so nothing is invented: an
 * unrecognised domain falls back to a humanised version of its own prefix.
 */
const SECTOR_LABELS: Record<string, string> = {
    announcement: 'Announcements',
    certificate_request: 'Certificates',
    login: 'Authentication',
    resident: 'Residents',
    resident_registration: 'Resident Registration',
    staff: 'Staff',
};

function sectorForEvent(event: string): string {
    const domain = event.split('.')[0] ?? '';
    return SECTOR_LABELS[domain] ?? formatLabel(domain);
}

const emptyPaginator = <T,>(): Paginator<T> => ({
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    from: null,
    to: null,
    links: [],
    next_page_url: null,
    prev_page_url: null,
});

function formatDuration(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds <= 0) return '—';
    if (seconds % 3600 === 0) {
        const hours = seconds / 3600;
        return `${hours} hour${hours === 1 ? '' : 's'}`;
    }
    const minutes = Math.round(seconds / 60);
    return `${minutes} minute${minutes === 1 ? '' : 's'}`;
}

function formatLabel(value: string): string {
    return value.replace(/[_.]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatEventLabel(event: string): string {
    return event
        .split('.')
        .map((part) =>
            part.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
        )
        .join(' · ');
}

export default function AuditLog(props: Props) {
    return (
        <Deferred
            data={[
                'logs',
                'loginAttempts',
                'loginAttemptsAvailable',
                'alerts',
                'alertsAvailable',
            ]}
            fallback={
                <AdminLayout active="audit-log">
                    <AdminPageSkeleton showTabs />
                </AdminLayout>
            }
        >
            <AuditLogContent {...props} />
        </Deferred>
    );
}

function AuditLogContent({
    logs,
    events,
    filters,
    loginAttempts,
    loginAttemptsAvailable = true,
    alerts,
    alertsAvailable = true,
}: Props) {
    const paginator = logs ?? emptyPaginator<AuditLogEntry>();
    const rows = Array.isArray(paginator.data) ? paginator.data : [];
    const eventOptions = Array.isArray(events) ? events : [];
    const activeFilter = filters?.event ?? '';
    const [selectedEvent, setSelectedEvent] = useState(activeFilter);

    const attemptsPaginator =
        loginAttempts ?? emptyPaginator<LoginAttemptEntry>();
    const attempts = Array.isArray(attemptsPaginator.data)
        ? attemptsPaginator.data
        : [];

    const alertsPaginator = alerts ?? emptyPaginator<SuspiciousAlertEntry>();
    const alertRows = Array.isArray(alertsPaginator.data)
        ? alertsPaginator.data
        : [];

    const hasFilters = activeFilter !== '';

    const [isLoading, setIsLoading] = useState(false);
    const [navigationError, setNavigationError] = useState<string | null>(null);
    const lastRequestUrl = useRef<string | null>(null);

    // Last successfully committed event filter. `selectedEvent` is set
    // optimistically before a navigation, so if that navigation fails the
    // dropdown is restored from this ref instead. It is seeded from the
    // mount-time filter and advanced only on a genuine `success` event (see
    // below), so a failed or cancelled navigation can never overwrite it.
    const committedFilter = useRef(activeFilter);

    /*
     * Navigation lifecycle feedback.
     *
     * Inertia v3 exposes global events through `router.on()`: `start`/`finish`
     * bracket a visit, `httpException` reports a non-2xx response, and
     * `networkError` reports a transport failure. The returned unsubscribe
     * functions are called on unmount so these listeners cannot leak onto
     * other admin pages.
     */
    useEffect(() => {
        const isAuditVisit = (url: URL) =>
            String(url).includes('/admin/audit-log');

        const removeStart = router.on('start', (event) => {
            if (!isAuditVisit(event.detail.visit.url)) return;

            lastRequestUrl.current = String(event.detail.visit.url);
            setNavigationError(null);
            setIsLoading(true);
        });

        const removeFinish = router.on('finish', () => {
            setIsLoading(false);
        });

        // A committed page swap fires `success`: Inertia fires it only after
        // `setPage()`, and skips it for HTTP exceptions, network errors, and
        // cancellations (those paths only reach `finish`). Record the filter
        // the server actually committed for this page.
        const removeSuccess = router.on('success', (event) => {
            if (event.detail.page.component !== 'Admin/AuditLog') return;

            const committed = (
                event.detail.page.props as {
                    filters?: { event?: string };
                }
            ).filters?.event;

            committedFilter.current = committed ?? '';
        });

        const removeHttpException = router.on('httpException', (event) => {
            setIsLoading(false);
            setNavigationError(
                `The server could not load the audit log (HTTP ${event.detail.response.status}).`,
            );
            setSelectedEvent(committedFilter.current);

            // Returning false makes Inertia call event.preventDefault() on the
            // cancelable `inertia:httpException` event, which suppresses its
            // default full-screen error dialog. The inline banner above and its
            // Retry button stay visible and usable instead of being covered.
            return false;
        });

        const removeNetworkError = router.on('networkError', () => {
            setIsLoading(false);
            setNavigationError(
                'The audit log could not be reached. Check your connection and try again.',
            );
            setSelectedEvent(committedFilter.current);
        });

        return () => {
            removeStart();
            removeFinish();
            removeSuccess();
            removeHttpException();
            removeNetworkError();
        };
    }, []);

    /*
     * Retry the exact request that failed. The attempted URL (including its
     * query string) is captured on `start`, so the original filter/pagination
     * target is re-issued rather than whatever happens to be in the address
     * bar. The `isLoading` guard prevents a second request while one is open.
     */
    const retryNavigation = () => {
        if (isLoading) return;

        const target =
            lastRequestUrl.current ??
            `${window.location.pathname}${window.location.search}`;

        router.visit(target, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    /*
     * Server-driven reload. Only the audit listing, the event allowlist, and
     * the echoed filters are re-requested; the selected filter stays in the
     * URL so pagination links (and reloads) keep it.
     */
    const applyFilter = (event: string) => {
        setSelectedEvent(event);
        router.get(
            '/admin/audit-log',
            { event: event || undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['logs', 'filters'],
            },
        );
    };

    const navigateToPage = (url: string | null) => {
        if (!url) return;
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <>
            <Head title="Audit Log" />

            <AdminLayout active="audit-log">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-ink-strong">
                        Audit Log
                    </h1>

                    <p className="mt-1 text-sm text-ink-muted">
                        System activity and security monitoring
                    </p>
                </div>

                <div
                    className="mb-4 flex items-center gap-2"
                    role="status"
                    aria-live="polite"
                >
                    {isLoading && (
                        <span className="text-xs text-ink-muted">Loading…</span>
                    )}
                </div>

                {navigationError && (
                    <div
                        role="alert"
                        className="mb-3 flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span>{navigationError}</span>
                        <button
                            type="button"
                            onClick={retryNavigation}
                            disabled={isLoading}
                            className="self-start rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isLoading ? 'Retrying…' : 'Retry'}
                        </button>
                    </div>
                )}

                <div className="flex flex-col gap-8">
                    <ActivityLogSection
                        paginator={paginator}
                        rows={rows}
                        eventOptions={eventOptions}
                        selectedEvent={selectedEvent}
                        hasFilters={hasFilters}
                        isLoading={isLoading}
                        applyFilter={applyFilter}
                        navigateToPage={navigateToPage}
                    />

                    <section>
                        <div className="mb-3 flex items-baseline gap-3">
                            <h2 className="text-base font-semibold text-ink-strong">
                                Security Logs
                            </h2>
                            <p className="text-xs text-ink-muted">
                                Failed login thresholds and authentication
                                activity
                            </p>
                        </div>

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <LoginAttemptsSection
                                paginator={attemptsPaginator}
                                rows={attempts}
                                available={loginAttemptsAvailable}
                                isLoading={isLoading}
                                navigateToPage={navigateToPage}
                            />
                            <SuspiciousActivitySection
                                paginator={alertsPaginator}
                                rows={alertRows}
                                available={alertsAvailable}
                                isLoading={isLoading}
                                navigateToPage={navigateToPage}
                            />
                        </div>
                    </section>
                </div>
            </AdminLayout>
        </>
    );
}

type PaginationControlsProps = {
    paginator: Paginator<unknown>;
    navigateToPage: (url: string | null) => void;
};

function PaginationControls({
    paginator,
    navigateToPage,
}: PaginationControlsProps) {
    if (paginator.last_page <= 1) return null;

    return (
        <div className="flex flex-col gap-2 border-t border-line-soft px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
            <span className="text-xs text-ink-muted">
                Page {paginator.current_page} of {paginator.last_page}
            </span>

            <div className="flex flex-wrap items-center gap-1">
                {paginator.links.map((link, index) => (
                    <button
                        key={index}
                        type="button"
                        onClick={() => navigateToPage(link.url)}
                        disabled={!link.url}
                        className={`rounded-lg px-3 py-1.5 text-xs ${
                            link.active
                                ? 'bg-brand text-white'
                                : link.url
                                  ? 'border border-line text-ink-muted hover:bg-canvas'
                                  : 'cursor-not-allowed text-ink-faint'
                        }`}
                        dangerouslySetInnerHTML={{
                            __html: link.label,
                        }}
                    />
                ))}
            </div>
        </div>
    );
}

type ActivityLogSectionProps = {
    paginator: AuditLogPaginator;
    rows: AuditLogEntry[];
    eventOptions: string[];
    selectedEvent: string;
    hasFilters: boolean;
    isLoading: boolean;
    applyFilter: (event: string) => void;
    navigateToPage: (url: string | null) => void;
};

function ActivityLogSection({
    paginator,
    rows,
    eventOptions,
    selectedEvent,
    hasFilters,
    isLoading,
    applyFilter,
    navigateToPage,
}: ActivityLogSectionProps) {
    return (
        <section>
            <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <h2 className="text-base font-semibold text-ink-strong">
                        Audit Logs
                    </h2>
                    <p className="text-xs text-ink-muted" aria-live="polite">
                        {paginator.total === 0
                            ? hasFilters
                                ? 'No records match the current filter.'
                                : 'No audit records yet.'
                            : `Showing ${paginator.from ?? 0}–${paginator.to ?? 0} of ${paginator.total} ${
                                  hasFilters ? 'matching records' : 'records'
                              }`}
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <label
                        htmlFor="event-filter"
                        className="text-sm text-ink-muted"
                    >
                        Event
                    </label>
                    <select
                        id="event-filter"
                        value={selectedEvent}
                        disabled={isLoading}
                        onChange={(e) => applyFilter(e.target.value)}
                        className="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink focus:border-gray-500 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <option value="">All events</option>
                        {eventOptions.map((event) => (
                            <option key={event} value={event}>
                                {formatEventLabel(event)}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            <div
                className="overflow-hidden rounded-lg border border-line-soft bg-white"
                aria-busy={isLoading}
            >
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[700px]">
                        <thead className="bg-canvas">
                            <tr className="border-b border-[#c2cbd4]">
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    DATE/TIME
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    SECTOR
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    STAFF NAME
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    ACTION
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-10 text-center text-sm text-ink-muted"
                                    >
                                        {hasFilters
                                            ? 'No audit records match the selected event filter.'
                                            : 'No audit records found yet. Activity will appear here as staff actions are recorded.'}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((log) => (
                                    <tr
                                        key={log.id}
                                        className="border-b border-line-soft last:border-0"
                                    >
                                        <td className="px-5 py-3 text-sm whitespace-nowrap text-ink-muted">
                                            {log.created_at ?? '—'}
                                        </td>

                                        <td className="px-5 py-3 text-sm text-ink-muted">
                                            {sectorForEvent(log.event)}
                                        </td>

                                        <td className="px-5 py-3 text-sm text-ink">
                                            {log.actor ? (
                                                <div>
                                                    <span className="font-medium">
                                                        {log.actor.name}
                                                    </span>
                                                    {log.actor.position && (
                                                        <p className="mt-1 text-xs text-ink-muted">
                                                            {log.actor.position}
                                                            {log.actor
                                                                .department_label
                                                                ? ` · ${log.actor.department_label}`
                                                                : ''}
                                                        </p>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-ink-faint">
                                                    System
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-5 py-3">
                                            <span className="rounded-full bg-[#e2f0fc] px-3 py-1 text-xs whitespace-nowrap text-[#21679d]">
                                                {formatEventLabel(log.event)}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationControls
                    paginator={paginator}
                    navigateToPage={navigateToPage}
                />
            </div>
        </section>
    );
}

type LoginAttemptsSectionProps = {
    paginator: Paginator<LoginAttemptEntry>;
    rows: LoginAttemptEntry[];
    available: boolean;
    isLoading: boolean;
    navigateToPage: (url: string | null) => void;
};

function LoginAttemptsSection({
    paginator,
    rows,
    available,
    isLoading,
    navigateToPage,
}: LoginAttemptsSectionProps) {
    return (
        <section className="flex h-full flex-col">
            <div className="mb-3 flex items-center gap-3">
                <h3 className="text-sm font-semibold text-ink-strong">
                    Login Attempts
                </h3>
                <p className="text-xs text-ink-muted" aria-live="polite">
                    {!available
                        ? 'Login attempt data is temporarily unavailable.'
                        : paginator.total === 0
                          ? 'No login attempts recorded yet.'
                          : `Showing ${paginator.from ?? 0}–${paginator.to ?? 0} of ${paginator.total} attempts`}
                </p>
            </div>

            <div
                className="flex flex-1 flex-col overflow-hidden rounded-lg border border-line-soft bg-white"
                aria-busy={isLoading}
            >
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[420px]">
                        <thead className="bg-canvas">
                            <tr className="border-b border-[#c2cbd4]">
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    ACTOR
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    CHANNEL
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    TIME
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    STATUS
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {!available ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-10 text-center text-sm text-ink-muted"
                                    >
                                        Login attempt data is temporarily
                                        unavailable. Please try again later.
                                    </td>
                                </tr>
                            ) : rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-10 text-center text-sm text-ink-muted"
                                    >
                                        No login attempts found. Attempts will
                                        appear here as login activity is
                                        recorded.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((attempt) => (
                                    <tr
                                        key={attempt.id}
                                        className="border-b border-line-soft last:border-0"
                                    >
                                        <td className="px-5 py-3 text-sm text-ink">
                                            {attempt.actor_label ||
                                                formatLabel(attempt.actor_type)}
                                        </td>

                                        <td className="px-5 py-3 text-sm text-ink-muted">
                                            {formatLabel(attempt.channel)}
                                        </td>

                                        <td className="px-5 py-3 text-sm whitespace-nowrap text-ink-muted">
                                            {attempt.attempted_at ?? '—'}
                                        </td>

                                        <td className="px-5 py-3">
                                            <StatusBadge
                                                status={attempt.status}
                                            />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationControls
                    paginator={paginator}
                    navigateToPage={navigateToPage}
                />
            </div>
        </section>
    );
}

function StatusBadge({ status }: { status: string }) {
    const styles: Record<string, string> = {
        success: 'bg-[#e2f0fc] text-[#21679d]',
        failed: 'bg-red-50 text-red-700',
        rate_limited: 'bg-amber-50 text-amber-700',
    };

    return (
        <span
            className={`rounded-full px-3 py-1 text-xs whitespace-nowrap ${
                styles[status] ?? 'bg-secondary text-ink-muted'
            }`}
        >
            {formatLabel(status)}
        </span>
    );
}

type SuspiciousActivitySectionProps = {
    paginator: Paginator<SuspiciousAlertEntry>;
    rows: SuspiciousAlertEntry[];
    available: boolean;
    isLoading: boolean;
    navigateToPage: (url: string | null) => void;
};

function SuspiciousActivitySection({
    paginator,
    rows,
    available,
    isLoading,
    navigateToPage,
}: SuspiciousActivitySectionProps) {
    return (
        <section className="flex h-full flex-col">
            <div className="mb-3 flex items-center gap-3">
                <h3 className="text-sm font-semibold text-ink-strong">
                    Suspicious Activity
                </h3>
                <p className="text-xs text-ink-muted" aria-live="polite">
                    {!available
                        ? 'Suspicious activity data is temporarily unavailable.'
                        : paginator.total === 0
                          ? 'No suspicious activity detected.'
                          : `Showing ${paginator.from ?? 0}–${paginator.to ?? 0} of ${paginator.total} alerts`}
                </p>
            </div>

            <div
                className="flex flex-1 flex-col overflow-hidden rounded-lg border border-line-soft bg-white"
                aria-busy={isLoading}
            >
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[520px]">
                        <thead className="bg-canvas">
                            <tr className="border-b border-[#c2cbd4]">
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    DETECTED
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    ALERT
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    ATTEMPTS
                                </th>
                                <th
                                    scope="col"
                                    className="px-5 py-3 text-left text-xs font-semibold tracking-wide text-[#6e747c]"
                                >
                                    WINDOW
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {!available ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-10 text-center text-sm text-ink-muted"
                                    >
                                        Suspicious activity data is temporarily
                                        unavailable. Please try again later.
                                    </td>
                                </tr>
                            ) : rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-5 py-10 text-center text-sm text-ink-muted"
                                    >
                                        No suspicious activity found. Repeated
                                        failed logins will appear here.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((alert) => (
                                    <tr
                                        key={alert.id}
                                        className="border-b border-line-soft last:border-0"
                                    >
                                        <td className="px-5 py-3 text-sm whitespace-nowrap text-ink-muted">
                                            {alert.created_at ?? '—'}
                                        </td>

                                        <td className="px-5 py-3">
                                            <span className="rounded-full bg-red-50 px-3 py-1 text-xs whitespace-nowrap text-red-700">
                                                {formatLabel(alert.alert_type)}
                                            </span>
                                        </td>

                                        <td className="px-5 py-3 text-sm text-ink-muted">
                                            {alert.attempt_count}
                                        </td>

                                        <td className="px-5 py-3 text-sm text-ink-muted">
                                            {formatDuration(
                                                alert.window_seconds,
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationControls
                    paginator={paginator}
                    navigateToPage={navigateToPage}
                />
            </div>
        </section>
    );
}
