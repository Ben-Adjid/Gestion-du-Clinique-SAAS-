<?php

namespace Database\Seeders;

use App\Models\Clinic;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ClinicSeeder extends Seeder
{
    public function run(): void
    {
        $clinics = [
            [
                'name' => 'Clinique Central Dakar',
                'city' => 'Dakar',
                'country' => 'Senegal',
                'code' => 'CENTRAL_DKR',
                'admin_email' => 'admin@clinique-central.sn',
                'admin_phone' => '+221338246789',
                'subscription_plan' => 'ENTERPRISE',
                'status' => 'ACTIVE',
            ],
            [
                'name' => 'Clinic Thiès Medical Center',
                'city' => 'Thiès',
                'country' => 'Senegal',
                'code' => 'THIES_MED',
                'admin_email' => 'admin@thies-medical.sn',
                'admin_phone' => '+221339641234',
                'subscription_plan' => 'PRO',
                'status' => 'ACTIVE',
            ],
            [
                'name' => 'Cabinet de Santé Kaolack',
                'city' => 'Kaolack',
                'country' => 'Senegal',
                'code' => 'KAOLACK_CAB',
                'admin_email' => 'admin@cabinet-kaolack.sn',
                'admin_phone' => '+221396156789',
                'subscription_plan' => 'BASIC',
                'status' => 'ACTIVE',
            ],
        ];

        foreach ($clinics as $clinic) {
            Clinic::firstOrCreate(['code' => $clinic['code']], $clinic);
        }
    }
}
