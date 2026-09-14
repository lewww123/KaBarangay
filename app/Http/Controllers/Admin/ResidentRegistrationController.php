<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationDocument;
use App\Models\ResidentRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidentRegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $pendingRegistrations = ResidentRegistration::query()
            ->with([
                'barangay:id,name,code',
                'purok:id,name',
                'sectors.sector:id,name,slug',
                'documents:id,resident_registration_id,sector_id,document_type,mime_type,file_size,verification_status',
            ])
            ->where('barangay_id', $user->barangay_id)
            ->whereIn('status', [
                'pending',
                'needs_correction',
            ])
            ->latest('submitted_at')
            ->get()
            ->map(function (ResidentRegistration $registration) {
                return [
                    'id' => $registration->id,
                    'reference' => $registration->public_id,

                    'first_name' => $registration->first_name,
                    'middle_name' => $registration->middle_name,
                    'last_name' => $registration->last_name,
                    'suffix' => $registration->suffix,

                    'birthdate' => $registration->birthdate
                        ?->format('Y-m-d'),

                    'sex' => $registration->sex,
                    'civil_status' => $registration->civil_status,
                    'address_line' => $registration->address_line,

                    'mobile' => $registration->mobile,
                    'email' => $registration->email,

                    'barangay' => $registration->barangay?->name,
                    'purok' => $registration->purok?->name,

                    'sectors' => $registration->sectors
                        ->map(function ($registrationSector) {
                            return [
                                'id' => $registrationSector->sector?->id,
                                'name' => $registrationSector->sector?->name,
                                'slug' => $registrationSector->sector?->slug,
                            ];
                        })
                        ->filter(fn ($sector) => $sector['id'])
                        ->values(),

                    'documents' => $registration->documents
                        ->map(function (RegistrationDocument $document) use ($registration) {
                            return [
                                'id' => $document->id,
                                'type' => $document->document_type,
                                'label' => $this->documentLabel(
                                    $document->document_type
                                ),
                                'mime_type' => $document->mime_type,
                                'file_size' => $document->file_size,
                                'verification_status' =>
                                    $document->verification_status,
                                'url' => route(
                                    'admin.residents.documents.show',
                                    [
                                        'registration' => $registration->id,
                                        'document' => $document->id,
                                    ]
                                ),
                                'is_image' => str_starts_with(
                                    (string) $document->mime_type,
                                    'image/'
                                ),
                            ];
                        })
                        ->values(),

                    'status' => $registration->status,

                    'submitted_at' => $registration->submitted_at
                        ?->format('Y-m-d'),
                ];
            });

        $pendingCount = ResidentRegistration::query()
            ->where('barangay_id', $user->barangay_id)
            ->whereIn('status', [
                'pending',
                'needs_correction',
            ])
            ->count();

        return Inertia::render('Admin/Residents', [
            'pendingRegistrations' => $pendingRegistrations,

            'stats' => [
                'totalResidents' => 0,
                'activeResidents' => 0,
                'pendingRegistrations' => $pendingCount,
            ],
        ]);
    }

    public function showDocument(
        Request $request,
        ResidentRegistration $registration,
        RegistrationDocument $document
    ): StreamedResponse {
        $user = $request->user();

        // Admin can only view applications from their own barangay.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            403
        );

        // Prevent using a document ID belonging to another registration.
        abort_unless(
            (int) $document->resident_registration_id ===
                (int) $registration->id,
            404
        );

        $disk = $document->disk ?: 'local';

        abort_unless(
            Storage::disk($disk)->exists($document->path),
            404
        );

        return Storage::disk($disk)->response(
            $document->path,
            $document->original_name ?: basename($document->path),
            [
                'Content-Type' => $document->mime_type
                    ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline'
        );
    }

    public function reject(
        Request $request,
        ResidentRegistration $registration
    ): RedirectResponse {
        $user = $request->user();

        // Admin can only review applicants from their barangay.
        abort_unless(
            (int) $registration->barangay_id ===
                (int) $user->barangay_id,
            403
        );

        // Only applications still under review can be rejected.
        abort_unless(
            in_array(
                $registration->status,
                [
                    'pending',
                    'needs_correction',
                ],
                true
            ),
            422
        );

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);

        $registration->update([
            'status' => 'rejected',
            'review_notes' => trim($validated['reason']),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return back()->with(
            'success',
            'Resident application rejected.'
        );
    }

    private function documentLabel(string $type): string
    {
        return match ($type) {
            'valid_id' => 'Valid ID',
            'live_identity_photo' => 'Live Identity Photo',
            'senior_proof' => 'Senior Citizen Proof',
            'pwd_proof' => 'PWD Proof',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
