<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrugInteraction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DrugInteractionController extends Controller
{
    public function index(Request $request)
    {
        $clinicId = $request->attributes->get('clinic_id');

        $interactions = DrugInteraction::query()
            ->when($clinicId !== null, fn ($query) => $query->whereHas('firstDrug', fn ($drugs) => $drugs->where('clinic_id', $clinicId)))
            ->with(['firstDrug:id,name,dosage', 'secondDrug:id,name,dosage'])
            ->orderByRaw("CASE severity WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")
            ->get();

        return response()->json(['data' => $interactions]);
    }

    public function store(Request $request)
    {
        $clinicId = $request->attributes->get('clinic_id');
        if ($clinicId === null) {
            return response()->json(['message' => 'Choisissez une clinique avant de gérer les interactions médicamenteuses.'], 422);
        }

        $validated = $request->validate([
            'drug_id_1' => ['required', 'integer', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)],
            'drug_id_2' => ['required', 'integer', 'different:drug_id_1', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)],
            'severity' => ['required', Rule::in(['LOW', 'MEDIUM', 'HIGH'])],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        [$first, $second] = [min($validated['drug_id_1'], $validated['drug_id_2']), max($validated['drug_id_1'], $validated['drug_id_2'])];
        if (DrugInteraction::where('drug_id_1', $first)->where('drug_id_2', $second)->exists()) {
            return response()->json(['message' => 'Cette interaction est déjà déclarée.'], 422);
        }

        $interaction = DrugInteraction::create([
            'drug_id_1' => $first,
            'drug_id_2' => $second,
            'severity' => $validated['severity'],
            'description' => $validated['description'],
        ]);

        return response()->json(['data' => $interaction->load(['firstDrug:id,name,dosage', 'secondDrug:id,name,dosage'])], 201);
    }

    public function destroy(Request $request, DrugInteraction $drugInteraction)
    {
        $clinicId = $request->attributes->get('clinic_id');
        $drugInteraction->load('firstDrug');
        if ($clinicId !== null && (int) $drugInteraction->firstDrug?->clinic_id !== (int) $clinicId) {
            return response()->json(['message' => 'Interaction introuvable.'], 404);
        }

        $drugInteraction->delete();

        return response()->noContent();
    }
}
