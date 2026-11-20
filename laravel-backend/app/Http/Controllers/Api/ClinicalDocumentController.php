<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Surgery;
use App\Services\Documents\ClinicalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Printable clinical documents: operative report, surgical consent and hospital stay file (PDF). */
class ClinicalDocumentController extends Controller
{
    /** The operative report can be printed once it is signed: an unsigned draft is not an official document. */
    public function operativeReport(Request $request, Surgery $surgery): Response|JsonResponse
    {
        if ($surgery->report_signed_at === null) {
            return response()->json(['message' => 'Le compte rendu doit être signé avant d’être imprimé.'], 422);
        }

        return $this->pdf(ClinicalDocuments::operativeReport($surgery), 'CR-OPERATOIRE-'.$surgery->id.'.pdf');
    }

    public function consent(Request $request, Surgery $surgery): Response|JsonResponse
    {
        $consent = $surgery->consent()->first();
        if ($consent === null) {
            return response()->json(['message' => 'Aucun consentement recueilli pour cette intervention.'], 422);
        }

        return $this->pdf(ClinicalDocuments::consent($consent), 'CONSENTEMENT-'.$surgery->id.'.pdf');
    }

    public function hospitalFile(Request $request, Admission $admission): Response
    {
        return $this->pdf(ClinicalDocuments::hospitalFile($admission), 'FICHE-SEJOUR-'.$admission->id.'.pdf');
    }

    private function pdf(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
