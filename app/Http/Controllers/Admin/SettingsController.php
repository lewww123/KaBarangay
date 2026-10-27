<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Resident CSV column allowlist.
     *
     * Only non-sensitive, display-oriented fields are exported. Credentials,
     * password hashes, reset tokens, QR tokens, and internal identifiers are
     * deliberately excluded.
     */
    private const RESIDENT_COLUMNS = [
        'resident_number' => 'Resident Number',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'suffix' => 'Suffix',
        'birthdate' => 'Birthdate',
        'sex' => 'Sex',
        'civil_status' => 'Civil Status',
        'address_line' => 'Address',
        'purok' => 'Purok',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'status' => 'Status',
        'approved_at' => 'Approved At',
    ];

    /**
     * Staff CSV column allowlist.
     *
     * Passwords, password hashes, remember tokens, and reset tokens are never
     * selected or exported.
     */
    private const STAFF_COLUMNS = [
        'name' => 'Name',
        'email' => 'Email',
        'staff_id' => 'Staff ID',
        'department' => 'Department',
        'role' => 'Role',
        'status' => 'Status',
    ];

    /**
     * JSON data-export format identifier and version.
     *
     * format_version stays 1: no field of a previously released format has
     * been removed or repurposed that a v0 consumer could have relied on —
     * this is the first release of the identifier under this name, so a new
     * version line would be misleading.
     */
    private const EXPORT_FORMAT = 'kabarangay.barangay-data-export';

    private const EXPORT_FORMAT_VERSION = 1;

    /**
     * Tables exported without barangay ownership.
     *
     * `sectors` is a global catalog (no barangay_id column) shared by every
     * barangay. It is included only so that `resident_sector.sector_id` /
     * `registration_sectors.sector_id` references remain interpretable.
     */
    private const GLOBAL_TABLES = ['sectors'];

    /**
     * Render the Settings page.
     *
     * The authenticated administrator's barangay name is sourced from their
     * assigned barangay_id so the Barangay Information section can display the
     * real, trusted value without a client-supplied identifier. A missing or
     * deleted barangay yields a null name rather than a fabricated value.
     */
    public function index(Request $request): Response
    {
        $admin = $request->user();

        $barangayName = null;

        if ($admin->barangay_id !== null) {
            $barangayName = Barangay::query()
                ->whereKey($admin->barangay_id)
                ->value('name');
        }

        return Inertia::render('Admin/Settings', [
            'barangay' => [
                'name' => $barangayName,
            ],
        ]);
    }

    /**
     * Export the authenticated administrator's barangay residents as CSV.
     */
    public function exportResidents(Request $request): HttpResponse
    {
        $admin = $request->user();

        $rows = $this->residentQuery($admin->barangay_id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $this->csvDownload(
            'residents-'.now()->format('Y-m-d').'.csv',
            self::RESIDENT_COLUMNS,
            $rows->map(fn (Resident $resident) => [
                'resident_number' => $resident->resident_number,
                'first_name' => $resident->first_name,
                'middle_name' => $resident->middle_name,
                'last_name' => $resident->last_name,
                'suffix' => $resident->suffix,
                'birthdate' => $resident->birthdate?->format('Y-m-d'),
                'sex' => $resident->sex,
                'civil_status' => $resident->civil_status,
                'address_line' => $resident->address_line,
                'purok' => $resident->purok?->name,
                'mobile' => $resident->mobile,
                'email' => $resident->email,
                'status' => $resident->status,
                'approved_at' => $resident->approved_at?->format('Y-m-d H:i:s'),
            ]),
        );
    }

    /**
     * Export the authenticated administrator's barangay staff as CSV.
     */
    public function exportStaff(Request $request): HttpResponse
    {
        $admin = $request->user();

        $rows = $this->staffQuery($admin->barangay_id)
            ->orderBy('name')
            ->get();

        return $this->csvDownload(
            'staff-'.now()->format('Y-m-d').'.csv',
            self::STAFF_COLUMNS,
            $rows->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'staff_id' => $user->staff_id,
                'department' => $user->department,
                'role' => $user->role,
                'status' => $user->status,
            ]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | JSON Data Export
    |--------------------------------------------------------------------------
    */

    /**
     * Download a barangay-scoped JSON data export.
     *
     * Only tables whose barangay ownership can be established from the real
     * schema are included (either a direct barangay_id column, or an indirect
     * scope through a barangay-owned parent row). A null/missing barangay
     * assignment produces an empty export (an empty `tables` object) rather
     * than a whole-system dump.
     *
     * This is a data export, not a restore-verified backup: credentials,
     * authentication material, and uploaded document binaries are excluded.
     */
    public function exportData(Request $request): HttpResponse
    {
        $admin = $request->user();
        $barangayId = $admin->barangay_id;

        $tables = [];

        if ($barangayId !== null) {
            $tables = $this->exportTables($barangayId);
        }

        $payload = [
            'meta' => [
                'format' => self::EXPORT_FORMAT,
                'format_version' => self::EXPORT_FORMAT_VERSION,
                'generated_at' => now()->toIso8601String(),
                'barangay_id' => $barangayId,
                'barangay_code' => $barangayId !== null
                    ? Barangay::query()->whereKey($barangayId)->value('code')
                    : null,
                'scope' => 'barangay',
                'global_tables' => self::GLOBAL_TABLES,
                'value_encoding' => 'raw-database',
                'note' => 'Barangay-scoped data export, not a restore-verified backup. '
                    .'Passwords, authentication material, and uploaded document binaries '
                    .'are excluded, so this file cannot fully restore an application state. '
                    .'Timestamps and values use the raw database representation. User '
                    .'reference ids (created_by, reviewed_by, verified_by, actor_id) may be '
                    .'unresolved because the users table is not exported.',
            ],
            'tables' => (object) $tables,
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        $filename = 'barangay-data-'.now()->format('Y-m-d').'.json';

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Assemble the allowlisted, barangay-scoped table datasets.
     *
     * Uploaded document binaries are not included. `registration_documents`
     * contributes only relationship/status keys (id, resident_registration_id,
     * sector_id, document_type, verification_status, verified_at); the stored
     * file, filename, MIME type, size, disk, path, and hash are excluded, so
     * these records do not constitute a complete file backup.
     *
     * @return array<string, array{columns: array<int, string>, rows: array<int, array<string, mixed>>}>
     */
    private function exportTables(int $barangayId): array
    {
        $tables = [];

        $tables['puroks'] = $this->exportDirect(
            'puroks',
            $barangayId,
            ['id', 'barangay_id', 'name', 'code', 'sort_order', 'is_active', 'created_at', 'updated_at'],
        );

        $tables['residents'] = $this->exportDirect(
            'residents',
            $barangayId,
            [
                'id', 'barangay_id', 'purok_id', 'resident_registration_id', 'public_id',
                'resident_number',
                'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate',
                'sex', 'civil_status', 'address_line', 'mobile', 'email',
                'status', 'approved_at', 'created_at', 'updated_at',
            ],
        );

        $tables['resident_sector'] = $this->exportIndirect(
            'resident_sector',
            ['id', 'resident_id', 'sector_id', 'created_at', 'updated_at'],
            'resident_id',
            ['residents', 'id'],
            $barangayId,
        );

        $tables['sectors'] = $this->exportReference(
            'sectors',
            ['id', 'name', 'slug', 'requires_proof', 'is_active', 'created_at', 'updated_at'],
        );

        $tables['resident_registrations'] = $this->exportDirect(
            'resident_registrations',
            $barangayId,
            [
                'id', 'barangay_id', 'purok_id', 'public_id',
                'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate',
                'sex', 'civil_status', 'address_line', 'mobile', 'email',
                'status', 'submitted_at', 'reviewed_at', 'last_resubmitted_at',
                'created_at', 'updated_at',
            ],
        );

        $tables['registration_sectors'] = $this->exportIndirect(
            'registration_sectors',
            ['id', 'resident_registration_id', 'sector_id', 'verification_status', 'verified_at', 'created_at', 'updated_at'],
            'resident_registration_id',
            ['resident_registrations', 'id'],
            $barangayId,
        );

        $tables['registration_documents'] = $this->exportIndirect(
            'registration_documents',
            ['id', 'resident_registration_id', 'sector_id', 'document_type', 'verification_status', 'verified_at', 'created_at', 'updated_at'],
            'resident_registration_id',
            ['resident_registrations', 'id'],
            $barangayId,
        );

        $tables['certificates'] = $this->exportDirect(
            'certificates',
            $barangayId,
            ['id', 'barangay_id', 'code', 'name', 'description', 'fee', 'requirements', 'requires_business_details', 'is_active', 'created_at', 'updated_at'],
        );

        $tables['certificate_requests'] = $this->exportDirect(
            'certificate_requests',
            $barangayId,
            ['id', 'barangay_id', 'resident_id', 'certificate_id', 'reference', 'purpose', 'fee', 'status', 'business_name', 'business_address', 'additional_details', 'submitted_at', 'reviewed_at', 'processing_at', 'ready_for_claim_at', 'released_at', 'rejected_at', 'created_at', 'updated_at'],
        );

        $tables['announcements'] = $this->exportDirect(
            'announcements',
            $barangayId,
            ['id', 'barangay_id', 'created_by', 'title', 'content', 'category', 'importance', 'target_audience', 'target_sector_id', 'requires_attendance', 'event_start_at', 'location', 'is_published', 'approval_status', 'reviewed_by', 'reviewed_at', 'completed_at', 'completed_by', 'created_at', 'updated_at'],
        );

        $tables['announcement_attendances'] = $this->exportDirect(
            'announcement_attendances',
            $barangayId,
            ['id', 'barangay_id', 'announcement_id', 'resident_id', 'status', 'method', 'attended_at', 'notes', 'verified_by', 'created_at', 'updated_at'],
        );

        $tables['announcement_approval_logs'] = $this->exportDirect(
            'announcement_approval_logs',
            $barangayId,
            ['id', 'barangay_id', 'announcement_id', 'actor_id', 'action', 'previous_status', 'new_status', 'notes', 'created_at', 'updated_at'],
        );

        /*
         * Lupon complaints: `respondent_name` is retained because it is the
         * minimum needed to identify which case each row belongs to. The
         * third-party contact/address (`respondent_contact`,
         * `respondent_address`) and the free-text `narrative` / `lupon_note`
         * are deliberately excluded as unnecessary personal and operational
         * information for a barangay data export.
         */
        $tables['lupon_complaints'] = $this->exportDirect(
            'lupon_complaints',
            $barangayId,
            ['id', 'barangay_id', 'resident_id', 'reference', 'respondent_name', 'respondent_resident_status', 'complaint_type', 'incident_date', 'incident_time', 'incident_location', 'requested_action', 'status', 'submitted_at', 'reviewed_at', 'rejected_at', 'created_at', 'updated_at'],
        );

        return $tables;
    }

    /**
     * Select an allowlist of columns from a table scoped by a direct
     * barangay_id column.
     */
    private function exportDirect(string $table, int $barangayId, array $columns): array
    {
        $rows = DB::table($table)
            ->where('barangay_id', $barangayId)
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Select rows from a table without its own barangay_id by restricting the
     * parent foreign key to ids that belong to the barangay.
     */
    private function exportIndirect(
        string $table,
        array $columns,
        string $foreignKey,
        array $parent,
        int $barangayId,
    ): array {
        [$parentTable, $parentKey] = $parent;

        $parentIds = DB::table($parentTable)
            ->where('barangay_id', $barangayId)
            ->pluck($parentKey);

        $rows = DB::table($table)
            ->whereIn($foreignKey, $parentIds)
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Select global reference data (no barangay ownership). Included only
     * because it is required to interpret barangay-owned relation rows.
     */
    private function exportReference(string $table, array $columns): array
    {
        $rows = DB::table($table)
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row) => (array) $row)
            ->all();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /*
    |--------------------------------------------------------------------------
    | Scoping
    |--------------------------------------------------------------------------
    |
    | Both datasets are restricted to the authenticated administrator's own
    | barangay. A null barangay assignment matches nothing rather than falling
    | back to an unscoped listing. The barangay is taken exclusively from the
    | authenticated account; client input is never consulted.
    |
    */

    private function residentQuery(?int $barangayId)
    {
        $query = Resident::query()
            ->with(['purok' => fn ($purokQuery) => $purokQuery->select(['id', 'name'])]);

        if ($barangayId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('barangay_id', $barangayId);
    }

    private function staffQuery(?int $barangayId)
    {
        $query = User::query()->where('role', '!=', 'super_admin');

        if ($barangayId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('barangay_id', $barangayId);
    }

    /*
    |--------------------------------------------------------------------------
    | CSV
    |--------------------------------------------------------------------------
    */

    /**
     * Build a CSV download response from a column map and rows.
     *
     * Every value is escaped for CSV and neutralised against spreadsheet
     * formula injection (values beginning with = + - @ or control characters
     * are prefixed with a single quote) before being written.
     */
    private function csvDownload(string $filename, array $columns, $rows): HttpResponse
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, array_values($columns));

        foreach ($rows as $row) {
            $line = [];

            foreach (array_keys($columns) as $key) {
                $line[] = $this->csvValue($row[$key] ?? null);
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Normalise a single cell value for CSV output.
     *
     * Spreadsheet applications treat a leading =, +, -, @, tab, or carriage
     * return as a formula. Prefixing those with an apostrophe keeps the value
     * literal and prevents formula injection from exported data.
     */
    private function csvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;

        if ($string !== '' && preg_match('/^[=+\-@\t\r]/', $string) === 1) {
            return "'".$string;
        }

        return $string;
    }
}
