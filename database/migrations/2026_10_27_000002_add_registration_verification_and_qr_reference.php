<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();

            // Stores only the resolved credential reference, never the raw QR token.
            $table->foreignId('qr_credential_id')
                ->nullable()
                ->constrained('resident_qr_credentials', 'id', 'resident_reg_qr_credential_fk')
                ->nullOnDelete();

            $table->index(
                ['barangay_id', 'email_verified_at'],
                'resident_registrations_email_verified_index'
            );
            $table->index(
                'qr_credential_id',
                'resident_registrations_qr_credential_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('resident_registrations')->where(function ($query) {
            $query->whereNotNull('email_verified_at')
                ->orWhereNotNull('qr_credential_id');
        })->exists()) {
            throw new RuntimeException(
                'Refusing to remove registration email verification or QR references while data is present.'
            );
        }

        Schema::table('resident_registrations', function (Blueprint $table) {
            $table->dropIndex('resident_registrations_email_verified_index');
            $table->dropIndex('resident_registrations_qr_credential_index');
            $table->dropForeign('resident_reg_qr_credential_fk');
            $table->dropColumn(['email_verified_at', 'qr_credential_id']);
        });
    }
};
