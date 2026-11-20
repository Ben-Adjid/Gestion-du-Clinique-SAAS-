<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\ModuleAccess;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private const CLINIC_ROLES = [
        'DOCTOR',
        'NURSE',
        'CASHIER',
        'PHARMACIST',
        'LABORATORY_TECHNICIAN',
        'RADIOLOGIST',
        'HR_MANAGER',
        'RECEPTIONIST',
        'PATIENT',
    ];

    public function me(Request $request)
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'unique:users,phone,'.$user->id],
        ]);

        $user->update($validated);

        return response()->json(['data' => $this->profile($user->fresh())]);
    }

    public function index(Request $request)
    {
        $query = User::query()->with(['role:id,name,display_name', 'clinic:id,name']);
        $clinicId = $request->attributes->get('clinic_id');

        if ($request->user()->role?->name !== 'SUPER_ADMIN' || $clinicId !== null) {
            $query->where('clinic_id', $clinicId ?? $request->user()->clinic_id);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($users) use ($search) {
                $users->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->paginate(25));
    }

    public function roles(Request $request)
    {
        $roles = Role::query()
            ->when(
                $request->user()->role?->name !== 'SUPER_ADMIN',
                fn ($query) => $query->whereIn('name', self::CLINIC_ROLES),
            )
            ->orderBy('display_name')
            ->get(['id', 'name', 'display_name', 'description']);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        $isSuperAdmin = $request->user()->role?->name === 'SUPER_ADMIN';
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role_id' => ['required', 'exists:roles,id'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE,SUSPENDED'],
            'clinic_id' => [$isSuperAdmin ? 'required' : 'prohibited', 'exists:clinics,id'],
        ]);

        $role = Role::findOrFail($validated['role_id']);
        $this->ensureAssignableRole($request, $role);
        $isPatient = $role->name === 'PATIENT';
        if ($isPatient !== isset($validated['patient_id'])) {
            return response()->json([
                'message' => $isPatient
                    ? 'Choisissez un dossier patient pour un compte patient.'
                    : 'A patient record can only be linked to a patient account.',
            ], 422);
        }

        $clinicId = $isSuperAdmin ? $validated['clinic_id'] : $request->user()->clinic_id;
        if ($isPatient) {
            $patient = Patient::query()->whereKey($validated['patient_id'])->first();
            if (!$patient || (int) $patient->clinic_id !== (int) $clinicId) {
                return response()->json(['message' => 'Choisissez un patient de cette clinique.'], 422);
            }
        }

        if (!$clinicId) {
            return response()->json(['message' => 'Choisissez une clinique avant de créer des utilisateurs.'], 422);
        }
        if (!$isPatient && ($validated['status'] ?? 'ACTIVE') === 'ACTIVE') {
            PlanLimits::assertCanAddUser((int) $clinicId);
        }

        $user = DB::transaction(function () use ($validated, $role, $clinicId, $isPatient): User {
            if ($isPatient) {
                $patient = Patient::query()
                    ->whereKey($validated['patient_id'])
                    ->where('clinic_id', $clinicId)
                    ->whereNull('user_id')
                    ->lockForUpdate()
                    ->first();
                if (!$patient) {
                    abort(422, 'Ce patient a déjà un compte ou n’est plus disponible.');
                }
            }

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'role_id' => $role->id,
                'clinic_id' => $clinicId,
                'status' => $validated['status'] ?? 'ACTIVE',
            ]);

            if ($isPatient) {
                $patient->user()->associate($user);
                $patient->save();
            }

            return $user;
        });

        return response()->json([
            'data' => $user->load(['role:id,name,display_name', 'clinic:id,name', 'patient:id,user_id,patient_unique_id,first_name,last_name']),
        ], 201);
    }

    public function show(Request $request, User $user)
    {
        if (!$this->canManageUser($request, $user)) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        return response()->json([
            'data' => $user->load(['role:id,name,display_name', 'clinic:id,name']),
        ]);
    }

    public function update(Request $request, User $user)
    {
        if (!$this->canManageUser($request, $user)) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        $isSuperAdmin = $request->user()->role?->name === 'SUPER_ADMIN';
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'unique:users,phone,'.$user->id],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE,SUSPENDED'],
            'clinic_id' => [$isSuperAdmin ? 'sometimes' : 'prohibited', 'exists:clinics,id'],
        ]);

        if (
            $request->user()->is($user)
            && isset($validated['status'])
            && $validated['status'] !== 'ACTIVE'
        ) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
        }
        if ($request->user()->is($user) && isset($validated['role_id'])) {
            return response()->json(['message' => 'Vous ne pouvez pas modifier votre propre rôle.'], 422);
        }

        if (isset($validated['role_id'])) {
            $newRole = Role::findOrFail($validated['role_id']);
            $this->ensureAssignableRole($request, $newRole);
            if ($newRole->name === 'PATIENT') {
                return response()->json(['message' => 'Créez les comptes patients à partir d’un dossier existant.'], 422);
            }
        }

        if (($validated['status'] ?? null) === 'ACTIVE' && $user->status !== 'ACTIVE' && $user->role?->name !== 'PATIENT') {
            PlanLimits::assertCanAddUser($user->clinic_id === null ? null : (int) $user->clinic_id, 'status');
        }

        $user->update($validated);
        if (isset($validated['role_id'])) {
            $user->patient()->update(['user_id' => null]);
        }
        if ($user->status !== 'ACTIVE') {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => $user->fresh()->load(['role:id,name,display_name', 'clinic:id,name']),
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        if (!$this->canManageUser($request, $user)) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }
        if ($user->role?->name === 'SUPER_ADMIN') {
            return response()->json(['message' => 'Un compte super-administrateur ne peut pas être supprimé ici.'], 403);
        }

        DB::transaction(function () use ($user): void {
            $user->patient()->update(['user_id' => null]);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    private function canManageUser(Request $request, User $user): bool
    {
        $actor = $request->user();
        if ($actor->role?->name === 'SUPER_ADMIN') {
            $clinicId = $request->attributes->get('clinic_id');
            return $clinicId === null || (int) $user->clinic_id === (int) $clinicId;
        }

        return $user->role?->name !== 'SUPER_ADMIN'
            && $user->clinic_id !== null
            && (int) $user->clinic_id === (int) $actor->clinic_id;
    }

    private function ensureAssignableRole(Request $request, Role $role): void
    {
        if (
            $request->user()->role?->name !== 'SUPER_ADMIN'
            && !in_array($role->name, self::CLINIC_ROLES, true)
        ) {
            abort(403, 'Le directeur de clinique ne peut pas attribuer ce rôle.');
        }
    }

    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'clinic_id' => $user->clinic_id,
            'clinic_name' => $user->clinic?->name,
            'clinic_status' => $user->clinic?->status,
            'role_id' => $user->role_id,
            'role' => $user->role?->name,
            'status' => $user->status,
            'totp_enabled' => $user->totp_enabled,
            'totp_required' => $user->requiresTotp(),
            'doctor_license' => $user->role?->name === 'DOCTOR' ? $user->doctorProfile?->license_number : null,
            'modules' => ModuleAccess::forUser($user),
            'permissions' => ModuleAccess::effectivePermissions($user),
        ];
    }
}
