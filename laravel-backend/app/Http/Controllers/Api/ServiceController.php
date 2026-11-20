<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Surgery;
use App\Models\SurgicalIndication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Dashboard of the head of a specialty: beds of the service, indications and operations to follow, duties. */
class ServiceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->doctorProfile;
        if ($user->role?->name !== 'DOCTOR' || !$profile?->is_head || $profile->specialty_id === null) {
            return response()->json(['message' => 'Ce tableau de bord est réservé au chef de service de la spécialité.'], 403);
        }
        $specialty = $profile->specialtyRef;
        $clinicId = (int) $user->clinic_id;
        $doctorIds = DoctorProfile::query()->where('specialty_id', $specialty->id)->pluck('user_id');

        $beds = Bed::query()->whereHas('room', fn ($rooms) => $rooms->where('service', $specialty->service))->get(['id', 'status']);
        $stays = $specialty->service ? Admission::query()->whereIn('status', Admission::ACTIVE)
            ->whereHas('room', fn ($rooms) => $rooms->where('service', $specialty->service))->count() : 0;

        $pending = SurgicalIndication::query()->where('specialty_id', $specialty->id)->where('status', 'PENDING_HEAD')->count();
        $late = Surgery::query()->whereIn('surgeon_id', $doctorIds)->where('status', 'PLANNED')->where('scheduled_at', '<', Carbon::now())->count();
        $upcoming = Surgery::query()->whereIn('surgeon_id', $doctorIds)->whereIn('status', ['PLANNED', 'IN_PROGRESS'])->orderBy('scheduled_at')->limit(10)
            ->get(['id', 'operation_type', 'scheduled_at', 'status', 'room']);
        $onCallToday = DoctorSchedule::query()->where('clinic_id', $clinicId)->whereIn('doctor_id', $doctorIds)
            ->whereDate('work_date', Carbon::today())->where('status', 'ON_CALL')->with('doctor:id,name')->get()
            ->map(fn (DoctorSchedule $duty) => $duty->doctor?->name)->values();

        return response()->json(['data' => [
            'specialty' => $specialty->label,
            'service' => $specialty->service,
            'beds' => ['total' => $beds->count(), 'occupied' => $beds->where('status', 'OCCUPIED')->count(), 'active_stays' => $stays],
            'indications_to_validate' => $pending,
            'operations_late' => $late,
            'operations' => $upcoming,
            'on_call_today' => $onCallToday,
        ]]);
    }
}
