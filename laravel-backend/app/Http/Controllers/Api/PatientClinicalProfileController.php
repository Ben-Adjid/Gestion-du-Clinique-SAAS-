<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientClinicalProfileController extends Controller
{
    public function show(Request $request, Patient $patient)
    {
        if (!$this->canAccessPatient($request, $patient)) {
            return response()->json(['message' => 'Patient introuvable.'], 404);
        }

        return response()->json([
            'data' => $patient->load(['emergencyContacts', 'allergies', 'medicalHistory']),
        ]);
    }

    public function update(Request $request, Patient $patient)
    {
        if (!$this->canAccessPatient($request, $patient)) {
            return response()->json(['message' => 'Patient introuvable.'], 404);
        }

        $validated = $request->validate([
            'contacts' => 'sometimes|array|max:20',
            'contacts.*.emergency_contact_name' => 'required|string|max:255',
            'contacts.*.emergency_contact_phone' => 'required|string|max:30',
            'contacts.*.relationship' => 'nullable|string|max:100',
            'allergies' => 'sometimes|array|max:100',
            'allergies.*.allergen' => 'required|string|max:255',
            'allergies.*.severity' => 'required|in:LOW,MEDIUM,HIGH',
            'allergies.*.notes' => 'nullable|string|max:2000',
            'medical_history' => 'sometimes|array|max:100',
            'medical_history.*.condition' => 'required|string|max:255',
            'medical_history.*.start_date' => 'nullable|date',
            'medical_history.*.end_date' => 'nullable|date',
            'medical_history.*.notes' => 'nullable|string|max:2000',
        ]);

        foreach ($validated['medical_history'] ?? [] as $index => $entry) {
            if (($entry['start_date'] ?? null) && ($entry['end_date'] ?? null)
                && $entry['end_date'] < $entry['start_date']) {
                return response()->json([
                    'message' => 'La date de fin doit être postérieure ou égale à la date de début.',
                    'errors' => ["medical_history.{$index}.end_date" => ['Date de fin invalide.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($patient, $validated): void {
            foreach ([
                'contacts' => 'emergencyContacts',
                'allergies' => 'allergies',
                'medical_history' => 'medicalHistory',
            ] as $key => $relation) {
                if (array_key_exists($key, $validated)) {
                    $patient->{$relation}()->delete();
                    $patient->{$relation}()->createMany($validated[$key]);
                }
            }
        });

        return $this->show($request, $patient);
    }

    private function canAccessPatient(Request $request, Patient $patient): bool
    {
        $user = $request->user();
        if ($user->role?->name === 'PATIENT') {
            return (int) $patient->user_id === (int) $user->id;
        }

        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;
        return (int) $patient->clinic_id === (int) $clinicId;
    }
}
