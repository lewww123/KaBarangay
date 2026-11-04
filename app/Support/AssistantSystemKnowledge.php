<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\CitizenCharterService;
use App\Models\Resident;

final class AssistantSystemKnowledge
{
    public function snapshot(?Resident $resident): array
    {
        $knowledge = [
            'scope' => 'Gabay sa aktuwal na Resident Portal. Hindi awtomatikong nagsusumite, nag-aapprove, nagbabayad o nag-eedit ang chat.',
            'certificate_request' => ['url' => '/resident/certificates', 'steps' => ['Mag-sign in sa Resident Portal.', 'Buksan ang Request Certificate at piliin ang Request Certificate tab.', 'Suriin ang Your Information mula sa resident profile.', 'Piliin ang certificate mula sa available list. Basahin ang fee at requirements na nakalagay sa napili.', 'Ilagay ang purpose at lahat ng additional fields na hinihingi. Business details ay para lamang sa certificate na nangangailangan nito.', 'Mag-Continue papunta sa review, suriin ang detalye, at pindutin ang Submit Request.', 'Itabi ang reference number. Sa My Requests tab makikita ang status at tracking.', 'Kapag For Payment o Ready for Claim, sundin ang nakasaad sa request at instruction ng barangay. Ang online submission ay hindi awtomatikong online payment, approval, release, o downloadable signed certificate.'], 'statuses' => ['Pending' => 'Naisumite, naghihintay ng pagsusuri.', 'For Payment' => 'Sundin ang payment instructions sa request o barangay.', 'Processing' => 'Inihahanda ang certificate.', 'Ready for Claim' => 'Handa nang kunin ayon sa instructions ng barangay.', 'Released' => 'Naitalang na-release ang certificate.', 'Rejected' => 'Hindi naaprubahan; buksan ang sariling request para sa pinapayagang detalye.']],
            'lupon' => ['url' => '/resident/lupon', 'steps' => ['Buksan ang File a Complaint o Lupon.', 'Ilagay ang respondent information, complaint type, incident details, salaysay at requested action ayon sa form.', 'Magdagdag ng ebidensya kung mayroon, suriin at isumite.', 'Itabi ang reference number. Sa My Complaints, For Review muna ang status.', 'Kapag For Payment na, makikita ang assessed filing fee. Pumunta sa barangay office para magbayad at kumuha ng official receipt.', 'Itatala ng staff ang OR number at unang hearing schedule. Saka pa lamang awtomatikong malilikha ang case sa Kapitan phase.', 'Buksan ang linked case para sa hearing details at resulta. Hanggang tatlong hearings bawat phase; ang Kapitan hearing dates ay nasa 15-day period. Hindi online payment ang complaint submission.']],
            'sector_applications' => ['url' => '/resident/sector-applications', 'steps' => ['Buksan ang Sector Services o sector applications.', 'Piliin ang available sector at kumpletuhin ang hinihinging detalye at proof.', 'Suriin at isumite. Subaybayan ang application status at tugunan ang correction request kung mayroon.']],
            'registration' => ['url' => '/resident/register', 'steps' => ['Piliin ang barangay at purok, at kumpletuhin ang personal at sector information.', 'Ibigay ang required identity documents at live identity photo ayon sa form.', 'Maglagay ng valid email at mobile number at gumawa ng password.', 'Suriin at isumite para sa admin review.', 'Ipapadala sa inilagay na email ang approval, correction o rejection decision.'], 'email_otp_required' => (bool) config('registration.email_verification_required', false)],
            'other_pages' => ['announcements' => '/resident/announcements', 'programs' => '/resident/programs', 'citizens_charter' => '/resident/citizen-charter', 'organization_and_officials' => '/resident/org-chart', 'profile' => '/resident/profile', 'settings' => '/resident/settings'],
            'catalog_note' => 'Database catalog at published descriptions lamang ang facts. Kapag wala ang isang dokumento sa active catalog, huwag sabihing available ito. Huwag mag-imbento ng fees, processing time, eligibility o downloadable documents. Hindi pa kinukuha ang buong nilalaman ng charter PDF; gamitin ang published description at ibigay ang link kung kailangan ang buong official document.',
            'certificates' => [], 'published_services' => [],
        ];
        if (! $resident) {
            return $knowledge;
        }
        $knowledge['certificates'] = Certificate::query()->where('barangay_id', $resident->barangay_id)->where('is_active', true)->orderBy('name')->limit(100)->get()->map(fn ($certificate) => [
            'name' => $certificate->name, 'description' => $certificate->description, 'fee_php' => $certificate->fee, 'requirements' => $certificate->requirements ?? [], 'additional_fields' => CertificateRequestDetails::fieldsForCertificate($certificate), 'url' => '/resident/certificates',
        ])->all();
        $knowledge['published_services'] = CitizenCharterService::query()->where('barangay_id', $resident->barangay_id)->visibleToResidents()->orderBy('display_order')->limit(30)->get(['id', 'title', 'category', 'description'])->map(fn ($service) => ['title' => $service->title, 'category' => $service->category, 'description' => $service->description, 'url' => '/resident/citizen-charter/'.$service->id])->all();

        return $knowledge;
    }
}
