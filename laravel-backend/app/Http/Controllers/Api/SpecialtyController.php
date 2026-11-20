<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The specialty catalogue, and the appointment of the heads of specialty by the clinic director. */
class SpecialtyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Specialty::query()->orderBy('label')->get(['id', 'code', 'label', 'service', 'record_kind'])]);
    }

    /** The director names or removes the head of a specialty (chef de service). */
    public function setHead(Request $request, User $doctor): JsonResponse
    {
        if ($request->user()->role?->name !== 'ADMIN_CLINIC') {
            return response()->json(['message' => 'Seul le directeur de la clinique nomme les chefs de service.'], 403);
        }
        abort_unless($doctor->clinic_id === (int) $request->user()->clinic_id && $doctor->role?->name === 'DOCTOR', 404);
        $validated = $request->validate(['is_head' => ['required', 'boolean']]);
        $profile = $doctor->doctorProfile;
        if ($profile === null || $profile->specialty_id === null) {
            return response()->json(['message' => 'Le médecin doit avoir une spécialité avant d’être nommé chef de service.'], 422);
        }
        $profile->update(['is_head' => $validated['is_head']]);

        return response()->json(['data' => ['doctor' => $doctor->name, 'is_head' => (bool) $validated['is_head'], 'specialty' => $profile->specialtyRef?->label], 'message' => $validated['is_head'] ? 'Chef de service nommé.' : 'Chef de service retiré.']);
    }
}
