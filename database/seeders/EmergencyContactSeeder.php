<?php

namespace Database\Seeders;

use App\Models\EmergencyContact;
use Illuminate\Database\Seeder;
use RuntimeException;

class EmergencyContactSeeder extends Seeder
{
    /**
     * Default emergency contact seed data preserved from existing resident dashboard.
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaultContacts(int $barangayId): array
    {
        return [
            // BARANGAY HOTLINES
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Official',
                'name' => 'Barangay San Jose Tanod',
                'label' => 'Official Hotline',
                'phone_number' => '09394596081',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Tanod',
                'name' => 'Cenon Mabutin Bocacao',
                'label' => 'Chief Tanod',
                'phone_number' => '09262235797',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Tanod',
                'name' => 'Romy Dangan Quiray',
                'label' => 'Summon Officer',
                'phone_number' => '09050847008',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 3,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Tanod',
                'name' => 'Ronie Herrera Nazon',
                'label' => 'Team Leader',
                'phone_number' => '09661961005',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 4,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Tanod',
                'name' => 'Tito Paduga Jagmis',
                'label' => 'Team Leader',
                'phone_number' => '09550717360',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 5,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_BARANGAY_HOTLINE,
                'sub_category' => 'Tanod',
                'name' => 'Elmer Magbura Martinez',
                'label' => 'Team Leader',
                'phone_number' => '09050578797',
                'can_call' => true,
                'can_sms' => true,
                'display_order' => 6,
                'is_active' => true,
            ],

            // EMERGENCY HOTLINES
            // City Emergency
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'City Emergency',
                'name' => 'Puerto Princesa City - 911',
                'label' => 'Emergency Hotline 1',
                'phone_number' => '09277972009',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 10,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'City Emergency',
                'name' => 'Puerto Princesa City - 911',
                'label' => 'Emergency Hotline 2',
                'phone_number' => '09204305378',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 11,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'City Emergency',
                'name' => 'Puerto Princesa City - 911',
                'label' => 'Emergency Hotline 3',
                'phone_number' => '09171120324',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 12,
                'is_active' => true,
            ],

            // Ambulance
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Ambulance',
                'name' => 'HERO',
                'label' => 'Ambulance Hotline',
                'phone_number' => '09504912599',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 20,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Ambulance',
                'name' => 'HERO',
                'label' => 'Ambulance Hotline',
                'phone_number' => '09513126727',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 21,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Ambulance',
                'name' => 'HERO',
                'label' => 'Ambulance Hotline',
                'phone_number' => '09953322488',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 22,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Ambulance',
                'name' => 'HERO',
                'label' => 'Health Emergency Response Operations',
                'phone_number' => '09103254577',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 23,
                'is_active' => true,
            ],

            // Fire
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Fire',
                'name' => 'PPC BFP',
                'label' => 'Fire Emergency',
                'phone_number' => '09649452971',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 30,
                'is_active' => true,
            ],

            // Police
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Police',
                'name' => 'PNP Station 1 - Mendoza',
                'label' => 'Police',
                'phone_number' => '09173115746',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 40,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Police',
                'name' => 'PNP Station 2 - Irawan',
                'label' => 'Police',
                'phone_number' => '09271624065',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 41,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Police',
                'name' => 'PNP Station 3 - Luzviminda',
                'label' => 'Police',
                'phone_number' => '09272347443',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 42,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Police',
                'name' => 'PNP Station 4 - Macarascas',
                'label' => 'Police',
                'phone_number' => '09282006155',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 43,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Police',
                'name' => 'PNP Station 5 - San Rafael',
                'label' => 'Police',
                'phone_number' => '09993802689',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 44,
                'is_active' => true,
            ],

            // Hospital
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Hospital',
                'name' => 'ONP',
                'label' => 'Hospital',
                'phone_number' => '09271338635',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 50,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Hospital',
                'name' => 'Adventist Hospital',
                'label' => 'Hospital',
                'phone_number' => '09455097723',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 51,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Hospital',
                'name' => 'ACE',
                'label' => 'Hospital',
                'phone_number' => '09176323122',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 52,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Hospital',
                'name' => 'PMMG-COOP',
                'label' => 'Hospital',
                'phone_number' => '09064487439',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 53,
                'is_active' => true,
            ],

            // Disaster
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Disaster',
                'name' => 'CDRRMO',
                'label' => 'Bagyo / Baha / Guho',
                'phone_number' => '09653148399',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 60,
                'is_active' => true,
            ],
            [
                'barangay_id' => $barangayId,
                'category' => EmergencyContact::CATEGORY_EMERGENCY_HOTLINE,
                'sub_category' => 'Disaster',
                'name' => 'CDRRMO',
                'label' => 'Bagyo / Baha / Guho',
                'phone_number' => '09387944004',
                'can_call' => true,
                'can_sms' => false,
                'display_order' => 61,
                'is_active' => true,
            ],
        ];
    }

    public function run(): void
    {
        throw new RuntimeException(
            'EmergencyContactSeeder is retired. Its legacy contacts are not a verified shared directory and must not be seeded automatically.'
        );
    }
}
