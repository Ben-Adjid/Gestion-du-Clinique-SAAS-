<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_access_seeder_is_repeatable_and_credentials_can_log_in(): void
    {
        Artisan::call('db:seed', [
            '--class' => \Database\Seeders\DemoAccessSeeder::class,
            '--no-interaction' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => \Database\Seeders\DemoAccessSeeder::class,
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, User::where('email', 'superadmin@example.com')->count());
        $this->assertTrue(Hash::check('Password@12345', User::where('email', 'superadmin@example.com')->firstOrFail()->password));
        $this->assertNotNull(User::where('email', 'patient@example.com')->firstOrFail()->patient);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'superadmin@example.com',
            'password' => 'Password@12345',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.clinic1@example.com',
            'password' => 'Password@12345',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'doctor.clinic1@example.com',
            'password' => 'Password@12345',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nurse.clinic1@example.com',
            'password' => 'Password@12345',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'patient@example.com',
            'password' => 'Password@12345',
        ])->assertOk();
    }
}
