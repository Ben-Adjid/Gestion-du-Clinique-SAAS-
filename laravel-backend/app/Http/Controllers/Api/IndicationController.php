<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\SurgicalIndication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Surgical indication: a doctor requests it, the head of the specialty validates it, the anesthesia
 * assesses it. Only a complete indication allows an operation to be scheduled.
 */
class IndicationController extends Controller
{
    /** Specialty code of the anesthesia-reanimation doctors, who run the pre-anesthesia assessment. */
    public const ANESTHESIA = 'ANESTHESIE_REA';

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $user = $request->user();
        if ($user->role?->name !== 'DOCTOR') {
            return response()->json(['message' => 'Seul un médecin peut demander une indication opératoire.'], 403);
        }
        $specialty = $user->doctorProfile?->specialtyRef;
        if ($specialty === null) {
            return response()->json(['message' => 'Votre spécialité doit être renseignée dans vos paramètres avant de demander une indication.'], 422);
        }

        $validated = $request->validate([
            'operation_type' => ['required', 'string', 'max:150'],
            'urgency' => ['required', 'string', Rule::in(SurgicalIndication::URGENCIES)],
            'motif' => ['required', 'string', 'min:20', 'max:4000'],
            'consultation_id' => ['nullable', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $user->clinic_id)],
        ], ['motif.min' => 'Décrivez l’indication opératoire (20 caractères minimum).']);

        $indication = SurgicalIndication::query()->create([
            ...$validated,
            'clinic_id' => $user->clinic_id,
            'patient_id' => $patient->id,
            'requested_by' => $user->id,
            'specialty_id' => $specialty->id,
            'status' => 'PENDING_HEAD',
        ]);

        return response()->json(['data' => $this->present($indication), 'message' => 'Indication opératoire transmise au chef de service.'], 201);
    }

    /** Requests waiting for the user: the head of a specialty sees its own, the anesthesia its assessments. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->doctorProfile;
        $query = SurgicalIndication::query()->with(['patient:id,first_name,last_name,patient_unique_id', 'specialty:id,label', 'requester:id,name'])->latest();

        if ($user->role?->name === 'DOCTOR' && $profile?->is_head) {
            $query->where('specialty_id', $profile->specialty_id);
        } elseif ($user->role?->name === 'DOCTOR' && $profile?->specialtyRef?->code === self::ANESTHESIA) {
            $query->where('status', 'APPROVED');
        } elseif ($user->role?->name === 'DOCTOR') {
            $query->where('requested_by', $user->id);
        } else {
            return response()->json(['message' => 'Les indications opératoires sont réservées aux médecins.'], 403);
        }

        return response()->json(['data' => $query->limit(100)->get()->map(fn (SurgicalIndication $item) => $this->present($item))->values()]);
    }

    /** The head of the specialty validates or refuses the indication (a reason is required to refuse). */
    public function decide(Request $request, SurgicalIndication $indication): JsonResponse
    {
        $user = $request->user();
        $profile = $user->doctorProfile;
        if ($user->role?->name !== 'DOCTOR' || !$profile?->is_head || (int) $profile->specialty_id !== (int) $indication->specialty_id) {
            return response()->json(['message' => 'Seul le chef de cette spécialité peut valider ou refuser cette indication.'], 403);
        }
        if ($indication->status !== 'PENDING_HEAD') {
            return response()->json(['message' => 'Cette indication a déjà été décidée.'], 422);
        }
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REFUSE'])],
            'reason' => ['required_if:decision,REFUSE', 'nullable', 'string', 'min:5', 'max:255'],
        ], ['reason.required_if' => 'Indiquez le motif du refus.']);

        $indication->update([
            'status' => $validated['decision'] === 'APPROVE' ? 'APPROVED' : 'REFUSED',
            'head_decided_by' => $user->id,
            'head_decided_at' => Carbon::now(),
            'refusal_reason' => $validated['decision'] === 'REFUSE' ? $validated['reason'] : null,
        ]);

        return response()->json(['data' => $this->present($indication->fresh()), 'message' => $validated['decision'] === 'APPROVE' ? 'Indication validée.' : 'Indication refusée.']);
    }

    /** Pre-anesthesia assessment by an anesthesia-reanimation doctor, once the head has approved. */
    public function preAnesthesia(Request $request, SurgicalIndication $indication): JsonResponse
    {
        $user = $request->user();
        if ($user->role?->name !== 'DOCTOR' || $user->doctorProfile?->specialtyRef?->code !== self::ANESTHESIA) {
            return response()->json(['message' => 'La consultation pré-anesthésique est réservée aux médecins anesthésistes-réanimateurs.'], 403);
        }
        if ($indication->status !== 'APPROVED') {
            return response()->json(['message' => 'L’évaluation pré-anesthésique suit la validation du chef de service.'], 422);
        }
        $validated = $request->validate([
            'asa' => ['required', 'integer', 'between:1,5'],
            'allergies_reviewed' => ['required', 'accepted'],
            'contraindications' => ['nullable', 'string', 'max:2000'],
        ], ['asa.between' => 'Le score ASA va de 1 à 5.', 'allergies_reviewed.accepted' => 'Confirmez avoir vérifié les allergies du patient.']);

        $indication->update([
            'asa' => $validated['asa'],
            'allergies_reviewed' => true,
            'contraindications' => $validated['contraindications'] ?? null,
            'pre_anesthesia_by' => $user->id,
            'pre_anesthesia_at' => Carbon::now(),
        ]);

        return response()->json(['data' => $this->present($indication->fresh()), 'message' => 'Consultation pré-anesthésique enregistrée.']);
    }

    private function present(SurgicalIndication $indication): array
    {
        $indication->loadMissing(['patient:id,first_name,last_name,patient_unique_id', 'specialty:id,label', 'requester:id,name']);

        return [
            'id' => $indication->id,
            'status' => $indication->status,
            'operation_type' => $indication->operation_type,
            'urgency' => $indication->urgency,
            'motif' => $indication->motif,
            'refusal_reason' => $indication->refusal_reason,
            'patient' => $indication->patient ? ['id' => $indication->patient->id, 'name' => trim($indication->patient->first_name.' '.$indication->patient->last_name), 'patient_unique_id' => $indication->patient->patient_unique_id] : null,
            'specialty' => $indication->specialty?->label,
            'requested_by' => $indication->requester?->name,
            'asa' => $indication->asa,
            'allergies_reviewed' => (bool) $indication->allergies_reviewed,
            'contraindications' => $indication->contraindications,
            'ready_to_schedule' => $indication->readyToSchedule(),
        ];
    }
}
