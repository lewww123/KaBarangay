<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('barangays')->whereIn('name', ['Sicsican', 'Barangay Sicsican'])->pluck('id') as $id) {
            DB::table('certificates')->insertOrIgnore([
                'barangay_id' => $id, 'code' => 'barangay-certification',
                'name' => 'Barangay Certification',
                'description' => 'Certification of residence and years of residency for a stated purpose.',
                'fee' => 0,
                'requirements' => json_encode(['Resident name', 'Purok', 'Years of residency', 'Purpose']),
                'requires_business_details' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve catalog entries that may already have requests or issuances.
    }
};
