<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('barangays')->whereIn('name', ['Sicsican', 'Barangay Sicsican'])->pluck('id') as $id) {
            DB::table('certificates')->insertOrIgnore([
                'barangay_id' => $id, 'code' => 'sicsican-clearance',
                'name' => 'Barangay Clearance',
                'description' => 'Barangay clearance for loan and employment applications.',
                'fee' => 0, 'requirements' => json_encode(['Resident name', 'Purok', 'Purpose']),
                'requires_business_details' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve any catalog entry already linked to requests or issued PDFs.
    }
};

