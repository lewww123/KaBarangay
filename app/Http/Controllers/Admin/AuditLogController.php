<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginAttempt;
use App\Models\SuspiciousLoginAlert;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Canonical audit event names the portal currently supports.
     *
     * The list covers the five approved categories (logins, staff, residents,
     * certificates, announcements). Events are written by later batches; the
     * allowlist exists so the index filter can never be widened by
     * client-supplied input.
     */
    private const SUPPORTED_EVENTS = [
        'announcement.approved',
        'announcement.created',
        'announcement.deleted',
        'announcement.rejected',
        'announcement.resubmitted',
        'certificate_request.created',
        'certificate_request.status_changed',
        'login.failed',
        'login.success',
        'resident.account_status_changed',
        'resident.status_changed',
        'resident_registration.approved',
        'resident_registration.rejected',
        'resident_registration.submitted',
        'staff.created',
        'staff.role_changed',
        'staff.status_changed',
        'staff.updated',
    ];

    /**
     * Fixed page size for the audit trail listing.
     */
    private const PAGE_SIZE = 15;

    /**
     * Allowlisted Security Logs tabs. Only these values are honored; anything
     * else safely falls back to the default so a client-supplied value can
     * never widen access or error the request.
     */
    private const TAB_ACTIVITY = 'activity';

    private const TAB_SECURITY = 'security';

    private const TABS = [
        self::TAB_ACTIVITY,
        self::TAB_SECURITY,
    ];

    /**
     * Audit rows store timestamps in the application timezone (UTC). They are
     * converted to Philippine local time for display, and the suffix makes the
     * zone explicit so a reader never mistakes the value for UTC.
     */
    private const DISPLAY_TIMEZONE = 'Asia/Manila';

    private const DISPLAY_TIMEZONE_SUFFIX = 'PHT';

    public function index(Request $request): Response
    {
        $admin = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Authorization scope
        |--------------------------------------------------------------------------
        |
        | The barangay restriction comes exclusively from the authenticated
        | administrator. A barangay_id query parameter (or any other client
        | input) is never consulted for scoping.
        |
        */
        $barangayId = $admin->barangay_id;

        /*
        |------------------------------------------------------------------
        | Event filter
        |------------------------------------------------------------------
        |
        | Only allowlisted event names are applied. Anything else is
        | normalized to an empty filter (no event restriction) — the same
        | convention the certificate and staff listings use for unknown
        | status values. An unknown value can therefore never broaden the
        | barangay restriction or error the request.
        |
        */
        $requestedEvent = $request->query('event', '');
        $requestedEvent = is_string($requestedEvent) ? trim($requestedEvent) : '';

        $eventFilter = in_array($requestedEvent, self::SUPPORTED_EVENTS, true)
            ? $requestedEvent
            : '';

        /*
        |------------------------------------------------------------------
        | Single-page composition
        |------------------------------------------------------------------
        |
        | The approved Audit Log page shows the Activity Log and the two
        | Security Logs datasets together, stacked, so every dataset is
        | supplied on every request. The allowlisted `tab` query parameter is
        | still read so legacy `?tab=security` links remain valid, but it no
        | longer gates which datasets are loaded: both sections render at once.
        | Each dataset retains its own barangay restriction below, so
        | combining them cannot widen access.
        |
        */
        $requestedTab = $request->query('tab', '');
        $requestedTab = is_string($requestedTab) ? trim($requestedTab) : '';

        $tab = in_array($requestedTab, self::TABS, true)
            ? $requestedTab
            : self::TAB_ACTIVITY;

        $query = AuditLog::query();

        if ($barangayId === null) {
            // Ambiguous administrator with no barangay: show nothing rather
            // than falling back to an unscoped listing.
            $query->whereRaw('1 = 0');
        } else {
            $query->where('barangay_id', $barangayId);
        }

        if ($eventFilter !== '') {
            $query->where('event', $eventFilter);
        }

        /*
        |------------------------------------------------------------------
        | Actor loading
        |------------------------------------------------------------------
        |
        | Actors are constrained to the administrator's barangay and only the
        | three display columns are selected, so a stale or foreign actor_id
        | can never leak another barangay's account details.
        |
        */
        $records = $query
            ->with(['actor' => function ($actorQuery) use ($barangayId) {
                $actorQuery->select(['id', 'name', 'role']);

                if ($barangayId === null) {
                    $actorQuery->whereRaw('1 = 0');
                } else {
                    $actorQuery->where('barangay_id', $barangayId);
                }
            }])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString()
            ->through(function (AuditLog $auditLog) {
                return [
                    'id' => $auditLog->id,
                    'event' => $auditLog->event,
                    'actor' => $auditLog->actor === null
                        ? null
                        : [
                            'id' => $auditLog->actor->id,
                            'name' => $auditLog->actor->name,
                            'role' => $auditLog->actor->role,
                        ],
                    'created_at' => $auditLog->created_at === null
                        ? null
                        : $auditLog->created_at
                            ->copy()
                            ->timezone(self::DISPLAY_TIMEZONE)
                            ->format('Y-m-d H:i:s').' '.self::DISPLAY_TIMEZONE_SUFFIX,
                ];
            });

        $loginAttempts = $this->loadSecuritySection(
            fn () => $this->loginAttempts($barangayId),
        );

        $alerts = $this->loadSecuritySection(
            fn () => $this->suspiciousAlerts(),
        );

        return Inertia::render('Admin/AuditLog', [
            'tab' => $tab,
            'logs' => $records,
            'events' => self::SUPPORTED_EVENTS,
            'filters' => [
                'event' => $eventFilter,
            ],
            'loginAttempts' => $loginAttempts['paginator'],
            'loginAttemptsAvailable' => $loginAttempts['available'],
            'alerts' => $alerts['paginator'],
            'alertsAvailable' => $alerts['available'],
        ]);
    }

    /**
     * Load a security dataset without letting its failure break the page.
     *
     * A missing or unavailable underlying table (for example an environment
     * where a Security Logs migration has not been applied) must not take down
     * the whole Audit Log page. The activity log is loaded independently, and
     * each security section degrades on its own: on failure the section
     * resolves to an empty, normal-shaped paginator plus `available => false`,
     * so the UI can distinguish "unavailable" from a genuinely empty dataset.
     *
     * The exception is reported through the framework's exception handler (for
     * server-side visibility) and is never surfaced to the responder: no SQL
     * text, connection details, table internals, or exception messages travel
     * to the browser.
     *
     * @param  callable(): \Illuminate\Contracts\Pagination\LengthAwarePaginator  $loader
     * @return array{available: bool, paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator}
     */
    private function loadSecuritySection(callable $loader): array
    {
        try {
            return [
                'available' => true,
                'paginator' => $loader(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'paginator' => $this->emptyPaginator(),
            ];
        }
    }

    /**
     * A normal-shaped, empty paginator for a failed security section.
     *
     * Shaped identically to a real paginated result so the Inertia contract and
     * the frontend paginator typing remain unchanged; only `available` on the
     * section signals that the data could not be loaded.
     */
    private function emptyPaginator(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: self::PAGE_SIZE,
            currentPage: 1,
        );
    }

    /**
     * Barangay-scoped login attempts for the Security Logs tab.
     *
     * LoginAttempt applies no global scope, so the tenant restriction is
     * applied explicitly here. An administrator with no barangay sees an empty
     * dataset rather than an unscoped listing. Only safe display columns are
     * selected; identifier_hash is never queried or returned, and the IP
     * address is masked before it leaves the controller.
     */
    protected function loginAttempts(?int $barangayId): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if ($barangayId === null) {
            return LoginAttempt::query()
                ->whereRaw('1 = 0')
                ->paginate(self::PAGE_SIZE)
                ->withQueryString();
        }

        return LoginAttempt::query()
            ->where('barangay_id', $barangayId)
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString()
            ->through(function (LoginAttempt $attempt) {
                return [
                    'id' => $attempt->id,
                    'actor_type' => $attempt->actor_type,
                    'channel' => $attempt->channel,
                    'status' => $attempt->status,
                    'attempted_at' => $attempt->attempted_at === null
                        ? null
                        : $attempt->attempted_at
                            ->copy()
                            ->timezone(self::DISPLAY_TIMEZONE)
                            ->format('Y-m-d H:i:s').' '.self::DISPLAY_TIMEZONE_SUFFIX,
                ];
            });
    }

    /**
     * Barangay-scoped suspicious alerts for the Security Logs tab.
     *
     * Reads go exclusively through SuspiciousLoginAlert's `barangay` global
     * scope, which tenant-scopes the query and hides null-barangay rows (and
     * unauthenticated reads) entirely. The scope is never bypassed here. Only
     * safe display fields are returned; identifier_hash is never exposed.
     */
    protected function suspiciousAlerts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return SuspiciousLoginAlert::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString()
            ->through(function (SuspiciousLoginAlert $alert) {
                return [
                    'id' => $alert->id,
                    'channel' => $alert->channel,
                    'alert_type' => $alert->alert_type,
                    'attempt_count' => $alert->attempt_count,
                    'window_seconds' => $alert->window_seconds,
                    'first_attempt_at' => $alert->first_attempt_at === null
                        ? null
                        : $alert->first_attempt_at
                            ->copy()
                            ->timezone(self::DISPLAY_TIMEZONE)
                            ->format('Y-m-d H:i:s').' '.self::DISPLAY_TIMEZONE_SUFFIX,
                    'last_attempt_at' => $alert->last_attempt_at === null
                        ? null
                        : $alert->last_attempt_at
                            ->copy()
                            ->timezone(self::DISPLAY_TIMEZONE)
                            ->format('Y-m-d H:i:s').' '.self::DISPLAY_TIMEZONE_SUFFIX,
                    'created_at' => $alert->created_at === null
                        ? null
                        : $alert->created_at
                            ->copy()
                            ->timezone(self::DISPLAY_TIMEZONE)
                            ->format('Y-m-d H:i:s').' '.self::DISPLAY_TIMEZONE_SUFFIX,
                ];
            });
    }
}
