<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            $table->text('email_safe_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('resident_notification_outbox')->whereNotNull('email_safe_reason')->exists()) {
            throw new RuntimeException(
                'Refusing to remove resident-facing rejection reason snapshots while notification history contains them.'
            );
        }

        Schema::table('resident_notification_outbox', function (Blueprint $table) {
            $table->dropColumn('email_safe_reason');
        });
    }
};
