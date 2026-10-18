<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_without_revealing_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.test'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:3000/reset-password?token=');
        });
    }

    public function test_password_can_be_reset_and_sessions_are_revoked(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword@123']);
        $user->createToken('session');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass@456',
            'password_confirmation' => 'BrandNewPass@456',
        ])->assertOk();

        $this->assertTrue(password_verify('BrandNewPass@456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_recent_password_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => 'FirstPassword@123']);
        $user->forceFill(['password' => 'SecondPassword@123'])->save();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'FirstPassword@123',
            'password_confirmation' => 'FirstPassword@123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'BrandNewPass@456',
            'password_confirmation' => 'BrandNewPass@456',
        ])->assertUnprocessable();
    }

    public function test_weak_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
