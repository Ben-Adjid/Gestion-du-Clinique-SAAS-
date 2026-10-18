<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_signup_is_disabled(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', [
            'email' => 'newuser@example.com',
            'phone' => '+1234567890',
            'password' => 'SecurePass@1234',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'newuser@example.com']);
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('SecurePass@1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'SecurePass@1234',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);
    }

    public function test_login_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('SecurePass@1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'WrongPassword@123',
        ]);

        $response->assertStatus(401);
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
                         ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
    }

    public function test_totp_login_requires_a_short_lived_challenge(): void
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $secret = $google2fa->generateSecretKey();
        $user = User::factory()->create([
            'email' => 'totp@example.com',
            'password' => Hash::make('SecurePass@1234'),
            'totp_secret' => \Illuminate\Support\Facades\Crypt::encryptString($secret),
            'totp_enabled' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'SecurePass@1234',
        ]);

        $login->assertStatus(202)
            ->assertJsonStructure(['requires_totp', 'challenge_token', 'expires_in'])
            ->assertJsonMissingPath('user_id');

        $verification = $this->postJson('/api/v1/auth/verify-totp', [
            'challenge_token' => $login->json('challenge_token'),
            'totp_code' => $google2fa->getCurrentOtp($secret),
        ]);

        $verification->assertOk()->assertJsonStructure([
            'access_token',
            'user' => ['id', 'email', 'role'],
        ]);
    }
}
