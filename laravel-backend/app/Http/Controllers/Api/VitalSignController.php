<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\VitalSign;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VitalSignController extends Controller
{
    private const MEASURES = ['systolic_bp', 'diastolic_bp', 'heart_rate', 'respiratory_rate', 'temperature', 'spo2', 'weight_kg', 'height_cm'];

    public function index(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found.'], 404);
        }

        $vitals = VitalSign::query()
            ->where('patient_id', $patient->id)
            ->with('recorder:id,name')
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $vitals]);
    }

    public function store(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found.'], 404);
        }

        $validated = $request->validate([
            'systolic_bp' => ['nullable', 'integer', 'between:50,260'],
            'diastolic_bp' => ['nullable', 'integer', 'between:30,160'],
            'heart_rate' => ['nullable', 'integer', 'between:20,250'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,80'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'spo2' => ['nullable', 'integer', 'between:50,100'],
            'weight_kg' => ['nullable', 'numeric', 'between:0.5,400'],
            'height_cm' => ['nullable', 'numeric', 'between:30,250'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
            'consultation_id' => [
                'nullable',
                'integer',
                Rule::exists('consultations', 'id')->where('patient_id', $patient->id)->where('clinic_id', $patient->clinic_id),
            ],
        ]);

        if (collect(self::MEASURES)->every(fn (string $measure) => ($validated[$measure] ?? null) === null)) {
            throw ValidationException::withMessages(['systolic_bp' => 'Saisissez au moins une mesure.']);
        }
        if (isset($validated['systolic_bp'], $validated['diastolic_bp']) && $validated['diastolic_bp'] >= $validated['systolic_bp']) {
            throw ValidationException::withMessages(['diastolic_bp' => 'La tension diastolique doit être inférieure à la systolique.']);
        }

        $vital = VitalSign::create([
            ...$validated,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'recorded_by' => $request->user()->id,
            'recorded_at' => $validated['recorded_at'] ?? now(),
        ]);

        if ($vital->alerts !== []) {
            // Warn the doctors expecting this patient today.
            $doctorIds = \App\Models\Appointment::query()
                ->where('patient_id', $patient->id)
                ->where('status', 'SCHEDULED')
                ->where('appointment_datetime', '>=', today())
                ->where('appointment_datetime', '<', today()->addDay())
                ->pluck('doctor_id')
                ->unique()
                ->reject(fn ($doctorId) => (int) $doctorId === (int) $request->user()->id);
            foreach ($doctorIds as $doctorId) {
                \App\Models\UserNotification::create([
                    'clinic_id' => $patient->clinic_id,
                    'user_id' => $doctorId,
                    'type' => 'VITALS_ALERT',
                    'title' => 'Constantes anormales',
                    'message' => "{$patient->first_name} {$patient->last_name} : ".implode(', ', $vital->alerts).'.',
                ]);
            }
        }

        return response()->json(['data' => $vital->load('recorder:id,name')], 201);
    }

    private function inClinic(Request $request, Patient $patient): bool
    {
        $current = $request->attributes->get('clinic_id');

        return $current === null || (int) $current === (int) $patient->clinic_id;
    }
}
