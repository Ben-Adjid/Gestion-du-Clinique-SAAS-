<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClinicUsersSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Password@12345';

    public function run(): void
    {
        $this->syncDemoUser(
            'Super Administrator',
            'superadmin@example.com',
            '+221771234567',
            null,
            'SUPER_ADMIN',
        );

        $staff = [
            ['ADMIN_CLINIC', 'admin', 'Administrateur'],
            ['DOCTOR', 'doctor', 'Médecin'],
            ['NURSE', 'nurse', 'Infirmier'],
            ['PHARMACIST', 'pharmacist', 'Pharmacien'],
            ['LABORATORY_TECHNICIAN', 'labtech', 'Technicien de laboratoire'],
            ['RECEPTIONIST', 'receptionist', 'Réceptionniste'],
            ['CASHIER', 'cashier', 'Caissier'],
            ['RADIOLOGIST', 'radiologist', 'Radiologue'],
        ];

        foreach (Clinic::query()->orderBy('id')->get() as $clinic) {
            foreach ($staff as [$role, $emailPrefix, $displayName]) {
                $this->syncDemoUser(
                    "{$displayName} {$clinic->name}",
                    "{$emailPrefix}.clinic{$clinic->id}@example.com",
                    null,
                    $clinic,
                    $role,
                );
            }
        }

        $firstClinic = Clinic::query()->orderBy('id')->first();
        if ($firstClinic) {
            $this->syncDemoUser(
                'Gestionnaire RH',
                'hrmanager@example.com',
                null,
                $firstClinic,
                'HR_MANAGER',
            );

            $patient = Patient::firstOrCreate(
                ['patient_unique_id' => 'DEMO-'.$firstClinic->code],
                [
                    'clinic_id' => $firstClinic->id,
                    'first_name' => 'Patient',
                    'last_name' => 'Démo',
                ],
            );
            $patientUser = $this->syncDemoUser(
                'Patient de démonstration',
                'patient@example.com',
                null,
                $firstClinic,
                'PATIENT',
            );
            $patient->update(['user_id' => $patientUser->id]);
        }
    }

    private function syncDemoUser(
        string $name,
        string $email,
        ?string $phone,
        ?Clinic $clinic,
        string $roleName,
    ): User {
        $role = Role::where('name', $roleName)->firstOrFail();
        $attributes = [
            'name' => $name,
            'phone' => $phone,
            'password' => self::DEMO_PASSWORD,
            'clinic_id' => $clinic?->id,
            'role_id' => $role->id,
            'status' => 'ACTIVE',
        ];

        return User::updateOrCreate(['email' => $email], $attributes);
    }
}
