<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\PatientImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bulk migration of patient files (CSV / Excel). The clinic always comes from the
 * authenticated user: a clinic_id column in the file is ignored.
 */
class DataImportController extends Controller
{
    public function __construct(private readonly PatientImporter $importer)
    {
    }

    public function template(): Response
    {
        return response($this->importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-import-patients.csv"',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ], [
            'file.required' => 'Sélectionnez un fichier.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'file.mimes' => 'Format accepté : .csv ou .xlsx.',
        ]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());

        try {
            $report = $this->importer->analyse(
                $file->getRealPath(),
                $extension,
                mb_substr($file->getClientOriginalName(), 0, 200),
                (int) $request->user()->clinic_id,
                (int) $request->user()->id,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return response()->json($report);
    }

    public function commit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->importer->commit(
            $data['token'],
            (int) $data['offset'],
            (int) $request->user()->clinic_id,
            (int) $request->user()->id,
        ));
    }
}
