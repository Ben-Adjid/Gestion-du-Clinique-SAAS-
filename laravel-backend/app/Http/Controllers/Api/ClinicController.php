<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use Illuminate\Http\Request;

class ClinicController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // SUPER_ADMIN can see all clinics
        if ($user->role && $user->role->name === 'SUPER_ADMIN') {
            $clinics = Clinic::all();
            return response()->json(['data' => $clinics], 200);
        }

        // Other users can only see their own clinic
        $clinic = Clinic::find($user->clinic_id);
        return response()->json(['data' => [$clinic]], 200);
    }

    public function store(Request $request)
    {
        if ($request->user()->role?->name !== 'SUPER_ADMIN') {
            return response()->json(['message' => 'Only a super administrator can create clinics.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:clinics,name',
            'city' => 'required|string|max:120',
            'country' => 'required|string|max:120',
            'code' => 'required|string|max:40|alpha_dash:ascii|unique:clinics,code',
            'subscription_plan' => 'required|in:BASIC,PRO,ENTERPRISE',
            'admin_email' => 'required|email|max:255',
            'admin_phone' => 'nullable|string|max:30',
        ]);

        $clinic = Clinic::create([
            ...$validated,
            'code' => strtoupper($validated['code']),
            'status' => 'ACTIVE',
        ]);

        return response()->json(['data' => $clinic], 201);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $clinic = Clinic::findOrFail($id);

        // Check access
        if ($user->role && $user->role->name !== 'SUPER_ADMIN' && $clinic->id !== $user->clinic_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return response()->json(['data' => $clinic], 200);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $clinic = Clinic::findOrFail($id);

        // Only ADMIN_CLINIC or SUPER_ADMIN can update
        if (!$user->role || !in_array($user->role->name, ['ADMIN_CLINIC', 'SUPER_ADMIN'])) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Non-super-admin users can only update their own clinic
        if ($user->role->name !== 'SUPER_ADMIN' && $clinic->id !== $user->clinic_id) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        $rules = [
            'name' => 'sometimes|string|max:255|unique:clinics,name,'.$clinic->id,
            'city' => 'sometimes|string|max:120',
            'country' => 'sometimes|string|max:120',
            'admin_email' => 'sometimes|email|max:255',
            'admin_phone' => 'sometimes|nullable|string|max:30',
            // Legal details printed on the official receipts (quittances)
            'license_number' => 'sometimes|nullable|string|max:60',
            'address' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
        ];
        if ($user->role->name === 'SUPER_ADMIN') {
            $rules['code'] = 'sometimes|string|max:40|alpha_dash:ascii|unique:clinics,code,'.$clinic->id;
            $rules['subscription_plan'] = 'sometimes|in:BASIC,PRO,ENTERPRISE';
            $rules['status'] = 'sometimes|in:ACTIVE,INACTIVE,SUSPENDED';
        }

        $validated = $request->validate($rules);
        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        // Legal details live in the clinic settings (printed on receipts), the other fields are columns.
        $legal = array_intersect_key($validated, array_flip(['license_number', 'address', 'phone']));
        $validated = array_diff_key($validated, $legal);
        if ($legal !== []) {
            $validated['settings'] = array_merge($clinic->settings ?? [], $legal);
        }

        $clinic->update($validated);

        return response()->json(['message' => 'Clinic updated successfully', 'data' => $clinic], 200);
    }

    public function destroy(string $id)
    {
        return response()->json(['message' => 'Operation not allowed'], 403);
    }
}
