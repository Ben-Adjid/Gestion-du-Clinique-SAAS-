<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

class AuthService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function login(array $credentials): array
    {
        $user = User::where('email', $credentials['email'])
            ->orWhere('phone', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new \RuntimeException('Credentials invalid', 401);
        }
        if ($user->status !== 'ACTIVE') {
            throw new \RuntimeException('Account inactive', 403);
        }
        $canSettleSubscription = $user->clinic?->status === 'SUSPENDED' && $user->role?->name === 'ADMIN_CLINIC';
        if ($user->clinic && $user->clinic->status !== 'ACTIVE' && !$canSettleSubscription) {
            throw new \RuntimeException('Votre clinique est suspendue ou inactive.', 403);
        }

        if ($user->totp_enabled) {
            $challengeToken = Str::random(64);
            Cache::put(
                $this->totpChallengeKey($challengeToken),
                ['user_id' => $user->id, 'attempts' => 0],
                now()->addMinutes(5),
            );

            return [
                'requires_totp' => true,
                'challenge_token' => $challengeToken,
                'expires_in' => 300,
            ];
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        return $this->generateToken($user);
    }

    public function verifyTotp(string $challengeToken, string $totpCode): array
    {
        $key = $this->totpChallengeKey($challengeToken);
        $challenge = Cache::get($key);
        if (!is_array($challenge) || !isset($challenge['user_id'], $challenge['attempts'])) {
            throw new \RuntimeException('The two-factor challenge has expired', 401);
        }
        if ($challenge['attempts'] >= 5) {
            Cache::forget($key);
            throw new \RuntimeException('Too many verification attempts', 429);
        }

        $user = User::find($challenge['user_id']);
        if (!$user || $user->status !== 'ACTIVE') {
            Cache::forget($key);
            throw new \RuntimeException('Invalid two-factor challenge', 401);
        }

        [$secret, $wasLegacyPlaintext] = $this->readTotpSecret($user->totp_secret);
        if (!$user->totp_enabled || !$this->google2fa->verifyKey($secret, $totpCode)) {
            Cache::put($key, [
                'user_id' => $user->id,
                'attempts' => $challenge['attempts'] + 1,
            ], now()->addMinutes(5));
            throw new \RuntimeException('Invalid TOTP code', 401);
        }

        Cache::forget($key);
        if ($wasLegacyPlaintext) {
            $user->update(['totp_secret' => Crypt::encryptString($secret)]);
        }
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        return $this->generateToken($user);
    }

    public function generateToken(User $user): array
    {
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => ((int) config('sanctum.expiration', 480)) * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'clinic_id' => $user->clinic_id,
                'role_id' => $user->role_id,
                'role' => $user->role?->name,
                'status' => $user->status,
                'totp_enabled' => $user->totp_enabled,
                'totp_required' => $user->requiresTotp(),
            ],
        ];
    }

    public function enableTotp(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        Cache::put($this->totpSetupKey($user), $secret, now()->addMinutes(10));

        return [
            'secret' => $secret,
            'otpauth_uri' => sprintf(
                'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
                rawurlencode('Clinique+'),
                rawurlencode($user->email),
                $secret,
                rawurlencode('Clinique+'),
            ),
        ];
    }

    public function confirmTotp(User $user, string $totpCode): void
    {
        $key = $this->totpSetupKey($user);
        $secret = Cache::get($key);
        if (!is_string($secret)) {
            throw new \RuntimeException('The two-factor setup has expired. Start again.', 401);
        }
        if (!$this->google2fa->verifyKey($secret, $totpCode)) {
            throw new \RuntimeException('Invalid TOTP code', 401);
        }

        $user->update([
            'totp_secret' => Crypt::encryptString($secret),
            'totp_enabled' => true,
        ]);
        Cache::forget($key);
    }

    public function disableTotp(User $user, string $totpCode): void
    {
        [$secret] = $this->readTotpSecret($user->totp_secret);
        if (!$user->totp_enabled || !$this->google2fa->verifyKey($secret, $totpCode)) {
            throw new \RuntimeException('Invalid TOTP code', 401);
        }

        $user->update([
            'totp_secret' => null,
            'totp_enabled' => false,
        ]);
    }

    public function logout(User $user): array
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ['message' => 'Logged out successfully'];
    }

    public function refresh(User $user): array
    {
        $currentToken = $user->currentAccessToken();
        $result = $this->generateToken($user);
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }

        return $result;
    }

    private function totpChallengeKey(string $challengeToken): string
    {
        return 'auth:totp-challenge:'.hash('sha256', $challengeToken);
    }

    private function totpSetupKey(User $user): string
    {
        return 'auth:totp-setup:'.$user->getKey();
    }

    /**
     * Supports one-time migration of existing plaintext secrets to encrypted storage.
     *
     * @return array{string, bool}
     */
    private function readTotpSecret(?string $secret): array
    {
        if ($secret === null || $secret === '') {
            return ['', false];
        }

        try {
            return [Crypt::decryptString($secret), false];
        } catch (DecryptException) {
            return [$secret, true];
        }
    }
}
