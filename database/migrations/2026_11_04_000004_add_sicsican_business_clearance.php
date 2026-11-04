<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('barangays')->whereIn('name', ['Sicsican', 'Barangay Sicsican'])->pluck('id') as $id) {
            DB::table('certificates')->insertOrIgnore(
                ['barangay_id' => $id, 'code' => 'business-clearance',
                    'name' => 'Business Clearance', 'description' => 'Business clearance for a Mayor’s Permit application.',
                    'fee' => 0, 'requirements' => json_encode(['Operator/owner name', 'Business address', 'Name/type of establishment']),
                    'requires_business_details' => true, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Retain catalog entries that may already have issued documents.
    }
};
