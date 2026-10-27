<?php

namespace App\Console\Commands;

use App\Models\SharedEmergencyContact;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use Throwable;

class ImportSharedEmergencyDirectory extends Command
{
    protected $signature = 'emergency-directory:import
        {file : Path to the reviewed JSON directory file}
        {--apply : Persist validated contacts; omit for a dry run}
        {--verified-by= : Optional active staff user ID or email recorded as verifier}
        {--force : Allow --apply in the production environment}';

    protected $description = 'Validate and import the reviewed shared emergency directory without overwriting existing records';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The directory file does not exist or is not readable.');

            return self::FAILURE;
        }

        if ($this->option('apply') && app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to write in production without --force.');

            return self::FAILURE;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error('The directory file could not be read.');

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The directory file is not valid JSON.');

            return self::FAILURE;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $this->error('The directory file must contain a JSON array of contacts.');

            return self::FAILURE;
        }

        $verifier = null;

        if ($verifiedBy = $this->option('verified-by')) {
            $verifier = User::query()
                ->where(function ($query) use ($verifiedBy) {
                    $query->where('id', $verifiedBy)
                        ->orWhere('email', $verifiedBy);
                })
                ->where('status', 'active')
                ->first();

            if (! $verifier) {
                $this->error('The verified-by user was not found or is inactive.');

                return self::FAILURE;
            }
        }

        $rows = [];
        $errors = [];
        $sourceKeys = [];

        foreach ($decoded as $index => $contact) {
            $rowNumber = $index + 1;

            if (! is_array($contact)) {
                $errors[] = "Row {$rowNumber}: must be an object.";

                continue;
            }

            $validator = Validator::make($contact, [
                'source_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
                'service_type' => ['required', 'string', Rule::in(SharedEmergencyContact::SERVICE_TYPES)],
                'name' => ['required', 'string', 'max:255'],
                'label' => ['nullable', 'string', 'max:255'],
                'phone_number' => [
                    'required',
                    'string',
                    'max:50',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                            $fail('The phone number must normalize to a valid 3–15 digit number with an optional leading +.');
                        }
                    },
                ],
                'coverage_area' => ['required', 'string', 'max:255'],
                'can_call' => ['required', 'boolean'],
                'can_sms' => ['required', 'boolean'],
                'display_order' => ['required', 'integer', 'min:0'],
                'is_active' => ['required', 'boolean'],
                'verified_at' => ['required', 'date'],
                'verification_source' => ['required', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Row {$rowNumber}: {$message}";
                }

                continue;
            }

            $sourceKey = $contact['source_key'];

            if (isset($sourceKeys[$sourceKey])) {
                $errors[] = "Row {$rowNumber}: duplicate source_key.";

                continue;
            }

            $sourceKeys[$sourceKey] = true;
            $rows[] = $contact;
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info(count($rows).' row(s) validated. Dry run: no records written.');

            return self::SUCCESS;
        }

        $inserted = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($rows, $verifier, &$inserted, &$skipped) {
                foreach ($rows as $row) {
                    $exists = SharedEmergencyContact::withTrashed()
                        ->where('source_key', $row['source_key'])
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    $contact = SharedEmergencyContact::create([
                        'source_key' => $row['source_key'],
                        'service_type' => $row['service_type'],
                        'name' => $row['name'],
                        'label' => $row['label'] ?? null,
                        'phone_number' => $row['phone_number'],
                        'normalized_phone' => PhoneNumber::normalize($row['phone_number']),
                        'coverage_area' => $row['coverage_area'],
                        'can_call' => (bool) $row['can_call'],
                        'can_sms' => (bool) $row['can_sms'],
                        'display_order' => (int) $row['display_order'],
                        'is_active' => (bool) $row['is_active'],
                        'verified_at' => Carbon::parse($row['verified_at']),
                        'verified_by' => $verifier?->id,
                        'verification_source' => $row['verification_source'],
                        'created_by' => $verifier?->id,
                    ]);

                    AuditLogger::record(
                        'shared_emergency_contact_imported',
                        actorId: $verifier?->id,
                        entityType: 'shared_emergency_contact',
                        entityId: $contact->id,
                        context: [
                            'action' => 'imported',
                            'entity_reference' => $contact->name,
                            'new_status' => $contact->is_active ? 'active' : 'inactive',
                        ],
                    );

                    $inserted++;
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The import failed and no records were written.');

            return self::FAILURE;
        }

        $this->info("Imported {$inserted} record(s); skipped {$skipped} existing or retired record(s).");

        return self::SUCCESS;
    }
}
