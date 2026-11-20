<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Opening a medical record leaves a READ trace that says which record, by whom, in which clinic. */
class AuditReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opening_a_patient_record_is_traced_with_its_resource_and_id(): void
    {
        $clinic = $this->clinic('Clinique Trace', 'TRC');
        $doctor = $this->user($clinic, 'DOCTOR');
        $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'TRC-2026-000001', 'first_name' => 'Aminata', 'last_name' => 'Test', 'date_of_birth' => '1990-05-05']);

        $this->actingAs($doctor, 'sanctum')->getJson("/api/v1/patients/{$patient->id}")->assertOk();

        $trace = AuditLog::query()->where('action', 'READ')->where('resource', 'PATIENT')->where('resource_id', $patient->id)->first();
        $this->assertNotNull($trace, 'Le dossier ouvert doit laisser une trace READ.');
        $this->assertSame($doctor->id, $trace->user_id);
        $this->assertSame($clinic->id, $trace->clinic_id);
    }

    private function clinic(string $name, string $code): Clinic
    {
        $clinic = Clinic::create([
            'name' => $name, 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'PRO', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);

        return $clinic;
    }

    private function user(Clinic $clinic, string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
