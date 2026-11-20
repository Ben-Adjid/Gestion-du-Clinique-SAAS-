<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\VitalSign;
use App\Models\UserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,patient_unique_id,phone,date_of_birth,gender', 'doctor:id,name'];

    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'doctor_id' => ['sometimes', 'nullable', 'integer'],
            'patient_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:SCHEDULED,CANCELLED,COMPLETED,NO_SHOW'],
        ]);
        if (isset($filters['from'], $filters['to']) && Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) > 62) {
            return response()->json(['message' => 'La période affichée ne peut pas dépasser deux mois.'], 422);
        }

        $appointments = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->when(
                $request->user()->role?->name === 'PATIENT',
                fn ($query) => $query->where('patient_id', $request->user()->patient?->id ?? 0),
            )
            ->with(self::RELATIONS)
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('appointment_datetime', '>=', "{$from} 00:00:00"))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('appointment_datetime', '<=', "{$to} 23:59:59"))
            ->when($filters['doctor_id'] ?? null, fn ($query, $doctorId) => $query->where('doctor_id', $doctorId))
            ->when($filters['patient_id'] ?? null, fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('appointment_datetime')
            ->limit(1000)
            ->get();

        return response()->json(['data' => $appointments]);
    }

    /**
     * Waiting room of the day: patients who arrived, in arrival order, with waiting
     * time and whether the nurse already took their vital signs.
     */
    public function queue(Request $request)
    {
        if ($request->user()->role?->name === 'PATIENT') {
            return response()->json(['message' => 'Vous n’avez pas l’autorisation d’effectuer cette action.'], 403);
        }
        $clinicId = $this->clinicId($request);
        $filters = $request->validate(['doctor_id' => ['sometimes', 'nullable', 'integer']]);
        if ($request->user()->role?->name === 'DOCTOR') {
            $filters['doctor_id'] = $request->user()->id;
        }

        $appointments = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('appointment_datetime', '>=', today())
            ->where('appointment_datetime', '<', today()->addDay())
            ->when($filters['doctor_id'] ?? null, fn ($query, $doctorId) => $query->where('doctor_id', $doctorId))
            ->with(self::RELATIONS)
            ->orderByRaw('checked_in_at IS NULL')
            ->orderBy('checked_in_at')
            ->orderBy('appointment_datetime')
            ->get();

        $vitalsToday = VitalSign::query()
            ->where('clinic_id', $clinicId)
            ->whereIn('patient_id', $appointments->pluck('patient_id'))
            ->where('recorded_at', '>=', today())
            ->orderBy('recorded_at')
            ->get()
            ->keyBy('patient_id');
        $consultations = Consultation::query()
            ->whereIn('appointment_id', $appointments->pluck('id'))
            ->pluck('id', 'appointment_id');

        $data = $appointments->map(function (Appointment $appointment) use ($vitalsToday, $consultations) {
            $vitals = $vitalsToday->get($appointment->patient_id);
            $waitingSince = $appointment->checked_in_at;
            $waitingUntil = $appointment->called_at ?? now();

            return [
                ...$appointment->toArray(),
                'stage' => match (true) {
                    $appointment->status !== 'SCHEDULED' => $appointment->status,
                    $appointment->called_at !== null => 'IN_CONSULTATION',
                    $appointment->checked_in_at !== null => 'WAITING',
                    default => 'EXPECTED',
                },
                'waiting_minutes' => $waitingSince ? (int) $waitingSince->diffInMinutes($waitingUntil) : null,
                'vitals' => $vitals ? [
                    'recorded_at' => $vitals->recorded_at,
                    'temperature' => $vitals->temperature,
                    'systolic_bp' => $vitals->systolic_bp,
                    'diastolic_bp' => $vitals->diastolic_bp,
                    'spo2' => $vitals->spo2,
                    'alerts' => $vitals->alerts,
                ] : null,
                'consultation_id' => $consultations->get($appointment->id),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function checkIn(Request $request, Appointment $appointment)
    {
        if ((int) $appointment->clinic_id !== (int) $this->clinicId($request)) {
            return response()->json(['message' => 'Rendez-vous introuvable.'], 404);
        }
        if ($appointment->status !== 'SCHEDULED') {
            return response()->json(['message' => 'Ce rendez-vous est clôturé.'], 422);
        }
        if (!$appointment->appointment_datetime->isToday()) {
            return response()->json(['message' => 'L’arrivée ne peut être enregistrée que le jour du rendez-vous.'], 422);
        }
        if ($appointment->checked_in_at !== null) {
            return response()->json(['message' => 'L’arrivée de ce patient est déjà enregistrée.'], 422);
        }
        $appointment->update(['checked_in_at' => now()]);
        $this->notifyDoctor($request, $appointment, 'Patient arrivé', "{$appointment->patient->first_name} {$appointment->patient->last_name} est en salle d’attente.");

        return response()->json(['data' => $appointment->fresh()->load(self::RELATIONS), 'message' => 'Arrivée enregistrée.']);
    }

    public function call(Request $request, Appointment $appointment)
    {
        if ((int) $appointment->clinic_id !== (int) $this->clinicId($request)) {
            return response()->json(['message' => 'Rendez-vous introuvable.'], 404);
        }
        if ((int) $appointment->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le médecin du rendez-vous peut appeler ce patient.'], 403);
        }
        if ($appointment->status !== 'SCHEDULED' || $appointment->checked_in_at === null) {
            return response()->json(['message' => 'Le patient doit d’abord être enregistré en salle d’attente.'], 422);
        }
        if ($appointment->called_at === null) {
            $appointment->update(['called_at' => now()]);
        }

        return response()->json(['data' => $appointment->fresh()->load(self::RELATIONS), 'message' => 'Patient appelé.']);
    }

    public function doctors(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }

        // Optional filters: the specialty chosen first, and the day to show the planning status for.
        $validated = $request->validate([
            'specialty' => ['sometimes', 'nullable', 'string', 'max:80'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => app(\App\Services\DoctorPlanning::class)
            ->doctorsFor($clinicId, $validated['date'] ?? null, $validated['specialty'] ?? null)]);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Choisissez une clinique avant de créer des rendez-vous.'], 422);
        }

        $walkIn = $request->boolean('is_walk_in');
        $validated = $request->validate([
            'patient_id' => "required|integer|exists:patients,id,clinic_id,{$clinicId},deleted_at,NULL",
            'doctor_id' => "required|integer|exists:users,id,clinic_id,{$clinicId}",
            'appointment_date' => [$walkIn ? 'prohibited' : 'required', 'date_format:Y-m-d'],
            'appointment_time' => [$walkIn ? 'prohibited' : 'required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:255'],
            'is_walk_in' => ['sometimes', 'boolean'],
        ], [
            'appointment_date.prohibited' => 'Un passage sans rendez-vous est enregistré à l’heure actuelle.',
            'appointment_time.prohibited' => 'Un passage sans rendez-vous est enregistré à l’heure actuelle.',
        ]);

        $doctor = User::with('role')->findOrFail($validated['doctor_id']);
        if ($doctor->role?->name !== 'DOCTOR' || $doctor->status !== 'ACTIVE') {
            return response()->json(['message' => 'Choisissez un médecin actif de cette clinique.'], 422);
        }

        // The planning protects the day: an unscheduled doctor cannot receive an appointment.
        $planning = app(\App\Services\DoctorPlanning::class);
        $planning->assertBookable($clinicId, $doctor->id, $walkIn ? now()->toDateString() : $validated['appointment_date']);
        $specialty = $request->string('specialty')->trim()->toString();
        $doctorSpecialty = \App\Models\DoctorProfile::query()->withoutGlobalScopes()
            ->where('user_id', $doctor->id)->value('specialty') ?? 'Médecine générale';
        if ($specialty !== '' && $doctorSpecialty !== $specialty) {
            return response()->json(['message' => 'Ce médecin ne correspond pas à la spécialité choisie.'], 422);
        }

        $appointmentDateTime = $walkIn
            ? now()->startOfMinute()
            : Carbon::createFromFormat('Y-m-d H:i', $validated['appointment_date'].' '.$validated['appointment_time']);
        if (!$walkIn && $appointmentDateTime->isPast()) {
            return response()->json(['message' => 'Un rendez-vous ne peut pas être fixé dans le passé.'], 422);
        }

        $appointment = DB::transaction(function () use ($clinicId, $validated, $appointmentDateTime, $walkIn) {
            User::whereKey($validated['doctor_id'])->lockForUpdate()->first();
            if ($walkIn) {
                $alreadyQueued = Appointment::query()
                    ->where('clinic_id', $clinicId)
                    ->where('patient_id', $validated['patient_id'])
                    ->where('status', 'SCHEDULED')
                    ->whereNotNull('checked_in_at')
                    ->where('appointment_datetime', '>=', today())
                    ->exists();
                if ($alreadyQueued) {
                    return 'Ce patient est déjà en salle d’attente aujourd’hui.';
                }
            } else {
                // A walk-in patient is slotted between appointments: no slot conflict check.
                $conflict = $this->conflict($clinicId, (int) $validated['doctor_id'], (int) $validated['patient_id'], $appointmentDateTime);
                if ($conflict !== null) {
                    return $conflict;
                }
            }

            return Appointment::create([
                'clinic_id' => $clinicId,
                'patient_id' => $validated['patient_id'],
                'doctor_id' => $validated['doctor_id'],
                'appointment_datetime' => $appointmentDateTime,
                'status' => 'SCHEDULED',
                'reason' => $validated['reason'] ?? null,
                'is_walk_in' => $walkIn,
                'checked_in_at' => $walkIn ? now() : null,
            ]);
        });
        if (is_string($appointment)) {
            return response()->json(['message' => $appointment], 422);
        }

        $this->notifyDoctor(
            $request,
            $appointment,
            $walkIn ? 'Patient sans rendez-vous' : 'Nouveau rendez-vous',
            $walkIn ? 'Un patient sans rendez-vous a été ajouté à votre salle d’attente.' : 'Un nouveau rendez-vous vous a été attribué.',
        );

        return response()->json([
            'data' => $appointment->load(self::RELATIONS),
        ], 201);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $isPatientOutsideOwnCase = $request->user()->role?->name === 'PATIENT'
            && (int) $appointment->patient_id !== (int) ($request->user()->patient?->id ?? 0);
        if ((int) $appointment->clinic_id !== (int) $this->clinicId($request) || $isPatientOutsideOwnCase) {
            return response()->json(['message' => 'Rendez-vous introuvable.'], 404);
        }

        return response()->json([
            'data' => $appointment->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, Appointment $appointment)
    {
        if ((int) $appointment->clinic_id !== (int) $this->clinicId($request)) {
            return response()->json(['message' => 'Rendez-vous introuvable.'], 404);
        }

        $validated = $request->validate([
            'appointment_date' => 'sometimes|required|date_format:Y-m-d',
            'appointment_time' => 'sometimes|required|date_format:H:i',
            'status' => 'sometimes|in:CANCELLED,COMPLETED,NO_SHOW',
            'cancellation_reason' => 'required_if:status,CANCELLED|nullable|string|max:2000',
            'reason' => 'sometimes|nullable|string|max:255',
        ]);

        if ($appointment->status !== 'SCHEDULED') {
            return response()->json(['message' => 'Ce rendez-vous est clôturé et ne peut plus être modifié.'], 422);
        }
        if (in_array($validated['status'] ?? null, ['COMPLETED', 'NO_SHOW'], true) && $appointment->appointment_datetime->isFuture()) {
            return response()->json(['message' => 'Un rendez-vous à venir ne peut pas être marqué terminé ou absent.'], 422);
        }

        $rescheduled = isset($validated['appointment_date']) || isset($validated['appointment_time']);
        if ($rescheduled && isset($validated['status'])) {
            return response()->json(['message' => 'Reportez ou clôturez le rendez-vous, mais pas les deux à la fois.'], 422);
        }

        if ($rescheduled) {
            $date = $validated['appointment_date'] ?? $appointment->appointment_date;
            $time = $validated['appointment_time'] ?? $appointment->appointment_time;
            $appointmentDateTime = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}");
            if ($appointmentDateTime->isPast()) {
                return response()->json(['message' => 'Un rendez-vous ne peut pas être fixé dans le passé.'], 422);
            }
            $validated['appointment_datetime'] = $appointmentDateTime;
            $validated['checked_in_at'] = null;
            $validated['called_at'] = null;
            unset($validated['appointment_date'], $validated['appointment_time']);
        }

        $result = DB::transaction(function () use ($appointment, $validated, $rescheduled) {
            if ($rescheduled) {
                User::whereKey($appointment->doctor_id)->lockForUpdate()->first();
                $conflict = $this->conflict(
                    (int) $appointment->clinic_id,
                    (int) $appointment->doctor_id,
                    (int) $appointment->patient_id,
                    $validated['appointment_datetime'],
                    $appointment->id,
                );
                if ($conflict !== null) {
                    return $conflict;
                }
            }
            $appointment->update($validated);

            return null;
        });
        if ($result !== null) {
            return response()->json(['message' => $result], 422);
        }
        if ($appointment->wasChanged() && (int) $request->user()->id !== (int) $appointment->doctor_id) {
            UserNotification::create([
                'clinic_id' => $appointment->clinic_id,
                'user_id' => $appointment->doctor_id,
                'type' => 'APPOINTMENT',
                'title' => 'Rendez-vous mis à jour',
                'message' => 'Un rendez-vous de votre agenda a été modifié.',
            ]);
        }

        return response()->json([
            'data' => $appointment->fresh()->load(self::RELATIONS),
        ]);
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        if ((int) $appointment->clinic_id !== (int) $this->clinicId($request)) {
            return response()->json(['message' => 'Rendez-vous introuvable.'], 404);
        }

        if ($appointment->status !== 'SCHEDULED') {
            return response()->json(['message' => 'Ce rendez-vous est déjà clôturé.'], 422);
        }
        $validated = $request->validate(['cancellation_reason' => 'required|string|max:2000']);
        $appointment->update(['status' => 'CANCELLED', 'cancellation_reason' => $validated['cancellation_reason']]);

        return response()->json([
            'data' => $appointment->fresh()->load(self::RELATIONS),
        ]);
    }

    private function conflict(int $clinicId, int $doctorId, int $patientId, Carbon $start, ?int $ignoreId = null): ?string
    {
        $slot = (int) config('clinic.appointment_slot_minutes');
        $overlapping = fn () => Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'SCHEDULED')
            ->where('appointment_datetime', '>', $start->copy()->subMinutes($slot))
            ->where('appointment_datetime', '<', $start->copy()->addMinutes($slot))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId));

        if ($overlapping()->where('doctor_id', $doctorId)->exists()) {
            return "Le médecin a déjà un rendez-vous sur ce créneau ({$slot} minutes).";
        }
        if ($overlapping()->where('patient_id', $patientId)->exists()) {
            return "Le patient a déjà un rendez-vous sur ce créneau ({$slot} minutes).";
        }

        return null;
    }

    private function notifyDoctor(Request $request, Appointment $appointment, string $title, string $message): void
    {
        if ((int) $request->user()->id === (int) $appointment->doctor_id) {
            return;
        }
        UserNotification::create([
            'clinic_id' => $appointment->clinic_id,
            'user_id' => $appointment->doctor_id,
            'type' => 'APPOINTMENT',
            'title' => $title,
            'message' => $message,
        ]);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
