<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|max:255',
            'password' => 'required|string|min:12',
        ]);

        try {
            $result = $this->authService->login($validated);

            if (isset($result['requires_totp'])) {
                return response()->json($result, 202);
            }

            return response()->json([
                'message' => 'Login successful',
                ...$result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function signup(Request $request)
    {
        return response()->json([
            'message' => 'Public registration is disabled. Ask your clinic administrator for an account.',
        ], 403);
    }

    public function verifyTotp(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => 'required|string|size:64',
            'totp_code' => 'required|numeric|digits:6',
        ]);

        try {
            $result = $this->authService->verifyTotp(
                $validated['challenge_token'],
                $validated['totp_code']
            );

            return response()->json([
                'message' => '2FA verified',
                ...$result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function enableTotaSetup(Request $request)
    {
        $user = $request->user();
        $result = $this->authService->enableTotp($user);

        return response()->json([
            'message' => 'Add this account to your authenticator app.',
            ...$result,
        ]);
    }

    public function confirmTotp(Request $request)
    {
        $validated = $request->validate([
            'totp_code' => 'required|numeric|digits:6',
        ]);

        try {
            $user = $request->user();
            $this->authService->confirmTotp($user, $validated['totp_code']);

            return response()->json(['message' => '2FA enabled successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function disableTotp(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'totp_code' => 'required|numeric|digits:6',
        ]);
        $user = $request->user();

        if ($user->requiresTotp()) {
            return response()->json(['message' => 'La double authentification est obligatoire pour votre rôle et ne peut pas être désactivée.'], 422);
        }
        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        try {
            $this->authService->disableTotp($user, $validated['totp_code']);
            return response()->json(['message' => 'Two-factor authentication disabled.']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function logout(Request $request)
    {
        $this->authService->logout($request->user());
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $result = $this->authService->refresh($user);

        return response()->json([
            'message' => 'Token refreshed',
            ...$result,
        ]);
    }
}
