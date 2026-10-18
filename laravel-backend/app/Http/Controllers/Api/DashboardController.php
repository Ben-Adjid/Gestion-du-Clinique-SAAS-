<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Support\ModuleAccess;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->name;
        $clinicId = $request->attributes->get('clinic_id');
        $modules = ModuleAccess::forUser($user);
        $patientId = $user->patient?->id;

        $clinic = fn ($query) => $clinicId === null ? $query : $query->where('clinic_id', $clinicId);
        $todayFrom = now()->startOfDay();
        $todayTo = now()->endOfDay();

        $widgets = match ($role) {
            'SUPER_ADMIN' => [
                $this->widget('saas_mrr', 'Revenu mensuel récurrent', round(ClinicSubscription::query()->whereIn('status', ['ACTIVE', 'PAST_DUE'])->get()->sum(fn (ClinicSubscription $subscription) => $subscription->monthlyRecurringRevenue())), 'saas', '/saas', 'currency'),
                $this->widget('clinics_active', 'Cliniques actives', Clinic::where('status', 'ACTIVE')->count(), 'clinics', '/clinics'),
                $this->widget('clinics_trial', 'Cliniques en essai', ClinicSubscription::where('status', 'TRIAL')->count(), 'clinics', '/clinics?subscription_status=TRIAL'),
                $this->widget('saas_overdue', 'Factures d’abonnement en retard', SaasInvoice::where('status', 'OVERDUE')->count(), 'saas', '/saas/invoices?status=OVERDUE'),
                $this->widget('saas_pending_payments', 'Paiements à valider', SaasPayment::where('status', 'PENDING')->count(), 'saas', '/saas/invoices?tab=payments'),
            ],
            'ADMIN_CLINIC' => [
                $this->widget('patients_total', 'Patients', $clinic(Patient::query())->count(), 'patients', '/patients'),
                $this->widget('appointments_today', 'Rendez-vous aujourd’hui', $clinic(Appointment::query())->whereBetween('appointment_datetime', [$todayFrom, $todayTo])->where('status', 'SCHEDULED')->count(), 'appointments', '/appointments'),
                $this->widget('invoices_outstanding_amount', 'Factures impayées', (float) $clinic(Invoice::query())->whereIn('status', ['ISSUED', 'OVERDUE'])->sum('total_amount'), 'invoices', '/invoices', 'currency'),
                $this->widget('payments_today', 'Encaissements du jour', (float) $clinic(Payment::query())->where('status', 'CONFIRMED')->whereBetween('payment_datetime', [$todayFrom, $todayTo])->sum('amount'), 'invoices', '/invoices', 'currency'),
                $this->widget('staff_active', 'Personnel actif', $clinic(User::query())->where('status', 'ACTIVE')->count(), 'users', '/users'),
            ],
            'DOCTOR' => [
                $this->widget('appointments_today', 'Mes rendez-vous aujourd’hui', $clinic(Appointment::query())->where('doctor_id', $user->id)->whereBetween('appointment_datetime', [$todayFrom, $todayTo])->where('status', 'SCHEDULED')->count(), 'appointments', '/appointments'),
                $this->widget('consultations_open', 'Consultations en cours', $clinic(Consultation::query())->where('doctor_id', $user->id)->whereIn('status', ['DRAFT', 'IN_PROGRESS'])->count(), 'consultations', '/consultations'),
                $this->widget('prescriptions_to_sign', 'Ordonnances à signer', $clinic(Prescription::query())->where('doctor_id', $user->id)->where('status', 'DRAFT')->count(), 'prescriptions', '/prescriptions'),
                $this->widget('lab_pending', 'Analyses en attente de résultats', $clinic(LabRequest::query())->where('doctor_id', $user->id)->whereIn('status', ['PENDING', 'RECEIVED', 'IN_PROGRESS', 'READY'])->count(), 'lab', '/lab-imaging?tab=lab'),
                $this->widget('imaging_pending', 'Examens d’imagerie à traiter', $clinic(ImagingRequest::query())->where('doctor_id', $user->id)->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(), 'imaging', '/lab-imaging?tab=imaging'),
            ],
            'NURSE' => [
                $this->widget('appointments_today', 'Rendez-vous aujourd’hui', $clinic(Appointment::query())->whereBetween('appointment_datetime', [$todayFrom, $todayTo])->where('status', 'SCHEDULED')->count(), 'appointments', '/appointments'),
                $this->widget('consultations_open', 'Consultations en cours', $clinic(Consultation::query())->whereIn('status', ['DRAFT', 'IN_PROGRESS'])->count(), 'consultations', '/consultations'),
                $this->widget('lab_to_send', 'Prélèvements à effectuer', $clinic(LabRequest::query())->where('status', 'PENDING')->count(), 'lab', '/lab-imaging?tab=lab'),
            ],
            'PATIENT' => $patientId ? [
                $this->widget('appointments_upcoming', 'Mes prochains rendez-vous', Appointment::query()->where('patient_id', $patientId)->where('status', 'SCHEDULED')->where('appointment_datetime', '>=', now())->count(), 'appointments', '/appointments'),
                $this->widget('consultations_signed', 'Mes consultations disponibles', Consultation::query()->where('patient_id', $patientId)->where('status', 'SIGNED')->count(), 'consultations', '/consultations'),
                $this->widget('prescriptions_available', 'Mes ordonnances disponibles', Prescription::query()->whereHas('consultation', fn ($query) => $query->where('patient_id', $patientId))->whereIn('status', ['SIGNED', 'DISPENSED'])->count(), 'prescriptions', '/prescriptions'),
            ] : [],
            'RECEPTIONIST' => [
                $this->widget('appointments_today', 'Rendez-vous aujourd’hui', $clinic(Appointment::query())->whereBetween('appointment_datetime', [$todayFrom, $todayTo])->where('status', 'SCHEDULED')->count(), 'appointments', '/appointments'),
                $this->widget('appointments_upcoming', 'Rendez-vous à venir', $clinic(Appointment::query())->where('appointment_datetime', '>', now())->where('status', 'SCHEDULED')->count(), 'appointments', '/appointments'),
                $this->widget('patients_today', 'Nouveaux patients aujourd’hui', $clinic(Patient::query())->whereBetween('created_at', [$todayFrom, $todayTo])->count(), 'patients', '/patients'),
            ],
            'CASHIER' => [
                $this->widget('invoices_outstanding_count', 'Factures impayées', $clinic(Invoice::query())->whereIn('status', ['ISSUED', 'OVERDUE'])->count(), 'invoices', '/invoices'),
                $this->widget('invoices_overdue', 'Factures en retard', $clinic(Invoice::query())->where('status', 'OVERDUE')->count(), 'invoices', '/invoices'),
                $this->widget('payments_today', 'Encaissements du jour', (float) $clinic(Payment::query())->where('status', 'CONFIRMED')->whereBetween('payment_datetime', [$todayFrom, $todayTo])->sum('amount'), 'invoices', '/invoices', 'currency'),
            ],
            'PHARMACIST' => [
                $this->widget('prescriptions_to_dispense', 'Ordonnances à délivrer', $clinic(Prescription::query())->where('status', 'SIGNED')->count(), 'prescriptions', '/prescriptions'),
                $this->widget('prescriptions_dispensed_today', 'Ordonnances délivrées aujourd’hui', $clinic(Prescription::query())->where('status', 'DISPENSED')->whereBetween('updated_at', [$todayFrom, $todayTo])->count(), 'prescriptions', '/prescriptions'),
            ],
            'LABORATORY_TECHNICIAN' => [
                $this->widget('lab_in_progress', 'Analyses en cours', $clinic(LabRequest::query())->whereIn('status', ['RECEIVED', 'IN_PROGRESS'])->count(), 'lab', '/lab-imaging?tab=lab'),
                $this->widget('lab_ready', 'Résultats prêts à valider', $clinic(LabRequest::query())->where('status', 'READY')->count(), 'lab', '/lab-imaging?tab=lab'),
            ],
            'RADIOLOGIST' => [
                $this->widget('imaging_pending', 'Examens d’imagerie à traiter', $clinic(ImagingRequest::query())->whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(), 'imaging', '/lab-imaging?tab=imaging'),
                $this->widget('imaging_ready', 'Comptes rendus à signer', $clinic(ImagingRequest::query())->where('status', 'READY')->count(), 'imaging', '/lab-imaging?tab=imaging'),
            ],
            'HR_MANAGER' => [
                $this->widget('employees_active', 'Employés actifs', $clinic(Employee::query())->where('status', 'ACTIVE')->count(), 'employees', null),
            ],
            default => [],
        };

        $visible = array_values(array_filter(
            $widgets,
            fn (array $widget) => $widget['module'] === null || in_array($widget['module'], $modules, true),
        ));

        return response()->json([
            'data' => [
                'role' => $role,
                'widgets' => array_map(fn (array $widget) => collect($widget)->except('module')->all(), $visible),
            ],
        ]);
    }

    private function widget(string $key, string $label, int|float $value, string $module, ?string $href, string $format = 'number'): array
    {
        return compact('key', 'label', 'value', 'module', 'href', 'format');
    }
}
