<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Certificate;
use Illuminate\Database\Seeder;

class CertificateSeeder extends Seeder
{
    public function run(): void
    {
        $certificates = [
            [
                'code' => 'residency',
                'name' => 'Certificate of Residency',
                'fee' => 100,
                'description' => 'Proof that you are a registered resident of this barangay.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Verified resident information',
                ],
                'requires_business_details' => false,
            ],
            [
                'code' => 'indigency',
                'name' => 'Certificate of Indigency',
                'fee' => 0,
                'description' => 'Certification for qualified residents who need proof of indigency.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Valid purpose for requesting the certificate',
                ],
                'requires_business_details' => false,
            ],
            [
                'code' => 'barangay-clearance',
                'name' => 'Barangay Clearance',
                'fee' => 0,
                'description' => 'Barangay clearance for personal, employment, or official use.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Verified resident information',
                ],
                'requires_business_details' => false,
            ],
            [
                'code' => 'retail',
                'name' => 'Retail',
                'fee' => 300,
                'description' => 'Certificate for retail-related business purposes.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Business name',
                    'Business address',
                ],
                'requires_business_details' => true,
            ],
            [
                'code' => 'tricycle',
                'name' => 'Tricycle',
                'fee' => 300,
                'description' => 'Certificate for tricycle-related purposes.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Valid request purpose',
                ],
                'requires_business_details' => false,
            ],
            [
                'code' => 'sari-sari',
                'name' => 'Sari-Sari Store',
                'fee' => 500,
                'description' => 'Certificate for sari-sari store business operations.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Business name',
                    'Business address',
                ],
                'requires_business_details' => true,
            ],
            [
                'code' => 'hardware',
                'name' => 'Hardware Store',
                'fee' => 3000,
                'description' => 'Certificate for hardware store business operations.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Business name',
                    'Business address',
                ],
                'requires_business_details' => true,
            ],
            [
                'code' => 'pool-resort',
                'name' => 'Pool / Resort',
                'fee' => 1500,
                'description' => 'Certificate for pool or resort-related business operations.',
                'requirements' => [
                    'Approved KaBarangay resident account',
                    'Business name',
                    'Business address',
                ],
                'requires_business_details' => true,
            ],
        ];

        $barangays = Barangay::query()->get(['id']);

        if ($barangays->isEmpty()) {
            $this->command?->warn(
                'No barangays found. Add barangay records first.'
            );

            return;
        }

        foreach ($barangays as $barangay) {
            foreach ($certificates as $certificate) {
                Certificate::updateOrCreate(
                    [
                        'barangay_id' => $barangay->id,
                        'code' => $certificate['code'],
                    ],
                    [
                        'name' => $certificate['name'],
                        'fee' => $certificate['fee'],
                        'description' => $certificate['description'],
                        'requirements' => $certificate['requirements'],
                        'requires_business_details' =>
                            $certificate['requires_business_details'],
                        'is_active' => true,
                    ]
                );
            }
        }

        $this->command?->info(
            'Successfully seeded 8 certificate types for '
            . $barangays->count()
            . ' barangay(s).'
        );
    }
}
