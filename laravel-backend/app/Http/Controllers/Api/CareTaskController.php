<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationTask;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CareTaskController extends Controller
{
    private const RELATIONS = [
        'consultation:id,patient_id,doctor_id,consultation_datetime',
        'consultation.patient:id,first_name,last_name,patient_unique_id',
        'consultation.doctor:id,name',
        'assignee:id,name',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $clinicId = $request->attributes->get('clinic_id');
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['PENDING', 'IN_PROGRESS', 'COMPLETED'])],
        ]);

        $tasks = ConsultationTask::query()
            ->whereHas('consultation', fn ($consultations) => $consultations
                ->when($clinicId !== null, fn ($query) => $query->where('clinic_id', $clinicId))
                ->when($user->role?->name === 'DOCTOR', fn ($query) => $query->where('doctor_id', $user->id)))
            ->when($user->role?->name === 'NURSE', fn ($query) => $query->where('assigned_to', $user->id))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->with(self::RELATIONS)
            ->orderByRaw("CASE status WHEN 'PENDING' THEN 0 WHEN 'IN_PROGRESS' THEN 1 ELSE 2 END")
            ->orderBy('due_date')
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $tasks]);
    }

    public function nurses(Request $request)
    {
        $clinicId = $request->attributes->get('clinic_id');
        if ($clinicId === null) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->activeNurses((int) $clinicId)->get(['id', 'name'])]);
    }

    public function store(Request $request, Consultation $consultation)
    {
        $user = $request->user();
        if (!$this->inClinic($request, $consultation->clinic_id)) {
            return response()->json(['message' => 'Consultation introuvable.'], 404);
        }
        if ($user->role?->name !== 'DOCTOR' || (int) $consultation->doctor_id !== (int) $user->id) {
            return response()->json(['message' => 'Seul le médecin de la consultation peut prescrire une tâche de soins.'], 403);
        }

        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::in($this->activeNurses((int) $consultation->clinic_id)->pluck('id')->all())],
            'task_description' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date', 'after_or_equal:now'],
        ], [
            'assigned_to.in' => 'Choisissez un infirmier actif de cette clinique.',
        ]);

        $task = ConsultationTask::create([
            ...$validated,
            'consultation_id' => $consultation->id,
            'created_by' => $user->id,
            'status' => 'PENDING',
        ]);

        UserNotification::create([
            'clinic_id' => $consultation->clinic_id,
            'user_id' => $validated['assigned_to'],
            'type' => 'CARE_TASK',
            'title' => 'Nouvelle tâche de soins',
            'message' => $validated['task_description'],
        ]);

        return response()->json(['data' => $task->load(self::RELATIONS)], 201);
    }

    public function update(Request $request, ConsultationTask $task)
    {
        $task->load('consultation');
        if (!$this->inClinic($request, $task->consultation->clinic_id)) {
            return response()->json(['message' => 'Tâche introuvable.'], 404);
        }
        if ((int) $task->assigned_to !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul l’infirmier assigné peut mettre à jour cette tâche.'], 403);
        }
        if ($task->status === 'COMPLETED') {
            return response()->json(['message' => 'Cette tâche est déjà terminée.'], 422);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['IN_PROGRESS', 'COMPLETED'])],
        ]);

        $task->update([
            'status' => $validated['status'],
            ...($validated['status'] === 'COMPLETED' ? ['completed_by' => $request->user()->id, 'completed_at' => now()] : []),
        ]);

        if ($validated['status'] === 'COMPLETED') {
            UserNotification::create([
                'clinic_id' => $task->consultation->clinic_id,
                'user_id' => $task->consultation->doctor_id,
                'type' => 'CARE_TASK',
                'title' => 'Tâche de soins réalisée',
                'message' => $task->task_description,
            ]);
        }

        return response()->json(['data' => $task->fresh()->load(self::RELATIONS)]);
    }

    private function activeNurses(int $clinicId)
    {
        return User::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->where('name', 'NURSE'))
            ->orderBy('name');
    }

    private function inClinic(Request $request, int $clinicId): bool
    {
        $current = $request->attributes->get('clinic_id');

        return $current === null || (int) $current === $clinicId;
    }
}
