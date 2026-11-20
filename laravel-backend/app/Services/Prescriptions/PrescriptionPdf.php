<?php

namespace App\Services\Prescriptions;

use App\Models\Prescription;
use App\Services\ClinicLogo;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Medical prescription (ordonnance) as a PDF: clinic header and logo, the prescriber's registration
 * number, legible posology, and a verification code a pharmacy can check against the prescription.
 *
 * html() and render() share the same view and the same verificationCode(), so the code printed on
 * the PDF is always the one the prescription screen shows.
 */
final class PrescriptionPdf
{
    /** Code printed on signed prescriptions: an HMAC of the content, so any change breaks it. */
    public static function verificationCode(Prescription $prescription): ?string
    {
        if (!in_array($prescription->status, ['SIGNED', 'DISPENSED'], true)) {
            return null;
        }

        return strtoupper(substr(hash_hmac('sha256', implode('|', [
            $prescription->id,
            $prescription->clinic_id,
            $prescription->doctor_id,
            $prescription->prescribed_at?->toIso8601String(),
            $prescription->items->map(fn ($item) => "{$item->medication_id}:{$item->dosage_prescribed}:{$item->frequency}:{$item->duration_days}:{$item->quantity}")->implode(','),
        ]), (string) config('app.key')), 0, 16));
    }

    /** The prescription as HTML, before the PDF engine runs. */
    public static function html(Prescription $prescription): string
    {
        $prescription->loadMissing(['consultation.patient', 'doctor.doctorProfile', 'items.medication', 'clinic']);

        return view('pdf.prescription', [
            'clinic' => $prescription->clinic,
            'logo' => ClinicLogo::pdfDataUri($prescription->clinic),
            'licence' => $prescription->clinic->settings['license_number'] ?? null,
            'prescription' => $prescription,
            'patient' => $prescription->consultation->patient,
            'doctorName' => $prescription->doctor->name,
            'doctorLicence' => $prescription->doctor->doctorProfile?->license_number,
            'verification' => self::verificationCode($prescription),
        ])->render();
    }

    public static function render(Prescription $prescription): string
    {
        return Pdf::loadHTML(self::html($prescription))->setPaper('a5', 'portrait')->output();
    }
}
