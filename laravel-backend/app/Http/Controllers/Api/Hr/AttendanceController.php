<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\HumanResources;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /** Attendance sheet of one day for the whole staff. */
    public function day(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $date = $validated['date'] ?? today()->toDateString();

        $records = Attendance::query()->where('clinic_id', $clinicId)->whereDate('work_date', $date)->get()->keyBy('employee_id');
        $onLeave = DB::table('leaves')
            ->where('clinic_id', $clinicId)
            ->where('status', 'APPROVED')
            ->whereNull('cancelled_at')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('leave_type', 'employee_id');

        $rows = Employee::query()
            ->where('clinic_id', $clinicId)
            ->where('status', '!=', 'TERMINATED')
            ->whereDate('hire_date', '<=', $date)
            ->orderBy('last_name')
            ->get(['id', 'employee_number', 'first_name', 'last_name', 'position', 'department'])
            ->map(fn (Employee $employee) => [
                ...$employee->toArray(),
                'attendance' => $records->get($employee->id),
                'leave_type' => $onLeave->get($employee->id),
            ]);

        return response()->json(['data' => $rows, 'date' => $date, 'settings' => HumanResources::settings($clinicId)]);
    }

    /** Self-service clock-in / clock-out for the connected staff member. */
    public function clock(Request $request)
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->where('status', '!=', 'TERMINATED')->first();
        if (!$employee) {
            return response()->json(['message' => 'Aucun dossier du personnel n’est lié à votre compte. Contactez les ressources humaines.'], 422);
        }
        $settings = HumanResources::settings($employee->clinic_id);
        $now = now();

        $record = DB::transaction(function () use ($employee, $settings, $now) {
            $record = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $now->toDateString())->lockForUpdate()->first();
            if (!$record) {
                $late = HumanResources::lateMinutes($now, $settings);

                return Attendance::create([
                    'clinic_id' => $employee->clinic_id,
                    'employee_id' => $employee->id,
                    'work_date' => $now->toDateString(),
                    'check_in_datetime' => $now,
                    'check_in_method' => 'MOBILE',
                    'status' => $late > 0 ? 'LATE' : 'PRESENT',
                    'late_minutes' => $late,
                ]);
            }
            if ($record->check_out_datetime !== null || in_array($record->status, ['ABSENT', 'JUSTIFIED_ABSENCE'], true)) {
                abort(422, 'Votre journée est déjà clôturée.');
            }
            $record->update(['check_out_datetime' => $now]);

            return $record;
        });

        return response()->json([
            'data' => $record->fresh(),
            'message' => $record->check_out_datetime ? 'Départ enregistré. Bonne soirée !' : ($record->status === 'LATE' ? 'Arrivée enregistrée (en retard).' : 'Arrivée enregistrée.'),
        ]);
    }

    /** Manual entry or correction by HR (forgotten clock-in, absence…). */
    public function record(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('clinic_id', $clinicId)],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['PRESENT', 'LATE', 'ABSENT'])],
            'check_in' => ['nullable', 'required_unless:status,ABSENT', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['check_in.required_unless' => 'L’heure d’arrivée est obligatoire sauf pour une absence.']);

        $absent = $validated['status'] === 'ABSENT';
        // The status follows the real arrival time: the HR form cannot record an on-time arrival as late.
        $late = $absent || empty($validated['check_in']) ? 0 : HumanResources::lateMinutes(Carbon::parse("{$validated['work_date']} {$validated['check_in']}:00"), HumanResources::settings($clinicId));
        $record = Attendance::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'work_date' => $validated['work_date']],
            [
                'clinic_id' => $clinicId,
                'check_in_datetime' => $absent ? null : "{$validated['work_date']} {$validated['check_in']}:00",
                'check_out_datetime' => $absent || empty($validated['check_out']) ? null : "{$validated['work_date']} {$validated['check_out']}:00",
                'status' => $absent ? 'ABSENT' : ($late > 0 ? 'LATE' : 'PRESENT'),
                'late_minutes' => $late,
                'check_in_method' => null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ],
        );

        return response()->json(['data' => $record, 'message' => 'Pointage enregistré.']);
    }

    /** Monthly summary per employee. */
    public function report(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $start = Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')->startOfDay();
        $end = $start->copy()->endOfMonth()->min(today());
        $records = Attendance::query()->where('clinic_id', $clinicId)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('employee_id');
        $settings = HumanResources::settings($clinicId);

        $rows = Employee::query()->where('clinic_id', $clinicId)->whereDate('hire_date', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
            ->orderBy('last_name')->get(['id', 'employee_number', 'first_name', 'last_name', 'position'])
            ->map(function (Employee $employee) use ($records, $settings) {
                $days = $records->get($employee->id, collect());

                return [
                    ...$employee->toArray(),
                    'present' => $days->where('status', 'PRESENT')->count(),
                    'late' => $days->where('status', 'LATE')->count(),
                    'absent' => $days->where('status', 'ABSENT')->count(),
                    'hours' => round($days->sum('worked_hours'), 1),
                    'overtime' => round($days->sum(fn ($day) => max(0, ($day->worked_hours ?? 0) - (float) $settings['daily_hours'])), 1),
                ];
            });

        return response()->json(['data' => $rows, 'working_days' => HumanResources::workingDays($start, $end)]);
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
