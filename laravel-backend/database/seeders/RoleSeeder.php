<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'SUPER_ADMIN', 'display_name' => 'Super Administrator', 'description' => 'Full system access'],
            ['name' => 'ADMIN_CLINIC', 'display_name' => 'Clinic Admin', 'description' => 'Clinic management and configuration'],
            ['name' => 'DOCTOR', 'display_name' => 'Doctor', 'description' => 'Medical doctor - consultations and prescriptions'],
            ['name' => 'NURSE', 'display_name' => 'Nurse', 'description' => 'Nurse - patient care and vital signs'],
            ['name' => 'CASHIER', 'display_name' => 'Cashier', 'description' => 'Financial transactions and billing'],
            ['name' => 'PHARMACIST', 'display_name' => 'Pharmacist', 'description' => 'Pharmacy operations and stock management'],
            ['name' => 'LABORATORY_TECHNICIAN', 'display_name' => 'Lab Technician', 'description' => 'Laboratory tests and results'],
            ['name' => 'RADIOLOGIST', 'display_name' => 'Radiologist', 'description' => 'Imaging studies and reports'],
            ['name' => 'HR_MANAGER', 'display_name' => 'HR Manager', 'description' => 'Human resources and payroll'],
            ['name' => 'RECEPTIONIST', 'display_name' => 'Receptionist', 'description' => 'Patient intake and scheduling'],
            ['name' => 'PATIENT', 'display_name' => 'Patient', 'description' => 'Personal medical record and appointments'],
        ];

        foreach ($roles as $role) {
            \App\Models\Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
