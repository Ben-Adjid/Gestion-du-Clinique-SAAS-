<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\LabRequestController;
use App\Http\Controllers\Api\ImagingRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\V1\PerformanceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Auth routes (public)
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/signup', [AuthController::class, 'signup'])->middleware('throttle:5,1');
    Route::post('/auth/verify-totp', [AuthController::class, 'verifyTotp'])->middleware('throttle:5,1');
    Route::post('/auth/forgot-password', [\App\Http\Controllers\Api\PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [\App\Http\Controllers\Api\PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::post('/auth/2fa/setup', [AuthController::class, 'enableTotaSetup']);
        Route::post('/auth/2fa/confirm', [AuthController::class, 'confirmTotp']);
        Route::delete('/auth/2fa', [AuthController::class, 'disableTotp'])->middleware('throttle:5,1');
        Route::get('/me', [\App\Http\Controllers\Api\UserController::class, 'me']);
        Route::patch('/me', [\App\Http\Controllers\Api\UserController::class, 'updateMe']);

        // Clinic subscription: reachable even when the clinic is suspended, so it can pay and be reactivated.
        Route::middleware(['clinic.ensure:allow-suspended', 'totp.enrolled'])->group(function () {
            Route::get('/subscription', [\App\Http\Controllers\Api\MySubscriptionController::class, 'show'])
                ->middleware('permission:view:subscription');
            Route::post('/subscription/payments', [\App\Http\Controllers\Api\MySubscriptionController::class, 'declarePayment'])
                ->middleware(['permission:declare:subscription_payment', 'audit']);
        });

        // SaaS platform (super administrator), outside any clinic context
        Route::prefix('saas')->middleware('totp.enrolled')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\Saas\DashboardController::class, 'show'])
                ->middleware('permission:view:saas_dashboard');
            Route::get('/plans', [\App\Http\Controllers\Api\Saas\PlanController::class, 'index'])
                ->middleware('permission:manage:subscriptions');
            Route::put('/plans/{plan}', [\App\Http\Controllers\Api\Saas\PlanController::class, 'update'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::get('/clinics', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'index'])
                ->middleware('permission:manage:clinics');
            Route::post('/clinics', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'store'])
                ->middleware(['permission:manage:clinics', 'audit']);
            Route::get('/clinics/{clinic}', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'show'])
                ->middleware('permission:manage:clinics');
            Route::patch('/clinics/{clinic}', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'update'])
                ->middleware(['permission:manage:clinics', 'audit']);
            Route::post('/clinics/{clinic}/suspend', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'suspend'])
                ->middleware(['permission:manage:clinics', 'audit']);
            Route::post('/clinics/{clinic}/reactivate', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'reactivate'])
                ->middleware(['permission:manage:clinics', 'audit']);
            Route::post('/clinics/{clinic}/subscription/change-plan', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'changePlan'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::post('/clinics/{clinic}/subscription/cancel', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'cancelSubscription'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::post('/clinics/{clinic}/subscription/resume', [\App\Http\Controllers\Api\Saas\ClinicController::class, 'resumeSubscription'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::get('/invoices', [\App\Http\Controllers\Api\Saas\BillingController::class, 'invoices'])
                ->middleware('permission:manage:subscriptions');
            Route::post('/invoices/{invoice}/payments', [\App\Http\Controllers\Api\Saas\BillingController::class, 'recordPayment'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::get('/payments', [\App\Http\Controllers\Api\Saas\BillingController::class, 'payments'])
                ->middleware('permission:manage:subscriptions');
            Route::post('/payments/{payment}/confirm', [\App\Http\Controllers\Api\Saas\BillingController::class, 'confirm'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::post('/payments/{payment}/reject', [\App\Http\Controllers\Api\Saas\BillingController::class, 'reject'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
            Route::post('/billing/run', [\App\Http\Controllers\Api\Saas\BillingController::class, 'runBilling'])
                ->middleware(['permission:manage:subscriptions', 'audit']);
        });

        // Clinic routes (with isolation middleware)
        Route::middleware(['clinic.ensure', 'totp.enrolled'])->group(function () {
            Route::apiResource('clinics', ClinicController::class, ['only' => ['index', 'store', 'show', 'update']]);
            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:view:audit_logs');
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'show']);
            Route::get('/reports/overview', [ReportController::class, 'overview'])
                ->middleware(['permission:view:reports', 'plan:reports']);
            Route::get('/users/roles', [\App\Http\Controllers\Api\UserController::class, 'roles'])
                ->middleware('permission:manage:users');
            Route::apiResource('users', \App\Http\Controllers\Api\UserController::class)
                ->middleware(['permission:manage:users', 'audit']);

            // Patients
            Route::get('/patients/duplicates', [PatientController::class, 'duplicates'])
                ->middleware('permission:create:patient');
            Route::post('/patients/{patient}/photo', [PatientController::class, 'uploadPhoto'])
                ->middleware(['permission:update:patient', 'audit']);
            Route::get('/patients/{patient}/photo', [PatientController::class, 'photo'])
                ->middleware('permission:read:patient');
            Route::delete('/patients/{patient}/photo', [PatientController::class, 'deletePhoto'])
                ->middleware(['permission:update:patient', 'audit']);
            Route::apiResource('patients', PatientController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:patient')
                ->middlewareFor('store', 'permission:create:patient')
                ->middlewareFor('update', 'permission:update:patient')
                ->middlewareFor('destroy', 'permission:delete:patient')
                ->middleware('audit');
            Route::get('/patients/{patient}/clinical-profile', [\App\Http\Controllers\Api\PatientClinicalProfileController::class, 'show'])
                ->middleware('permission:read:patient_clinical_profile');
            Route::put('/patients/{patient}/clinical-profile', [\App\Http\Controllers\Api\PatientClinicalProfileController::class, 'update'])
                ->middleware(['permission:update:patient_clinical_profile', 'audit']);

            // Appointments
            Route::get('/appointments/doctors', [AppointmentController::class, 'doctors'])
                ->middleware('permission:read:appointment');
            Route::get('/appointments/queue', [AppointmentController::class, 'queue'])
                ->middleware('permission:read:appointment');
            Route::post('/appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
                ->middleware(['permission:update:appointment', 'audit']);
            Route::post('/appointments/{appointment}/call', [AppointmentController::class, 'call'])
                ->middleware(['permission:create:consultation', 'audit']);
            Route::apiResource('appointments', AppointmentController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:appointment')
                ->middlewareFor('store', 'permission:create:appointment')
                ->middlewareFor('update', 'permission:update:appointment')
                ->middlewareFor('destroy', 'permission:cancel:appointment')
                ->middleware('audit');
            Route::get('/appointment-requests', [\App\Http\Controllers\Api\AppointmentRequestController::class, 'index'])
                ->middleware('permission:read:appointment');
            Route::post('/appointment-requests', [\App\Http\Controllers\Api\AppointmentRequestController::class, 'store'])
                ->middleware(['permission:request:appointment', 'audit']);
            Route::post('/appointment-requests/{appointmentRequest}/approve', [\App\Http\Controllers\Api\AppointmentRequestController::class, 'approve'])
                ->middleware(['permission:update:appointment', 'audit']);
            Route::post('/appointment-requests/{appointmentRequest}/reject', [\App\Http\Controllers\Api\AppointmentRequestController::class, 'reject'])
                ->middleware(['permission:update:appointment', 'audit']);

            // Consultations
            Route::get('/cim10', [ConsultationController::class, 'cim10'])
                ->middleware('permission:create:consultation');
            Route::get('/certificates', [\App\Http\Controllers\Api\MedicalCertificateController::class, 'index'])
                ->middleware('permission:read:certificate');
            Route::post('/certificates', [\App\Http\Controllers\Api\MedicalCertificateController::class, 'store'])
                ->middleware(['permission:create:certificate', 'audit']);
            Route::get('/certificates/{certificate}', [\App\Http\Controllers\Api\MedicalCertificateController::class, 'show'])
                ->middleware(['permission:read:certificate', 'audit']);
            Route::post('/certificates/{certificate}/cancel', [\App\Http\Controllers\Api\MedicalCertificateController::class, 'cancel'])
                ->middleware(['permission:create:certificate', 'audit']);
            Route::post('/consultations/{consultation}/sign', [ConsultationController::class, 'sign'])
                ->middleware(['permission:sign:consultation', 'audit']);
            Route::apiResource('consultations', ConsultationController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:consultation')
                ->middlewareFor('store', 'permission:create:consultation')
                ->middlewareFor(['update', 'destroy'], 'permission:update:consultation')
                ->middleware('audit');

            // Prescriptions
            Route::get('/medications', [\App\Http\Controllers\Api\PrescriptionController::class, 'medications'])
                ->middleware('permission:read:prescription');
            Route::post('/prescriptions/{prescription}/sign', [\App\Http\Controllers\Api\PrescriptionController::class, 'sign'])
                ->middleware(['permission:sign:prescription', 'audit']);

            // Pharmacy: catalogue, stock by lot (FEFO), dispensing and counter sales
            Route::controller(\App\Http\Controllers\Api\PharmacyController::class)->group(function () {
                Route::post('/medications', 'addMedication')->middleware(['permission:manage:pharmacy_stock', 'audit']);
                Route::patch('/medications/{medication}', 'updateMedication')->middleware(['permission:manage:pharmacy_stock', 'audit']);
                Route::get('/pharmacy/stock', 'stock')->middleware('permission:manage:pharmacy_stock');
                Route::post('/pharmacy/receptions', 'receive')->middleware(['permission:manage:pharmacy_stock', 'audit']);
                Route::post('/pharmacy/adjustments', 'adjust')->middleware(['permission:manage:pharmacy_stock', 'audit']);
                Route::get('/pharmacy/movements', 'movements')->middleware('permission:manage:pharmacy_stock');
                Route::get('/pharmacy/queue', 'queue')->middleware('permission:dispense:medication');
                Route::post('/prescriptions/{prescription}/dispense', 'dispense')->middleware(['permission:dispense:medication', 'audit']);
                Route::get('/pharmacy/sales', 'sales')->middleware('permission:dispense:medication');
                Route::post('/pharmacy/sales', 'counterSale')->middleware(['permission:dispense:medication', 'audit']);
            });
            Route::apiResource('prescriptions', PrescriptionController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:prescription')
                ->middlewareFor('store', 'permission:create:prescription')
                ->middlewareFor(['update', 'destroy'], 'permission:update:prescription')
                ->middleware('audit');

            // Lab Requests (Pro and Enterprise plans)
            Route::middleware('plan:lab')->group(function () {
            Route::get('/lab-exams', [LabRequestController::class, 'exams'])
                ->middleware('permission:read:lab_request');
            Route::post('/lab-exams', [LabRequestController::class, 'addExam'])
                ->middleware(['permission:manage:exams', 'audit']);
            Route::patch('/lab-exams/{labExam}', [LabRequestController::class, 'updateExam'])
                ->middleware(['permission:manage:exams', 'audit']);
            Route::post('/lab-requests/{labRequest}/collect', [LabRequestController::class, 'collect'])
                ->middleware(['permission:collect:lab_sample', 'audit']);
            Route::post('/lab-requests/{labRequest}/results', [LabRequestController::class, 'recordResult'])
                ->middleware(['permission:submit:lab_results', 'audit']);
            Route::post('/lab-requests/{labRequest}/sign-results', [LabRequestController::class, 'signResults'])
                ->middleware(['permission:sign:lab_results', 'audit']);
            Route::apiResource('lab-requests', LabRequestController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:lab_request')
                ->middlewareFor('store', 'permission:create:lab_request')
                ->middlewareFor('update', 'permission:update:lab_request')
                ->middlewareFor('destroy', 'permission:create:lab_request')
                ->middleware('audit');
            });

            // Imaging Requests (Pro and Enterprise plans)
            Route::middleware('plan:imaging')->group(function () {
            Route::get('/imaging-exams', [ImagingRequestController::class, 'exams'])
                ->middleware('permission:read:imaging');
            Route::post('/imaging-exams', [ImagingRequestController::class, 'addExam'])
                ->middleware(['permission:manage:exams', 'audit']);
            Route::patch('/imaging-exams/{imagingExam}', [ImagingRequestController::class, 'updateExam'])
                ->middleware(['permission:manage:exams', 'audit']);
            Route::post('/imaging-requests/{imagingRequest}/files', [ImagingRequestController::class, 'uploadFile'])
                ->middleware(['permission:upload:imaging', 'audit']);
            Route::get('/imaging-requests/{imagingRequest}/files/{file}', [ImagingRequestController::class, 'downloadFile'])
                ->middleware(['permission:read:imaging', 'audit']);
            Route::delete('/imaging-requests/{imagingRequest}/files/{file}', [ImagingRequestController::class, 'deleteFile'])
                ->middleware(['permission:upload:imaging', 'audit']);
            Route::post('/imaging-requests/{imagingRequest}/report', [ImagingRequestController::class, 'saveReport'])
                ->middleware(['permission:create:imaging_report', 'audit']);
            Route::post('/imaging-requests/{imagingRequest}/sign-report', [ImagingRequestController::class, 'signReport'])
                ->middleware(['permission:create:imaging_report', 'audit']);
            Route::apiResource('imaging-requests', ImagingRequestController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:imaging')
                ->middlewareFor('store', 'permission:create:imaging_request')
                ->middlewareFor('update', 'permission:update:imaging')
                ->middlewareFor('destroy', 'permission:create:imaging_request')
                ->middleware('audit');
            });

            // Cashier: services to bill, invoices, payments, daily closing and price list
            Route::get('/billing/pending', [InvoiceController::class, 'pending'])
                ->middleware('permission:create:invoice');
            Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
                ->middleware(['permission:update:invoice', 'audit']);
            Route::get('/cash/summary', [\App\Http\Controllers\Api\CashController::class, 'summary'])
                ->middleware('permission:manage:payment');
            Route::get('/cash/closings', [\App\Http\Controllers\Api\CashController::class, 'closings'])
                ->middleware('permission:manage:payment');
            Route::post('/cash/closings', [\App\Http\Controllers\Api\CashController::class, 'close'])
                ->middleware(['permission:manage:payment', 'audit']);
            Route::get('/tariffs', [\App\Http\Controllers\Api\TariffController::class, 'show'])
                ->middleware('permission:create:invoice');
            Route::put('/tariffs', [\App\Http\Controllers\Api\TariffController::class, 'update'])
                ->middleware(['permission:manage:tariffs', 'audit']);
            Route::apiResource('invoices', InvoiceController::class)
                ->middlewareFor(['index', 'show'], 'permission:read:invoice')
                ->middlewareFor('store', 'permission:create:invoice')
                ->middlewareFor(['update', 'destroy'], 'permission:update:invoice')
                ->middleware('audit');
            Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
                ->middleware(['permission:manage:payment', 'audit']);

            // Human resources (Enterprise plan): staff files, attendance, leave and payroll
            Route::middleware('plan:hr')->group(function () {
                Route::controller(\App\Http\Controllers\Api\Hr\EmployeeController::class)->group(function () {
                    Route::get('/hr/employees', 'index')->middleware('permission:manage:employees');
                    Route::get('/hr/employees/available-users', 'availableUsers')->middleware('permission:manage:employees');
                    Route::post('/hr/employees', 'store')->middleware(['permission:manage:employees', 'audit']);
                    Route::get('/hr/employees/{employee}', 'show')->middleware(['permission:manage:employees', 'audit']);
                    Route::patch('/hr/employees/{employee}', 'update')->middleware(['permission:manage:employees', 'audit']);
                    Route::post('/hr/employees/{employee}/terminate', 'terminate')->middleware(['permission:manage:employees', 'audit']);
                });
                Route::controller(\App\Http\Controllers\Api\Hr\AttendanceController::class)->group(function () {
                    Route::get('/hr/attendance', 'day')->middleware('permission:manage:attendance');
                    Route::post('/hr/attendance', 'record')->middleware(['permission:manage:attendance', 'audit']);
                    Route::get('/hr/attendance/report', 'report')->middleware('permission:manage:attendance');
                    Route::post('/me/attendance/clock', 'clock')->middleware(['permission:access:staff_space', 'audit']);
                });
                Route::controller(\App\Http\Controllers\Api\Hr\LeaveController::class)->group(function () {
                    Route::get('/hr/leaves', 'index')->middleware('permission:access:staff_space');
                    Route::post('/hr/leaves', 'store')->middleware(['permission:access:staff_space', 'audit']);
                    Route::post('/hr/leaves/{leave}/review', 'review')->middleware(['permission:manage:leaves', 'audit']);
                    Route::post('/hr/leaves/{leave}/cancel', 'cancel')->middleware(['permission:access:staff_space', 'audit']);
                });
                Route::controller(\App\Http\Controllers\Api\Hr\PayrollController::class)->group(function () {
                    Route::get('/hr/payroll', 'index')->middleware('permission:manage:payroll|approve:payroll');
                    Route::post('/hr/payroll/generate', 'generate')->middleware(['permission:manage:payroll', 'audit']);
                    Route::patch('/hr/payroll/{payroll}', 'update')->middleware(['permission:manage:payroll', 'audit']);
                    Route::post('/hr/payroll/approve', 'approve')->middleware(['permission:approve:payroll', 'audit']);
                    Route::post('/hr/payroll/{payroll}/pay', 'pay')->middleware(['permission:manage:payroll', 'audit']);
                    Route::get('/hr/payroll/{payroll}', 'show')->middleware(['permission:access:staff_space', 'audit']);
                });
                Route::get('/me/staff-space', [\App\Http\Controllers\Api\Hr\StaffSpaceController::class, 'show'])
                    ->middleware('permission:access:staff_space');
            });

            // Patient portal (Enterprise plan)
            Route::middleware(['plan:patient_portal', 'permission:access:patient_portal'])->prefix('portal')
                ->controller(\App\Http\Controllers\Api\PortalController::class)->group(function () {
                    Route::get('/summary', 'summary');
                    Route::get('/documents', 'documents');
                    Route::get('/lab-requests/{labRequest}', 'lab')->middleware('audit');
                    Route::get('/imaging-requests/{imagingRequest}', 'imaging')->middleware('audit');
                    Route::get('/invoices', 'invoices');
                    Route::get('/invoices/{invoice}', 'invoice');
                });

            // Nursing care, vital signs and medical record timeline
            Route::get('/care-tasks', [\App\Http\Controllers\Api\CareTaskController::class, 'index'])
                ->middleware('permission:read:care_task');
            Route::get('/care-tasks/nurses', [\App\Http\Controllers\Api\CareTaskController::class, 'nurses'])
                ->middleware('permission:create:care_task');
            Route::post('/consultations/{consultation}/tasks', [\App\Http\Controllers\Api\CareTaskController::class, 'store'])
                ->middleware(['permission:create:care_task', 'audit']);
            Route::patch('/care-tasks/{task}', [\App\Http\Controllers\Api\CareTaskController::class, 'update'])
                ->middleware(['permission:complete:care_task', 'audit']);
            Route::get('/patients/{patient}/vitals', [\App\Http\Controllers\Api\VitalSignController::class, 'index'])
                ->middleware(['permission:read:vitals', 'audit']);
            Route::post('/patients/{patient}/vitals', [\App\Http\Controllers\Api\VitalSignController::class, 'store'])
                ->middleware(['permission:record:vitals', 'audit']);
            Route::get('/patients/{patient}/timeline', [\App\Http\Controllers\Api\PatientTimelineController::class, 'show'])
                ->middleware(['permission:read:dpe', 'audit']);

            // Drug interactions
            Route::post('/prescriptions/check-interactions', [PrescriptionController::class, 'checkInteractions'])
                ->middleware('permission:create:prescription');
            Route::get('/drug-interactions', [\App\Http\Controllers\Api\DrugInteractionController::class, 'index'])
                ->middleware('permission:read:prescription');
            Route::post('/drug-interactions', [\App\Http\Controllers\Api\DrugInteractionController::class, 'store'])
                ->middleware(['permission:manage:pharmacy_stock', 'audit']);
            Route::delete('/drug-interactions/{drugInteraction}', [\App\Http\Controllers\Api\DrugInteractionController::class, 'destroy'])
                ->middleware(['permission:manage:pharmacy_stock', 'audit']);

            // Performance & Caching (PHASE 17)
            Route::get('/performance/cache-stats', [PerformanceController::class, 'cacheStats'])
                ->middleware('permission:view:reports');
            Route::post('/performance/flush-cache', [PerformanceController::class, 'flushCache'])
                ->middleware('permission:manage:users');
            Route::get('/performance/search-patients', [PerformanceController::class, 'searchPatients'])
                ->middleware('permission:read:patient');
            Route::get('/performance/appointments', [PerformanceController::class, 'lazyLoadAppointments'])
                ->middleware('permission:read:appointment');
        });
    });
});
