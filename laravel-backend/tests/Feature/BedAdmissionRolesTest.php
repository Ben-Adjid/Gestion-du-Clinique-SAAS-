<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Who may install a patient in a bed: the doctor, the nurse and the clinic administrator. */
class BedAdmissionRolesTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Bed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Lits', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'LIT',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'lit@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $room = Room::create(['clinic_id' => $this->clinic->id, 'name' => 'Maternité', 'service' => 'MATERNITE', 'capacity' => 1]);
        $this->bed = Bed::create(['clinic_id' => $this->clinic->id, 'room_id' => $room->id, 'code' => 'MAT-01', 'ward' => 'Maternité', 'status' => 'AVAILABLE']);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', $role)->value('id'), 'status' => 'ACTIVE']);
    }

    private function patient(string $unique): Patient
    {
        return Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => $unique, 'first_name' => 'Patiente', 'last_name' => 'Test', 'gender' => 'F', 'date_of_birth' => '1995-03-03']);
    }

    public function test_a_doctor_installs_a_patient_in_a_bed(): void
    {
        $doctor = $this->user('DOCTOR');
        $this->actingAs($doctor, 'sanctum')->postJson("/api/v1/beds/{$this->bed->id}/assign", ['patient_id' => $this->patient('LIT-2026-000001')->id])
            ->assertOk();
        $this->assertSame('OCCUPIED', $this->bed->fresh()->status);
    }

    public function test_a_nurse_installs_a_patient_in_a_bed(): void
    {
        $nurse = $this->user('NURSE');
        $this->actingAs($nurse, 'sanctum')->postJson("/api/v1/beds/{$this->bed->id}/assign", ['patient_id' => $this->patient('LIT-2026-000002')->id])
            ->assertOk();
    }

    public function test_reception_and_patients_cannot_install_a_patient(): void
    {
        $patient = $this->patient('LIT-2026-000003');
        $this->actingAs($this->user('RECEPTIONIST'), 'sanctum')->postJson("/api/v1/beds/{$this->bed->id}/assign", ['patient_id' => $patient->id])->assertForbidden();
        $this->actingAs($this->user('PATIENT'), 'sanctum')->postJson("/api/v1/beds/{$this->bed->id}/assign", ['patient_id' => $patient->id])->assertForbidden();
        $this->assertSame('AVAILABLE', $this->bed->fresh()->status);
    }
}
