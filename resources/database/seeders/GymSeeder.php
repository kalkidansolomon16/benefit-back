<?php

namespace Database\Seeders;

use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GymSeeder extends Seeder
{
    public function run(): void
    {
        $gyms = [
            [
                'gym' => [
                    'name'              => 'Gold\'s Gym Bole',
                    'category'          => 'gym',
                    'tier'              => 'premium',
                    'address'           => 'Bole Road, Near Edna Mall',
                    'sub_city'          => 'Bole',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 9.0105,
                    'longitude'         => 38.7636,
                    'contact_person'    => 'Yohannes Tesfaye',
                    'contact_phone'     => '+251911234567',
                    'contact_email'     => 'owner@goldsgym.et',
                    'monthly_fee_etb'   => 1200.00,
                    'quarterly_fee_etb' => 3200.00,
                    'annual_fee_etb'    => 11000.00,
                    'max_capacity'      => 300,
                    'per_visit_rate'    => 80.00,
                    'quality_score'     => 92,
                    'partner_code'      => 'GGB-001',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-01-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1571902943202-507ec2618e8f?w=1200',
                    'facilities'        => ['Free Weights', 'Cardio Zone', 'Swimming Pool', 'Sauna', 'Personal Training', 'Group Classes', 'Juice Bar', 'Locker Rooms'],
                    'opening_hours'     => ['monday'=>'05:30-22:00','tuesday'=>'05:30-22:00','wednesday'=>'05:30-22:00','thursday'=>'05:30-22:00','friday'=>'05:30-22:00','saturday'=>'07:00-20:00','sunday'=>'07:00-20:00'],
                ],
                'owner' => [
                    'name'     => 'Yohannes Tesfaye',
                    'email'    => 'owner@goldsgym.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'Fitness First Kazanchis',
                    'category'          => 'gym',
                    'tier'              => 'premium',
                    'address'           => 'Kazanchis, Behind National Theatre',
                    'sub_city'          => 'Arada',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 9.0192,
                    'longitude'         => 38.7525,
                    'contact_person'    => 'Mekdes Alemu',
                    'contact_phone'     => '+251922345678',
                    'contact_email'     => 'owner@fitnessfirst.et',
                    'monthly_fee_etb'   => 1000.00,
                    'quarterly_fee_etb' => 2700.00,
                    'annual_fee_etb'    => 9500.00,
                    'max_capacity'      => 250,
                    'per_visit_rate'    => 70.00,
                    'quality_score'     => 88,
                    'partner_code'      => 'FFK-002',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-02-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1200',
                    'facilities'        => ['Free Weights', 'Cardio Zone', 'Spin Classes', 'Yoga Studio', 'Personal Training', 'Locker Rooms', 'Towel Service'],
                    'opening_hours'     => ['monday'=>'06:00–22:00','tuesday'=>'06:00–22:00','wednesday'=>'06:00–22:00','thursday'=>'06:00–22:00','friday'=>'06:00–22:00','saturday'=>'07:00–19:00','sunday'=>'07:00–19:00'],
                ],
                'owner' => [
                    'name'     => 'Mekdes Alemu',
                    'email'    => 'owner@fitnessfirst.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'Sana Gym Sarbet',
                    'category'          => 'gym',
                    'tier'              => 'basic_plus',
                    'address'           => 'Sarbet, CMC Road',
                    'sub_city'          => 'Nifas Silk-Lafto',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 8.9936,
                    'longitude'         => 38.7614,
                    'contact_person'    => 'Dawit Bekele',
                    'contact_phone'     => '+251933456789',
                    'contact_email'     => 'owner@sanagym.et',
                    'monthly_fee_etb'   => 700.00,
                    'quarterly_fee_etb' => 1900.00,
                    'annual_fee_etb'    => 7000.00,
                    'max_capacity'      => 180,
                    'per_visit_rate'    => 50.00,
                    'quality_score'     => 78,
                    'partner_code'      => 'SGS-003',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-03-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1593079831268-3381b0db4a77?w=1200',
                    'facilities'        => ['Free Weights', 'Cardio Zone', 'Boxing Ring', 'Locker Rooms'],
                    'opening_hours'     => ['monday'=>'06:00–21:00','tuesday'=>'06:00–21:00','wednesday'=>'06:00–21:00','thursday'=>'06:00–21:00','friday'=>'06:00–21:00','saturday'=>'08:00–18:00','sunday'=>'08:00–18:00'],
                ],
                'owner' => [
                    'name'     => 'Dawit Bekele',
                    'email'    => 'owner@sanagym.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'Elite Sports Club Gerji',
                    'category'          => 'sports_club',
                    'tier'              => 'premium',
                    'address'           => 'Gerji, Opposite Gerji Church',
                    'sub_city'          => 'Bole',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 9.0031,
                    'longitude'         => 38.7982,
                    'contact_person'    => 'Tigist Haile',
                    'contact_phone'     => '+251944567890',
                    'contact_email'     => 'owner@elitesports.et',
                    'monthly_fee_etb'   => 1500.00,
                    'quarterly_fee_etb' => 4000.00,
                    'annual_fee_etb'    => 14000.00,
                    'max_capacity'      => 400,
                    'per_visit_rate'    => 100.00,
                    'quality_score'     => 95,
                    'partner_code'      => 'ESG-004',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-01-15',
                    'photo_url'         => 'https://images.unsplash.com/photo-1576678927484-cc907957088c?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1483721310020-03333e577078?w=1200',
                    'facilities'        => ['Olympic Pool', 'Tennis Courts', 'Squash', 'Free Weights', 'Cardio Zone', 'Sauna', 'Steam Room', 'Restaurant', 'Parking'],
                    'opening_hours'     => ['monday'=>'05:00–23:00','tuesday'=>'05:00–23:00','wednesday'=>'05:00–23:00','thursday'=>'05:00–23:00','friday'=>'05:00–23:00','saturday'=>'06:00–22:00','sunday'=>'06:00–22:00'],
                ],
                'owner' => [
                    'name'     => 'Tigist Haile',
                    'email'    => 'owner@elitesports.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'PowerHouse Gym Megenagna',
                    'category'          => 'gym',
                    'tier'              => 'basic_plus',
                    'address'           => 'Megenagna, Roundabout Area',
                    'sub_city'          => 'Yeka',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 9.0287,
                    'longitude'         => 38.8002,
                    'contact_person'    => 'Biruk Girma',
                    'contact_phone'     => '+251955678901',
                    'contact_email'     => 'owner@powerhouse.et',
                    'monthly_fee_etb'   => 800.00,
                    'quarterly_fee_etb' => 2100.00,
                    'annual_fee_etb'    => 7800.00,
                    'max_capacity'      => 200,
                    'per_visit_rate'    => 55.00,
                    'quality_score'     => 80,
                    'partner_code'      => 'PHM-005',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-04-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1521804906057-1df8fdb718b7?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1549060279-7e168fcee0c2?w=1200',
                    'facilities'        => ['Free Weights', 'Cardio Zone', 'CrossFit Area', 'Group Classes', 'Locker Rooms'],
                    'opening_hours'     => ['monday'=>'06:00–22:00','tuesday'=>'06:00–22:00','wednesday'=>'06:00–22:00','thursday'=>'06:00–22:00','friday'=>'06:00–22:00','saturday'=>'07:00–20:00','sunday'=>'07:00–20:00'],
                ],
                'owner' => [
                    'name'     => 'Biruk Girma',
                    'email'    => 'owner@powerhouse.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'Zen Wellness Spa & Fitness',
                    'category'          => 'wellness',
                    'tier'              => 'premium',
                    'address'           => 'Old Airport, Africa Avenue',
                    'sub_city'          => 'Bole',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 8.9978,
                    'longitude'         => 38.7794,
                    'contact_person'    => 'Selam Tadesse',
                    'contact_phone'     => '+251966789012',
                    'contact_email'     => 'owner@zenwellness.et',
                    'monthly_fee_etb'   => 1800.00,
                    'quarterly_fee_etb' => 4800.00,
                    'annual_fee_etb'    => 17000.00,
                    'max_capacity'      => 150,
                    'per_visit_rate'    => 120.00,
                    'quality_score'     => 97,
                    'partner_code'      => 'ZWF-006',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-01-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?w=1200',
                    'facilities'        => ['Yoga Studio', 'Pilates', 'Meditation Room', 'Hot Tub', 'Sauna', 'Massage Therapy', 'Nutrition Consulting', 'Juice Bar'],
                    'opening_hours'     => ['monday'=>'06:00–21:00','tuesday'=>'06:00–21:00','wednesday'=>'06:00–21:00','thursday'=>'06:00–21:00','friday'=>'06:00–21:00','saturday'=>'07:00–20:00','sunday'=>'07:00–20:00'],
                ],
                'owner' => [
                    'name'     => 'Selam Tadesse',
                    'email'    => 'owner@zenwellness.et',
                    'password' => 'Password@123',
                ],
            ],
            [
                'gym' => [
                    'name'              => 'Iron Temple Gym Piassa',
                    'category'          => 'gym',
                    'tier'              => 'basic',
                    'address'           => 'Piassa, Near St. George Cathedral',
                    'sub_city'          => 'Arada',
                    'city'              => 'Addis Ababa',
                    'latitude'          => 9.0354,
                    'longitude'         => 38.7477,
                    'contact_person'    => 'Abebe Worku',
                    'contact_phone'     => '+251977890123',
                    'contact_email'     => 'owner@irontemple.et',
                    'monthly_fee_etb'   => 450.00,
                    'quarterly_fee_etb' => 1200.00,
                    'annual_fee_etb'    => 4500.00,
                    'max_capacity'      => 120,
                    'per_visit_rate'    => 35.00,
                    'quality_score'     => 70,
                    'partner_code'      => 'ITP-007',
                    'is_active'         => true,
                    'is_partner'        => true,
                    'partnership_start' => '2025-05-01',
                    'photo_url'         => 'https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?w=800',
                    'cover_photo_url'   => 'https://images.unsplash.com/photo-1574680096145-d05b474e2155?w=1200',
                    'facilities'        => ['Free Weights', 'Cardio Zone', 'Locker Rooms'],
                    'opening_hours'     => ['monday'=>'06:00–21:00','tuesday'=>'06:00–21:00','wednesday'=>'06:00–21:00','thursday'=>'06:00–21:00','friday'=>'06:00–21:00','saturday'=>'08:00–17:00','sunday'=>'08:00–17:00'],
                ],
                'owner' => [
                    'name'     => 'Abebe Worku',
                    'email'    => 'owner@irontemple.et',
                    'password' => 'Password@123',
                ],
            ],
        ];

        foreach ($gyms as $entry) {
            $gymData   = $entry['gym'];
            $ownerData = $entry['owner'];

            // Create or update the gym
            $gym = Gym::updateOrCreate(
                ['partner_code' => $gymData['partner_code']],
                $gymData
            );

            // Create or update the owner user
            $user = User::updateOrCreate(
                ['email' => $ownerData['email']],
                [
                    'name'       => $ownerData['name'],
                    'password'   => Hash::make($ownerData['password']),
                    'role'       => 'gym_partner',
                    'gym_id'     => $gym->id,
                    'is_active'  => true,
                ]
            );

            // Link via GymStaff as admin/owner
            GymStaff::updateOrCreate(
                ['user_id' => $user->id, 'gym_id' => $gym->id],
                [
                    'role'                 => 'admin',
                    'is_active'            => true,
                    'must_change_password' => false,
                ]
            );

            $this->command->info("✓ {$gym->name} — login: {$ownerData['email']} / {$ownerData['password']}");
        }
    }
}
