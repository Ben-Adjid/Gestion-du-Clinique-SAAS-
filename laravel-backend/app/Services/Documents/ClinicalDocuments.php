<?php

namespace App\Services\Documents;

use App\Models\Admission;
use App\Models\Surgery;
use App\Models\SurgicalConsent;
use App\Models\VitalSign;
use App\Services\ClinicLogo;

/**
 * Operative report, surgical consent and hospital file as PDFs, on the shared frame.
 * Each document carries the verification code of its own identity (see VerificationCode).
 */
final class ClinicalDocuments
{
    /** "Dr Fatimé ..." already carries the title: never print "Dr Dr". */
    public static function doctorLabel(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : (preg_match('/^Dr\b/u', $name) ? $name : 'Dr '.$name);
    }

    public static function operativeReport(Surgery $surgery): string
    {
        $surgery->loadMissing(['patient', 'surgeon.doctorProfile', 'anesthetist', 'clinic']);
        $signed = $surgery->report_signed_at !== null;

        return OfficialPdf::render('pdf.operative-report', [
            'clinic' => $surgery->clinic,
            'logo' => ClinicLogo::pdfDataUri($surgery->clinic),
            'licence' => $surgery->clinic->settings['license_number'] ?? null,
            'surgery' => $surgery,
            'patient' => $surgery->patient,
            'surgeonLabel' => self::doctorLabel($surgery->surgeon?->name),
            'surgeonLicence' => $surgery->surgeon?->doctorProfile?->license_number,
            'anesthetistLabel' => self::doctorLabel($surgery->anesthetist?->name),
            'verification' => $signed ? VerificationCode::for('OPERATIVE_REPORT', $surgery->id, (string) $surgery->report_signed_at?->timestamp, strlen((string) $surgery->report)) : null,
        ]);
    }

    public static function consent(SurgicalConsent $consent): string
    {
        $consent->loadMissing(['surgery.patient', 'surgery.surgeon.doctorProfile', 'surgery.clinic']);
        $surgery = $consent->surgery;

        return OfficialPdf::render('pdf.consent', [
            'clinic' => $surgery->clinic,
            'logo' => ClinicLogo::pdfDataUri($surgery->clinic),
            'licence' => $surgery->clinic->settings['license_number'] ?? null,
            'consent' => $consent,
            'surgery' => $surgery,
            'patient' => $surgery->patient,
            'surgeonLabel' => self::doctorLabel($surgery->surgeon?->name),
            'surgeonLicence' => $surgery->surgeon?->doctorProfile?->license_number,
            'verification' => VerificationCode::for('CONSENT', $consent->id, (string) $consent->signed_at?->timestamp, (string) $surgery->id),
        ]);
    }

    public static function hospitalFile(Admission $admission): string
    {
        $admission->loadMissing(['patient', 'bed', 'room', 'doctor.doctorProfile', 'transfers', 'clinic']);
        $vitals = VitalSign::query()->where('patient_id', $admission->patient_id)->latest('recorded_at')->limit(3)->get();

        return OfficialPdf::render('pdf.hospital-file', [
            'clinic' => $admission->clinic,
            'logo' => ClinicLogo::pdfDataUri($admission->clinic),
            'licence' => $admission->clinic->settings['license_number'] ?? null,
            'admission' => $admission,
            'patient' => $admission->patient,
            'doctorLabel' => self::doctorLabel($admission->doctor?->name),
            'doctorLicence' => $admission->doctor?->doctorProfile?->license_number,
            'vitals' => $vitals,
            'verification' => VerificationCode::for('HOSPITAL_FILE', $admission->id, (string) $admission->admitted_at?->timestamp, (string) $admission->status),
        ]);
    }
}
