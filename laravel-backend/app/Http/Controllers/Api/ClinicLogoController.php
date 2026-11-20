<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Services\ClinicLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Logo of the director's own clinic. No clinic id in the URL: the clinic is always the user's,
 * so one clinic cannot change another clinic's logo.
 */
class ClinicLogoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role?->name !== 'ADMIN_CLINIC') {
            return response()->json(['message' => 'Seul le directeur de la clinique peut changer le logo.'], 403);
        }

        $request->validate([
            'logo' => ['required', 'file', 'max:'.ClinicLogo::MAX_KILOBYTES, 'mimes:'.implode(',', ClinicLogo::EXTENSIONS)],
        ], [
            'logo.required' => 'Choisissez une image.',
            'logo.max' => 'Le logo ne doit pas dépasser 1 Mo.',
            'logo.mimes' => 'Formats acceptés : PNG, JPG ou WEBP (SVG refusé).',
        ]);

        $clinic = Clinic::query()->findOrFail($user->clinic_id);
        ClinicLogo::store($clinic, $request->file('logo'));

        return response()->json(['message' => 'Logo enregistré. Il figurera sur les quittances et ordonnances.', 'data' => ['has_logo' => true]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($request->user()->role?->name !== 'ADMIN_CLINIC') {
            throw ValidationException::withMessages(['logo' => 'Seul le directeur de la clinique peut supprimer le logo.']);
        }
        ClinicLogo::delete(Clinic::query()->findOrFail($request->user()->clinic_id));

        return response()->json(['message' => 'Logo supprimé.', 'data' => ['has_logo' => false]]);
    }
}
