<?php

namespace Tests\Feature;

use App\Jobs\SendResidentUpdateEmail;
use App\Mail\ResidentUpdateMail;
use App\Models\AnnouncementAttendance;
use App\Models\CertificateRequest;
use App\Models\ResidentInboxNotification;
use App\Observers\ResidentUpdateObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesBarangayFixtures;
use Tests\TestCase;

class ResidentNotificationTest extends TestCase
{
    use CreatesBarangayFixtures, RefreshDatabase;

    public function test_attendance_generates_one_notification_and_queues_email(): void
    {
        Bus::fake();
        $barangay = $this->createBarangay('Notify', 'NOTIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $announcement = $this->createAnnouncement($barangay);
        $attendance = AnnouncementAttendance::create([
            'barangay_id' => $barangay->id, 'resident_id' => $resident->id,
            'announcement_id' => $announcement->id, 'status' => 'present',
            'method' => 'manual', 'attended_at' => now(),
        ]);
        $attendance->update(['notes' => 'Updated note']);
        $this->assertDatabaseCount('resident_notifications', 1);
        $this->assertDatabaseHas('resident_notifications', ['resident_id' => $resident->id, 'title' => 'Attendance confirmed']);
        Bus::assertDispatchedTimes(SendResidentUpdateEmail::class, 1);
    }

    public function test_certificate_ready_event_is_deduplicated_and_other_statuses_are_ignored(): void
    {
        Bus::fake();
        $barangay = $this->createBarangay('Certificate', 'CERT-NOTIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        // Exercise the observer payload without bypassing certificate workflow prerequisites in production.
        $record = new CertificateRequest(['barangay_id' => $barangay->id, 'resident_id' => $resident->id, 'reference' => 'CERT-TEST', 'status' => 'Processing']);
        $record->id = 123;
        $record->wasRecentlyCreated = true;
        $observer = new ResidentUpdateObserver;
        $observer->saved($record);
        $this->assertDatabaseCount('resident_notifications', 0);
        $record->status = 'Ready for Claim';
        $observer->saved($record);
        $observer->saved($record);
        $this->assertDatabaseCount('resident_notifications', 1);
        Bus::assertDispatchedTimes(SendResidentUpdateEmail::class, 1);
    }

    public function test_resident_can_only_list_and_mark_own_notifications(): void
    {
        $barangay = $this->createBarangay('Private', 'PRIVATE-NOTIFY');
        $purok = $this->createPurok($barangay);
        $resident = $this->createResident($barangay, $purok);
        $other = $this->createResident($barangay, $purok, ['mobile' => '09170000002']);
        $own = $this->notice($resident);
        $foreign = $this->notice($other);
        $this->withSession(['resident_id' => $resident->id]);
        $this->getJson('/resident/notifications')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1);
        $this->postJson("/resident/notifications/{$foreign->id}/read")->assertNotFound();
        $this->postJson("/resident/notifications/{$own->id}/read")->assertOk();
        $this->getJson('/resident/notifications')->assertJsonPath('unread_count', 0);
        $own->update(['read_at' => null]);
        $this->postJson('/resident/notifications/read-all')->assertOk();
        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_email_requires_verified_address_and_renders_update(): void
    {
        Mail::fake();
        $barangay = $this->createBarangay('Mail', 'MAIL-NOTIFY');
        $resident = $this->createResident($barangay, $this->createPurok($barangay));
        $resident->update(['email' => 'resident@example.test']);
        $notice = $this->notice($resident);
        (new SendResidentUpdateEmail($notice->id))->handle();
        Mail::assertNothingSent();
        $resident->update(['email_verified_at' => now()]);
        (new SendResidentUpdateEmail($notice->id))->handle();
        Mail::assertSent(ResidentUpdateMail::class, function ($mail) {
            return $mail->hasTo('resident@example.test') && str_contains($mail->render(), 'Your update is ready.');
        });
    }

    private function notice($resident): ResidentInboxNotification
    {
        return ResidentInboxNotification::create([
            'resident_id' => $resident->id, 'barangay_id' => $resident->barangay_id,
            'event_key' => 'test:'.$resident->id, 'title' => 'Test update',
            'message' => 'Your update is ready.', 'url' => '/resident/certificates',
        ]);
    }
}
