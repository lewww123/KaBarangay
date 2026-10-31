<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Models\SharedEmergencyContact;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyContactController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $barangayId = (int) $user->barangay_id;

        $loadHotlines = function () use ($barangayId): array {
            $barangayHotlines = [];

            try {
                if (Schema::hasTable('emergency_contacts')) {
                    $barangayHotlines = EmergencyContact::query()
                        ->where('barangay_id', $barangayId)
                        ->where('category', EmergencyContact::CATEGORY_BARANGAY_HOTLINE)
                        ->orderBy('display_order')
                        ->orderBy('id')
                        ->get()
                        ->map(fn (EmergencyContact $contact) => $this->transformHotline($contact))
                        ->values()
                        ->all();
                }
            } catch (\Throwable) {
                $barangayHotlines = [];
            }

            return $barangayHotlines;
        };

        $loadSharedContacts = function (): array {
            $sharedEmergencyContacts = [];

            try {
                if (Schema::hasTable('shared_emergency_contacts')) {
                    $sharedEmergencyContacts = SharedEmergencyContact::query()
                        ->active()
                        ->verified()
                        ->orderBy('display_order')
                        ->orderBy('id')
                        ->get()
                        ->map(fn (SharedEmergencyContact $contact) => $this->transformShared($contact))
                        ->values()
                        ->all();
                }
            } catch (\Throwable) {
                $sharedEmergencyContacts = [];
            }

            return $sharedEmergencyContacts;
        };

        return Inertia::render('Admin/EmergencyNumbers/Index', [
            'barangayHotlines' => $this->deferProp($loadHotlines),
            'sharedEmergencyContacts' => $this->deferProp($loadSharedContacts),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate($this->hotlineRules());

        $this->assertUniqueHotlinePhone(
            (int) $user->barangay_id,
            $validated['phone_number'],
        );

        $payload = $validated;
        unset($payload['category']);

        $payload['barangay_id'] = $user->barangay_id;
        $payload['category'] = EmergencyContact::CATEGORY_BARANGAY_HOTLINE;
        $payload['sub_category'] = $validated['sub_category'] ?? null;
        $payload['label'] = $validated['label'] ?? null;
        $payload['created_by'] = $user->id;
        $payload['can_call'] = $request->has('can_call') ? $request->boolean('can_call') : true;
        $payload['can_sms'] = $request->has('can_sms') ? $request->boolean('can_sms') : false;
        $payload['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $payload['display_order'] = (int) ($validated['display_order'] ?? 0);

        $contact = EmergencyContact::create($payload);

        AuditLogger::record(
            'emergency_contact_created',
            barangayId: $contact->barangay_id,
            actorId: $user->id,
            entityType: 'emergency_contact',
            entityId: $contact->id,
            context: [
                'action' => 'created',
                'entity_reference' => $contact->name,
                'new_status' => $contact->is_active ? 'active' : 'inactive',
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Barangay hotline added successfully.');
    }

    public function update(Request $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeBarangay($user, $emergencyContact);
        $this->authorizeHotline($emergencyContact);

        $validated = $request->validate($this->hotlineRules());

        $this->assertUniqueHotlinePhone(
            (int) $user->barangay_id,
            $validated['phone_number'],
            $emergencyContact->id,
        );

        $payload = $validated;
        unset($payload['category']);

        $payload['category'] = EmergencyContact::CATEGORY_BARANGAY_HOTLINE;
        $payload['updated_by'] = $user->id;

        if ($request->has('can_call')) {
            $payload['can_call'] = $request->boolean('can_call');
        }

        if ($request->has('can_sms')) {
            $payload['can_sms'] = $request->boolean('can_sms');
        }

        if ($request->has('is_active')) {
            $payload['is_active'] = $request->boolean('is_active');
        }

        if ($request->has('display_order')) {
            $payload['display_order'] = (int) ($validated['display_order'] ?? 0);
        }

        $previousStatus = $emergencyContact->is_active ? 'active' : 'inactive';
        $emergencyContact->update($payload);

        AuditLogger::record(
            'emergency_contact_updated',
            barangayId: $emergencyContact->barangay_id,
            actorId: $user->id,
            entityType: 'emergency_contact',
            entityId: $emergencyContact->id,
            context: [
                'action' => 'updated',
                'entity_reference' => $emergencyContact->name,
                'previous_status' => $previousStatus,
                'new_status' => $emergencyContact->is_active ? 'active' : 'inactive',
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Barangay hotline updated successfully.');
    }

    public function destroy(Request $request, EmergencyContact $emergencyContact): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeBarangay($user, $emergencyContact);
        $this->authorizeHotline($emergencyContact);

        $contactId = $emergencyContact->id;
        $barangayId = $emergencyContact->barangay_id;
        $name = $emergencyContact->name;

        $emergencyContact->delete();

        AuditLogger::record(
            'emergency_contact_deleted',
            barangayId: $barangayId,
            actorId: $user->id,
            entityType: 'emergency_contact',
            entityId: $contactId,
            context: [
                'action' => 'deleted',
                'entity_reference' => $name,
            ],
            ipAddress: $request->ip(),
        );

        return back()->with('success', 'Barangay hotline deleted successfully.');
    }

    /**
     * @return array<string, list<string|Closure|In>>
     */
    private function hotlineRules(): array
    {
        return [
            'category' => ['sometimes', 'string', Rule::in([EmergencyContact::CATEGORY_BARANGAY_HOTLINE])],
            'sub_category' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'phone_number' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                        $fail('The phone number must normalize to a valid 3–15 digit number with an optional leading +.');
                    }
                },
            ],
            'can_call' => ['sometimes', 'boolean'],
            'can_sms' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function assertUniqueHotlinePhone(int $barangayId, string $phone, ?int $ignoreId = null): void
    {
        $normalized = PhoneNumber::normalize($phone);

        $duplicate = EmergencyContact::query()
            ->where('barangay_id', $barangayId)
            ->where('category', EmergencyContact::CATEGORY_BARANGAY_HOTLINE)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get(['id', 'phone_number'])
            ->contains(fn (EmergencyContact $contact) => PhoneNumber::normalize($contact->phone_number) === $normalized);

        if ($duplicate) {
            throw ValidationException::withMessages([
                'phone_number' => 'This phone number is already listed for your barangay.',
            ]);
        }
    }

    private function authorizeBarangay(User $user, EmergencyContact $contact): void
    {
        abort_unless(
            (int) $contact->barangay_id === (int) $user->barangay_id,
            403,
            'Unauthorized barangay access.'
        );
    }

    private function authorizeHotline(EmergencyContact $contact): void
    {
        abort_unless(
            $contact->category === EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
            403,
            'Shared emergency directory records are managed centrally.'
        );
    }

    /**
     * @return array{
     *     id: int,
     *     category: string,
     *     sub_category: string|null,
     *     name: string,
     *     label: string|null,
     *     phone_number: string,
     *     can_call: bool,
     *     can_sms: bool,
     *     display_order: int,
     *     is_active: bool,
     *     tel_uri: string,
     *     sms_uri: string
     * }
     */
    private function transformHotline(EmergencyContact $contact): array
    {
        $normalizedPhone = PhoneNumber::normalize($contact->phone_number);

        return [
            'id' => $contact->id,
            'category' => $contact->category,
            'sub_category' => $contact->sub_category,
            'name' => $contact->name,
            'label' => $contact->label,
            'phone_number' => $contact->phone_number,
            'can_call' => (bool) $contact->can_call,
            'can_sms' => (bool) $contact->can_sms,
            'display_order' => (int) $contact->display_order,
            'is_active' => (bool) $contact->is_active,
            'tel_uri' => 'tel:'.$normalizedPhone,
            'sms_uri' => 'sms:'.$normalizedPhone,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     category: string,
     *     service_type: string,
     *     sub_category: string,
     *     name: string,
     *     label: string|null,
     *     phone_number: string,
     *     coverage_area: string|null,
     *     can_call: bool,
     *     can_sms: bool,
     *     display_order: int,
     *     is_active: bool,
     *     tel_uri: string,
     *     sms_uri: string
     * }
     */
    private function transformShared(SharedEmergencyContact $contact): array
    {
        return [
            'id' => $contact->id,
            'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
            'service_type' => $contact->service_type,
            'sub_category' => $contact->service_type,
            'name' => $contact->name,
            'label' => $contact->label,
            'phone_number' => $contact->phone_number,
            'coverage_area' => $contact->coverage_area,
            'can_call' => (bool) $contact->can_call,
            'can_sms' => (bool) $contact->can_sms,
            'display_order' => (int) $contact->display_order,
            'is_active' => (bool) $contact->is_active,
            'tel_uri' => $contact->telUri(),
            'sms_uri' => $contact->smsUri(),
        ];
    }
}
