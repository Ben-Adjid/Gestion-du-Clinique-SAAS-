<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\AbsenceRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\UserNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Justifications for absences: submitted by staff, decided by HR (manage:attendance). */
class AbsenceRequestController extends Controller
{
    private const LOOKBACK_DAYS = 30;

    public function mine(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['data' => $employee
            ? AbsenceRequest::query()->where('employee_id', $employee->id)->latest('work_date')->limit(60)->get()
            : []]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        if (!$employee) {
            return response()->json(['message' => 'Aucun dossier du personnel n’est lié à votre compte.'], 422);
        }
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:' . today()->subDays(self::LOOKBACK_DAYS)->toDateString()],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'work_date.after_or_equal' => 'Un justificatif se dépose dans les ' . self::LOOKBACK_DAYS . ' jours.',
            'attachment.mimes' => 'Format accepté : PDF, JPG ou PNG.',
            'attachment.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ]);

        $worked = Attendance::query()->where('employee_id', $employee->id)
            ->whereDate('work_date', $validated['work_date'])->whereNotNull('check_in_datetime')->exists();
        if ($worked) {
            throw ValidationException::withMessages(['work_date' => 'Vous avez pointé ce jour-là : aucun justificatif d’absence n’est nécessaire.']);
        }

        $path = $request->file('attachment')?->store('absences/' . $employee->clinic_id, 'local');

        try {
            $absence = AbsenceRequest::query()->create([
                'clinic_id' => $employee->clinic_id,
                'employee_id' => $employee->id,
                'work_date' => $validated['work_date'],
                'reason' => $validated['reason'],
                'attachment_path' => $path,
                'status' => 'PENDING',
            ]);
        } catch (UniqueConstraintViolationException) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw ValidationException::withMessages(['work_date' => 'Un justificatif existe déjà pour ce jour.']);
        }

        return response()->json(['data' => $absence, 'message' => 'Justificatif transmis aux ressources humaines.'], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['sometimes', 'in:PENDING,APPROVED,REJECTED']]);
        $query = AbsenceRequest::query()->with('employee:id,first_name,last_name,position')->latest('work_date');
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function review(Request $request, AbsenceRequest $absenceRequest): JsonResponse
    {
        if ($absenceRequest->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => 'Ce justificatif a déjà été traité.']);
        }
        $validated = $request->validate([
            'decision' => ['required', 'in:APPROVED,REJECTED'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,REJECTED'],
        ], ['note.required_if' => 'Indiquez le motif du refus.']);

        DB::transaction(function () use ($absenceRequest, $validated, $request) {
            if ($validated['decision'] === 'APPROVED') {
                $worked = Attendance::query()->where('employee_id', $absenceRequest->employee_id)
                    ->whereDate('work_date', $absenceRequest->work_date)->whereNotNull('check_in_datetime')->exists();
                if ($worked) {
                    throw ValidationException::withMessages(['decision' => 'Un pointage existe pour ce jour : le justificatif ne peut pas être approuvé.']);
                }
                Attendance::query()->updateOrCreate(
                    ['employee_id' => $absenceRequest->employee_id, 'work_date' => $absenceRequest->work_date],
                    [
                        'clinic_id' => $absenceRequest->clinic_id,
                        'status' => 'JUSTIFIED_ABSENCE',
                        'check_in_datetime' => null,
                        'check_out_datetime' => null,
                        'late_minutes' => 0,
                        'notes' => 'Absence justifiée : ' . $absenceRequest->reason,
                        'recorded_by' => $request->user()->id,
                    ],
                );
            }

            $absenceRequest->update([
                'status' => $validated['decision'],
                'review_note' => $validated['note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $userId = $absenceRequest->employee()->value('user_id');
            if ($userId) {
                UserNotification::query()->create([
                    'clinic_id' => $absenceRequest->clinic_id,
                    'user_id' => $userId,
                    'type' => 'ABSENCE_REVIEW',
                    'title' => $validated['decision'] === 'APPROVED' ? 'Justificatif accepté' : 'Justificatif refusé',
                    'message' => 'Absence du ' . Carbon::parse($absenceRequest->work_date)->format('d/m/Y') . ' : ' . ($validated['decision'] === 'APPROVED' ? 'acceptée.' : 'refusée. ' . ($validated['note'] ?? '')),
                ]);
            }
        });

        return response()->json(['data' => $absenceRequest->fresh(), 'message' => 'Décision enregistrée.']);
    }

    public function attachment(AbsenceRequest $absenceRequest): StreamedResponse|JsonResponse
    {
        if (!$absenceRequest->attachment_path || !Storage::disk('local')->exists($absenceRequest->attachment_path)) {
            return response()->json(['message' => 'Aucun justificatif joint.'], 404);
        }

        return Storage::disk('local')->download($absenceRequest->attachment_path);
    }

    private function ownEmployee(Request $request): ?Employee
    {
        return Employee::query()->where('user_id', $request->user()->id)->where('status', '!=', 'TERMINATED')->first();
    }
}
