<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::definitions() as $perm) {
            \App\Models\Permission::firstOrCreate(['name' => $perm['name']], $perm);
        }
    }

    /** @return list<array{name: string, resource: string, action: string}> */
    public static function definitions(): array
    {
        return [
            // Patient management
            ['name' => 'create:patient', 'resource' => 'patient', 'action' => 'create'],
            ['name' => 'read:patient', 'resource' => 'patient', 'action' => 'read'],
            ['name' => 'update:patient', 'resource' => 'patient', 'action' => 'update'],
            ['name' => 'read:patient_clinical_profile', 'resource' => 'patient', 'action' => 'read_clinical_profile'],
            ['name' => 'update:patient_clinical_profile', 'resource' => 'patient', 'action' => 'update_clinical_profile'],
            ['name' => 'delete:patient', 'resource' => 'patient', 'action' => 'delete'],
            ['name' => 'import:patients', 'resource' => 'patient', 'action' => 'import'],

            // DPE (Medical Record)
            ['name' => 'read:dpe', 'resource' => 'dpe', 'action' => 'read'],
            ['name' => 'update:dpe', 'resource' => 'dpe', 'action' => 'update'],

            // Consultation
            ['name' => 'create:consultation', 'resource' => 'consultation', 'action' => 'create'],
            ['name' => 'read:consultation', 'resource' => 'consultation', 'action' => 'read'],
            ['name' => 'update:consultation', 'resource' => 'consultation', 'action' => 'update'],
            ['name' => 'sign:consultation', 'resource' => 'consultation', 'action' => 'sign'],

            // Medical certificates
            ['name' => 'create:certificate', 'resource' => 'certificate', 'action' => 'create'],
            ['name' => 'read:certificate', 'resource' => 'certificate', 'action' => 'read'],

            // Prescription
            ['name' => 'create:prescription', 'resource' => 'prescription', 'action' => 'create'],
            ['name' => 'read:prescription', 'resource' => 'prescription', 'action' => 'read'],
            ['name' => 'update:prescription', 'resource' => 'prescription', 'action' => 'update'],
            ['name' => 'sign:prescription', 'resource' => 'prescription', 'action' => 'sign'],

            // Pharmacy
            ['name' => 'manage:pharmacy_stock', 'resource' => 'pharmacy', 'action' => 'manage_stock'],
            ['name' => 'dispense:medication', 'resource' => 'pharmacy', 'action' => 'dispense'],

            // Lab
            ['name' => 'collect:lab_sample', 'resource' => 'lab', 'action' => 'collect_sample'],
            ['name' => 'create:lab_request', 'resource' => 'lab', 'action' => 'create_request'],
            ['name' => 'submit:lab_results', 'resource' => 'lab', 'action' => 'submit_results'],
            ['name' => 'sign:lab_results', 'resource' => 'lab', 'action' => 'sign_results'],

            // Imaging
            ['name' => 'upload:imaging', 'resource' => 'imaging', 'action' => 'upload'],
            ['name' => 'create:imaging_report', 'resource' => 'imaging', 'action' => 'create_report'],

            // Billing & Invoicing
            ['name' => 'create:invoice', 'resource' => 'invoice', 'action' => 'create'],
            ['name' => 'read:invoice', 'resource' => 'invoice', 'action' => 'read'],
            ['name' => 'manage:payment', 'resource' => 'payment', 'action' => 'manage'],

            // Appointments
            ['name' => 'create:appointment', 'resource' => 'appointment', 'action' => 'create'],
            ['name' => 'read:appointment', 'resource' => 'appointment', 'action' => 'read'],
            ['name' => 'update:appointment', 'resource' => 'appointment', 'action' => 'update'],
            ['name' => 'cancel:appointment', 'resource' => 'appointment', 'action' => 'cancel'],
            ['name' => 'request:appointment', 'resource' => 'appointment', 'action' => 'request'],

            // Lab and imaging access
            ['name' => 'read:lab_request', 'resource' => 'lab', 'action' => 'read'],
            ['name' => 'update:lab_request', 'resource' => 'lab', 'action' => 'update'],
            ['name' => 'read:imaging', 'resource' => 'imaging', 'action' => 'read'],
            ['name' => 'create:imaging_request', 'resource' => 'imaging', 'action' => 'create_request'],
            ['name' => 'update:imaging', 'resource' => 'imaging', 'action' => 'update'],

            // Invoice maintenance and price list
            ['name' => 'update:invoice', 'resource' => 'invoice', 'action' => 'update'],
            ['name' => 'manage:tariffs', 'resource' => 'invoice', 'action' => 'manage_tariffs'],

            // HR & Payroll
            ['name' => 'manage:employees', 'resource' => 'hr', 'action' => 'manage_employees'],
            ['name' => 'approve:payroll', 'resource' => 'hr', 'action' => 'approve_payroll'],
            ['name' => 'access:staff_space', 'resource' => 'hr', 'action' => 'self_service'],
            ['name' => 'access:patient_portal', 'resource' => 'portal', 'action' => 'access'],
            ['name' => 'manage:attendance', 'resource' => 'hr', 'action' => 'manage_attendance'],
            ['name' => 'manage:payroll', 'resource' => 'hr', 'action' => 'manage_payroll'],
            ['name' => 'manage:leaves', 'resource' => 'hr', 'action' => 'manage_leaves'],

            // Admin functions
            ['name' => 'manage:users', 'resource' => 'admin', 'action' => 'manage_users'],
            ['name' => 'manage:roles', 'resource' => 'admin', 'action' => 'manage_roles'],
            ['name' => 'view:reports', 'resource' => 'admin', 'action' => 'view_reports'],
            ['name' => 'view:audit_logs', 'resource' => 'admin', 'action' => 'view_audit_logs'],
            ['name' => 'manage:exams', 'resource' => 'admin', 'action' => 'manage_exams'],

            // Nursing care
            ['name' => 'read:care_task', 'resource' => 'care_task', 'action' => 'read'],
            ['name' => 'create:care_task', 'resource' => 'care_task', 'action' => 'create'],
            ['name' => 'complete:care_task', 'resource' => 'care_task', 'action' => 'complete'],
            ['name' => 'read:vitals', 'resource' => 'vitals', 'action' => 'read'],
            ['name' => 'record:vitals', 'resource' => 'vitals', 'action' => 'record'],

            // SaaS platform (super administrator) and clinic subscription
            ['name' => 'manage:clinics', 'resource' => 'platform', 'action' => 'manage_clinics'],
            ['name' => 'manage:subscriptions', 'resource' => 'platform', 'action' => 'manage_subscriptions'],
            ['name' => 'view:saas_dashboard', 'resource' => 'platform', 'action' => 'view_dashboard'],
            ['name' => 'view:subscription', 'resource' => 'subscription', 'action' => 'view'],
            ['name' => 'declare:subscription_payment', 'resource' => 'subscription', 'action' => 'declare_payment'],
        ];
    }
}
