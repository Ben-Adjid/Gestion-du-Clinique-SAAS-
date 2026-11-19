<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Services\PatientRights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Staff side of the patient's rights: consents, copy of the record, anonymisation. */
class PatientRightsController extends Controller
{
    public function __construct(private readonly PatientRights $rights)
    {
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        self::refuseNonStaff($request);
        return response()->json(['data' => $this->state($patient)]);
    }

    public function updateConsents(Request $request, Patient $patient): JsonResponse
    {
        self::refuseNonStaff($request);
        $this->refuseIfAnonymized($patient);
        $validated = $request->validate(['consents' => ['required', 'array']]);

        $this->rights->updateConsents($patient, self::validConsents($validated['consents']), $request->user()->id);

        return response()->json(['data' => $this->state($patient)]);
    }

    public function export(Request $request, Patient $patient): JsonResponse
    {
        self::refuseNonStaff($request);
        return response()->json($this->rights->export($patient), 200, [
            'Content-Disposition' => 'attachment; filename="dossier-'.$patient->patient_unique_id.'.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** Irreversible: the identity is erased. The clinical content is kept for legal retention. */
    public function anonymize(Request $request, Patient $patient): JsonResponse
    {
        self::refuseNonStaff($request);
        $request->validate(['confirm' => ['required', 'in:ANONYMISER']], [
            'confirm.in' => 'Tapez ANONYMISER pour confirmer.',
        ]);
        if ($patient->user()->exists()) {
            return response()->json(['message' => 'Supprimez d’abord le compte patient lié à ce dossier.'], 409);
        }

        $this->rights->anonymize($patient);

        return response()->json(['message' => 'Dossier anonymisé.', 'data' => $this->state($patient->fresh())]);
    }

    /** @return array<string, mixed> */
    private function state(Patient $patient): array
    {
        return [
            'consents' => $this->rights->consents($patient),
            'anonymized_at' => $patient->anonymized_at?->toIso8601String(),
            'has_portal_account' => $patient->user()->exists(),
        ];
    }

    /**
     * The patient role holds read:patient for its own record. These routes address any record of
     * the clinic, so they are refused to patient accounts whatever their permissions.
     */
    private static function refuseNonStaff(Request $request): void
    {
        abort_if($request->user()->role?->name === 'PATIENT', 403, 'Réservé au personnel de la clinique.');
    }

    private function refuseIfAnonymized(Patient $patient): void
    {
        if ($patient->anonymized_at !== null) {
            throw ValidationException::withMessages(['patient' => 'Les consentements d’un dossier anonymisé ne peuvent plus être modifiés.']);
        }
    }

    /**
     * Only the consent types known to the platform are accepted; anything else is refused
     * rather than silently ignored, so that a typo never looks like a saved choice.
     *
     * @param  array<string, mixed>  $consents
     * @return array<string, bool>
     */
    public static function validConsents(array $consents): array
    {
        $allowed = array_keys(PatientConsent::TYPES);
        $unknown = array_diff(array_keys($consents), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['consents' => 'Consentement inconnu : '.implode(', ', $unknown).'.']);
        }
        $values = [];
        foreach ($consents as $type => $granted) {
            if (!is_bool($granted)) {
                throw ValidationException::withMessages(["consents.{$type}" => 'La valeur doit être vrai ou faux.']);
            }
            $values[$type] = $granted;
        }

        return $values;
    }
}
