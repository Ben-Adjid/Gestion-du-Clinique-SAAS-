<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $rolePermissions = [
            // Platform operator: no access to medical records (medical secrecy), only the SaaS.
            'SUPER_ADMIN' => [
                'manage:clinics', 'manage:subscriptions', 'view:saas_dashboard',
                'manage:users', 'manage:roles', 'view:audit_logs',
            ],
            'ADMIN_CLINIC' => [
                'manage:users', 'manage:roles', 'view:reports', 'view:audit_logs', 'manage:exams',
                'view:subscription', 'declare:subscription_payment', 'read:doctor_planning', 'manage:doctor_planning', 'read:beds', 'manage:beds',
                'create:patient', 'read:patient', 'update:patient', 'delete:patient', 'import:patients',
                'create:appointment', 'read:appointment', 'update:appointment', 'cancel:appointment',
                'read:invoice', 'create:invoice', 'update:invoice', 'manage:payment', 'manage:tariffs',
                // Results of lab and imaging exams are medical content: not for the director.
                // HR: the director sees the staff and approves the payroll prepared by HR.
                'manage:employees', 'approve:payroll',
            ],
            'DOCTOR' => ['read:patient', 'read:patient_clinical_profile', 'update:patient_clinical_profile', 'read:dpe', 'update:dpe', 'create:appointment', 'read:appointment', 'update:appointment', 'cancel:appointment', 'create:consultation', 'read:consultation', 'update:consultation', 'sign:consultation', 'create:certificate', 'read:certificate', 'create:prescription', 'read:prescription', 'update:prescription', 'sign:prescription', 'create:lab_request', 'read:lab_request', 'read:imaging', 'create:imaging_request', 'read:care_task', 'create:care_task', 'read:vitals', 'record:vitals', 'read:doctor_planning', 'read:surgery', 'manage:surgery', 'edit:surgery_report', 'read:beds'],
            'NURSE' => ['read:patient', 'read:patient_clinical_profile', 'read:dpe', 'create:appointment', 'read:appointment', 'update:appointment', 'read:consultation', 'read:prescription', 'create:lab_request', 'read:lab_request', 'collect:lab_sample', 'read:care_task', 'complete:care_task', 'read:vitals', 'record:vitals', 'read:doctor_planning', 'read:surgery', 'read:beds', 'manage:beds'],
            'CASHIER' => ['read:patient', 'read:invoice', 'create:invoice', 'update:invoice', 'manage:payment'],
            'PHARMACIST' => ['read:patient', 'read:prescription', 'manage:pharmacy_stock', 'dispense:medication'],
            'LABORATORY_TECHNICIAN' => ['read:patient', 'create:lab_request', 'read:lab_request', 'collect:lab_sample', 'update:lab_request', 'submit:lab_results', 'sign:lab_results'],
            'RADIOLOGIST' => ['read:patient', 'read:imaging', 'upload:imaging', 'update:imaging', 'create:imaging_report'],
            // Read-only view of the doctors' planning (presence and on-call duty), never of medical records.
            'HR_MANAGER' => ['manage:employees', 'manage:attendance', 'manage:payroll', 'manage:leaves', 'read:doctor_planning'],
            'RECEPTIONIST' => ['create:patient', 'read:patient', 'update:patient', 'create:appointment', 'read:appointment', 'update:appointment', 'cancel:appointment', 'read:doctor_planning', 'read:beds'],
            'PATIENT' => ['read:patient', 'read:patient_clinical_profile', 'read:appointment', 'request:appointment', 'read:consultation', 'read:prescription', 'read:certificate', 'access:patient_portal'],
        ];

        // Every staff member has a personal HR space (clocking, leave, payslips).
        foreach ($rolePermissions as $roleName => $perms) {
            if (!in_array($roleName, ['SUPER_ADMIN', 'PATIENT'], true)) {
                $rolePermissions[$roleName][] = 'access:staff_space';
            }
        }

        foreach ($rolePermissions as $roleName => $perms) {
            $role = Role::where('name', $roleName)->first();
            if (!$role) continue;

            if ($perms === '*') {
                $role->permissions()->sync(Permission::all()->pluck('id'));
            } else {
                $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));
            }
        }
    }
}
