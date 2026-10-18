<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\HumanResources;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    /** HR sees every request; other staff only their own. */
    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $mine = !$this->isHr($request);
        $own = $mine ? $this->ownEmployee($request) : null;
        if ($mine && !$own) {
            return response()->json(['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1]);
        }

        return response()->json(Leave::query()
            ->where('clinic_id', $clinicId)
            ->with(['employee:id,first_name,last_name,position,employee_number', 'reviewer:id,name'])
            ->when($mine, fn ($query) => $query->where('employee_id', $own->id))
            ->when(!$mine && ($filters['employee_id'] ?? null), fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->paginate(25));
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'employee_id' => ['sometimes', 'integer', Rule::exists('employees', 'id')->where('clinic_id', $clinicId)],
            'leave_type' => ['required', Rule::in(array_keys(Leave::TYPES))],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => [Rule::requiredIf(in_array($request->input('leave_type'), ['SICK', 'UNPAID'], true)), 'nullable', 'string', 'max:1000'],
        ], ['start_date.after_or_equal' => 'Une demande de congé ne peut pas commencer dans le passé.']);

        // HR may file a request on behalf of an employee; others request for themselves.
        $employee = isset($validated['employee_id']) && $this->isHr($request)
            ? Employee::findOrFail($validated['employee_id'])
            : $this->ownEmployee($request);
        if (!$employee || $employee->status === 'TERMINATED') {
            return response()->json(['message' => 'Aucun dossier du personnel actif n’est lié à votre compte.'], 422);
        }

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $days = HumanResources::workingDays($start, $end);
        if ($days === 0) {
            return response()->json(['message' => 'La période choisie ne contient aucun jour ouvré.'], 422);
        }
        $overlap = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereNull('cancelled_at')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlap) {
            return response()->json(['message' => 'Une autre demande couvre déjà une partie de ces dates.'], 422);
        }
        if ($validated['leave_type'] === 'VACATION') {
            $balance = HumanResources::leaveBalance($employee, (int) $start->year);
            if ($days > $balance['remaining']) {
                return response()->json(['message' => "Solde insuffisant : {$balance['remaining']} jour(s) de congé disponible(s), {$days} demandé(s)."], 422);
            }
        }

        $leave = Leave::create([
            'clinic_id' => $clinicId,
            'employee_id' => $employee->id,
            'leave_type' => $validated['leave_type'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_count' => $days,
            'status' => 'PENDING',
            'reason' => $validated['reason'] ?? null,
        ]);
        $this->notifyHr($clinicId, 'Nouvelle demande de congé', "{$employee->fullName()} : ".Leave::TYPES[$leave->leave_type]." du {$start->format('d/m/Y')} au {$end->format('d/m/Y')} ({$days} j).");

        return response()->json(['data' => $leave->load('employee:id,first_name,last_name'), 'message' => "Demande envoyée ({$days} jour(s) ouvré(s))."], 201);
    }

    public function review(Request $request, Leave $leave)
    {
        if ((int) $leave->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'review_note' => ['nullable', 'required_if:decision,REJECTED', 'string', 'max:1000'],
        ], ['review_note.required_if' => 'Indiquez le motif du refus.']);
        if ($leave->status !== 'PENDING' || $leave->cancelled_at) {
            return response()->json(['message' => 'Cette demande a déjà été traitée.'], 422);
        }
        if ((int) $leave->employee->user_id === (int) $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas valider votre propre demande.'], 403);
        }

        $leave->update([
            'status' => $validated['decision'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);
        if ($leave->employee->user_id) {
            UserNotification::create([
                'clinic_id' => $leave->clinic_id,
                'user_id' => $leave->employee->user_id,
                'type' => 'LEAVE',
                'title' => $validated['decision'] === 'APPROVED' ? 'Congé accepté' : 'Congé refusé',
                'message' => Leave::TYPES[$leave->leave_type]." du {$leave->start_date->format('d/m/Y')} au {$leave->end_date->format('d/m/Y')}".(!empty($validated['review_note']) ? " : {$validated['review_note']}" : '.'),
            ]);
        }

        return response()->json(['data' => $leave->fresh()->load(['employee:id,first_name,last_name', 'reviewer:id,name']), 'message' => $validated['decision'] === 'APPROVED' ? 'Congé accepté.' : 'Congé refusé.']);
    }

    /** The employee withdraws a pending request; HR may cancel an approved leave that has not started. */
    public function cancel(Request $request, Leave $leave)
    {
        if ((int) $leave->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }
        $isOwner = (int) $leave->employee->user_id === (int) $request->user()->id;
        $allowed = ($isOwner && $leave->status === 'PENDING')
            || ($this->isHr($request) && in_array($leave->status, ['PENDING', 'APPROVED'], true));
        if (!$allowed || $leave->cancelled_at) {
            return response()->json(['message' => 'Cette demande ne peut pas être annulée.'], 403);
        }
        if ($leave->status === 'APPROVED' && $leave->start_date->lte(today())) {
            return response()->json(['message' => 'Un congé déjà commencé ne peut plus être annulé.'], 422);
        }
        $leave->update(['cancelled_at' => now()]);

        return response()->json(['data' => $leave->fresh(), 'message' => 'Demande annulée.']);
    }

    private function notifyHr(int $clinicId, string $title, string $message): void
    {
        User::query()->where('clinic_id', $clinicId)->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->where('name', 'HR_MANAGER'))
            ->pluck('id')
            ->each(fn (int $userId) => UserNotification::create(['clinic_id' => $clinicId, 'user_id' => $userId, 'type' => 'LEAVE', 'title' => $title, 'message' => $message]));
    }

    private function ownEmployee(Request $request): ?Employee
    {
        return Employee::query()->where('user_id', $request->user()->id)->first();
    }

    private function isHr(Request $request): bool
    {
        return in_array('manage:leaves', \App\Support\ModuleAccess::effectivePermissions($request->user()), true);
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
