<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;

        $requests = AppointmentRequest::query()
            ->where('clinic_id', $clinicId)
            ->when(
                $user->role?->name === 'PATIENT',
                fn ($query) => $query->where('patient_id', $user->patient?->id ?? 0),
            )
            ->with([
                'patient:id,first_name,last_name,patient_unique_id',
                'doctor:id,name',
                'appointment:id,status,appointment_datetime',
                'reviewer:id,name',
            ])
            ->latest()
            ->get();

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;
        $patient = $user->role?->name === 'PATIENT' ? $user->patient : null;
        if (!$clinicId || !$patient || (int) $patient->clinic_id !== (int) $clinicId) {
            return response()->json(['message' => 'A patient account linked to a record in this clinic is required.'], 403);
        }

        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('clinic_id', $clinicId)],
            'requested_date' => ['required', 'date_format:Y-m-d'],
            'requested_time' => ['required', 'date_format:H:i'],
        ]);
        $doctor = User::query()->with('role')->findOrFail($validated['doctor_id']);
        if ($doctor->role?->name !== 'DOCTOR' || $doctor->status !== 'ACTIVE') {
            return response()->json(['message' => 'Select an active doctor from this clinic.'], 422);
        }

        $requestedAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['requested_date'].' '.$validated['requested_time'],
        );
        if ($requestedAt->isPast()) {
            return response()->json(['message' => 'A requested appointment cannot be in the past.'], 422);
        }
        $alreadyPending = AppointmentRequest::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'PENDING')
            ->where('requested_datetime', $requestedAt)
            ->exists();
        if ($alreadyPending) {
            return response()->json(['message' => 'A request for this time is already pending.'], 422);
        }

        $appointmentRequest = AppointmentRequest::create([
            'clinic_id' => $clinicId,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'requested_datetime' => $requestedAt,
            'status' => 'PENDING',
        ]);

        UserNotification::create([
            'clinic_id' => $clinicId,
            'user_id' => $doctor->id,
            'type' => 'APPOINTMENT',
            'title' => 'Demande de rendez-vous',
            'message' => 'Un patient a demandé un rendez-vous à confirmer.',
        ]);

        return response()->json([
            'data' => $appointmentRequest->load([
                'patient:id,first_name,last_name,patient_unique_id',
                'doctor:id,name',
            ]),
        ], 201);
    }

    public function approve(Request $request, AppointmentRequest $appointmentRequest)
    {
        if (!$this->inClinic($request, $appointmentRequest)) {
            return response()->json(['message' => 'Appointment request not found.'], 404);
        }
        if ($appointmentRequest->status !== 'PENDING') {
            return response()->json(['message' => 'This appointment request has already been reviewed.'], 422);
        }

        $appointment = DB::transaction(function () use ($request, $appointmentRequest) {
            $lockedRequest = AppointmentRequest::query()->lockForUpdate()->findOrFail($appointmentRequest->id);
            if ($lockedRequest->status !== 'PENDING') {
                abort(422, 'This appointment request has already been reviewed.');
            }
            $doctor = User::query()->with('role')->lockForUpdate()->findOrFail($lockedRequest->doctor_id);
            if ($doctor->status !== 'ACTIVE' || $doctor->role?->name !== 'DOCTOR') {
                abort(422, 'The requested doctor is no longer active.');
            }
            if ($lockedRequest->requested_datetime->isPast()) {
                abort(422, 'The requested time has already passed.');
            }

            $appointment = Appointment::query()
                ->where('clinic_id', $lockedRequest->clinic_id)
                ->where('status', 'SCHEDULED')
                ->where(function ($query) use ($lockedRequest) {
                    $query->where('doctor_id', $lockedRequest->doctor_id)
                        ->orWhere('patient_id', $lockedRequest->patient_id);
                })
                ->where('appointment_datetime', '>', $lockedRequest->requested_datetime->copy()->subMinutes((int) config('clinic.appointment_slot_minutes')))
                ->where('appointment_datetime', '<', $lockedRequest->requested_datetime->copy()->addMinutes((int) config('clinic.appointment_slot_minutes')))
                ->exists();
            if ($appointment) {
                abort(422, 'The requested time is no longer available. Contact the patient to choose another time.');
            }

            $appointment = Appointment::create([
                'clinic_id' => $lockedRequest->clinic_id,
                'patient_id' => $lockedRequest->patient_id,
                'doctor_id' => $lockedRequest->doctor_id,
                'appointment_datetime' => $lockedRequest->requested_datetime,
                'status' => 'SCHEDULED',
            ]);
            $lockedRequest->update([
                'status' => 'APPROVED',
                'appointment_id' => $appointment->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $appointment;
        });

        UserNotification::create([
            'clinic_id' => $appointmentRequest->clinic_id,
            'user_id' => $appointmentRequest->patient->user_id,
            'type' => 'APPOINTMENT',
            'title' => 'Rendez-vous confirmé',
            'message' => 'Votre demande de rendez-vous a été acceptée.',
        ]);

        return response()->json([
            'data' => $appointmentRequest->fresh()->load([
                'patient:id,first_name,last_name,patient_unique_id',
                'doctor:id,name',
                'appointment:id,status,appointment_datetime',
                'reviewer:id,name',
            ]),
        ]);
    }

    public function reject(Request $request, AppointmentRequest $appointmentRequest)
    {
        if (!$this->inClinic($request, $appointmentRequest)) {
            return response()->json(['message' => 'Appointment request not found.'], 404);
        }
        if ($appointmentRequest->status !== 'PENDING') {
            return response()->json(['message' => 'This appointment request has already been reviewed.'], 422);
        }
        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        $appointmentRequest->update([
            'status' => 'REJECTED',
            'review_note' => $validated['review_note'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        if ($appointmentRequest->patient?->user_id) {
            UserNotification::create([
                'clinic_id' => $appointmentRequest->clinic_id,
                'user_id' => $appointmentRequest->patient->user_id,
                'type' => 'APPOINTMENT',
                'title' => 'Demande de rendez-vous refusée',
                'message' => 'Votre demande ne peut pas être acceptée. Motif : '.$validated['review_note'],
            ]);
        }

        return response()->json(['data' => $appointmentRequest->fresh()->load(['patient', 'doctor', 'reviewer'])]);
    }

    private function inClinic(Request $request, AppointmentRequest $appointmentRequest): bool
    {
        $user = $request->user();
        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;

        return (int) $appointmentRequest->clinic_id === (int) $clinicId
            && ($user->role?->name !== 'PATIENT'
                || (int) $appointmentRequest->patient_id === (int) ($user->patient?->id ?? 0));
    }
}
