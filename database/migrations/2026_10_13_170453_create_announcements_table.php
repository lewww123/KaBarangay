<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Announcements Table
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained('barangays')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('content');
            $table->string('category', 50)->default('General'); // General, Event, Relief Goods, Cash Assistance, Meeting, Water Interruption, Others
            $table->string('target_audience', 50)->default('all'); // all, senior_citizens, pwd, sector_specific
            $table->foreignId('target_sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            
            $table->boolean('requires_attendance')->default(false);
            $table->dateTime('event_start_at')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->index(['barangay_id', 'is_published', 'target_audience']);
        });

        // 2. Announcement Attendances Table (Para sa Beneficiaries & Attendance)
        Schema::create('announcement_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained('barangays')->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            $table->string('status', 30)->default('expected'); // expected, present, claimed, absent
            $table->string('method', 20)->nullable(); // qr_scan, manual
            $table->timestamp('attended_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['announcement_id', 'resident_id']);
            $table->index(['barangay_id', 'announcement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_attendances');
        Schema::dropIfExists('announcements');
    }
};