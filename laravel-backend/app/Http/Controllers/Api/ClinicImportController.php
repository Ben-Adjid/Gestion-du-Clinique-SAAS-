<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunClinicImport;
use App\Models\ClinicImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Takeover of a clinic's data from an archive (the format of the clinic export). Only the clinic administrator.
 * Upload -> check (nothing written, duplicates and errors listed) -> confirm -> import in the background.
 */
class ClinicImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->onlyAdministrator($request);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:2097152'],
        ], ['file.mimes' => 'Le fichier doit être une archive ZIP (l’export de la plateforme).', 'file.max' => 'Le fichier dépasse 2 Go.']);

        $clinicId = (int) $request->user()->clinic_id;
        $path = $validated['file']->storeAs("imports/clinic-{$clinicId}", Str::uuid().'.zip');

        $import = ClinicImport::create([
            'clinic_id' => $clinicId,
            'user_id' => $request->user()->id,
            'status' => ClinicImport::PENDING,
            'file_path' => $path,
            'original_name' => $validated['file']->getClientOriginalName(),
        ]);
        RunClinicImport::dispatch($import->id, 'check');

        return response()->json(['data' => $import->fresh(), 'message' => 'Vérification du fichier en cours.'], 202);
    }

    /** The latest takeover of this clinic, so that the page shows where the last one stands. */
    public function latest(Request $request): JsonResponse
    {
        $this->onlyAdministrator($request);
        $import = ClinicImport::query()->where('clinic_id', $request->user()->clinic_id)->latest('id')->first();

        return response()->json(['data' => $import]);
    }

    public function show(Request $request, ClinicImport $import): JsonResponse
    {
        $this->onlyAdministrator($request);
        abort_unless((int) $import->clinic_id === (int) $request->user()->clinic_id, 404, 'Import introuvable.');

        return response()->json(['data' => $import]);
    }

    /** The administrator confirms the check: the rows are written in the background. */
    public function confirm(Request $request, ClinicImport $import): JsonResponse
    {
        $this->onlyAdministrator($request);
        abort_unless((int) $import->clinic_id === (int) $request->user()->clinic_id, 404, 'Import introuvable.');
        if ($import->status !== ClinicImport::CHECKED) {
            return response()->json(['message' => 'Seul un fichier vérifié peut être importé.'], 422);
        }

        RunClinicImport::dispatch($import->id, 'run');

        return response()->json(['data' => $import->fresh(), 'message' => 'Import lancé. Le rapport sera disponible à la fin.'], 202);
    }

    private function onlyAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()->role?->name === 'ADMIN_CLINIC',
            403,
            'Seul l’administrateur de la clinique peut reprendre les données d’une autre plateforme.',
        );
    }
}
