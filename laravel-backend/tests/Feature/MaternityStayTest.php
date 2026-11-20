<?php

namespace Tests\Feature;

use App\Models\Admission;
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

/** A maternity stay from admission to discharge: two patients in one room, a transfer, then the discharge. */
class MaternityStayTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $midwife;
    private User $nurse;
    private Room $maternity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Maternité', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'MAT',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'mat@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->midwife = $this->user('DOCTOR');
        $this->nurse = $this->user('NURSE');
        $this->maternity = Room::create(['clinic_id' => $this->clinic->id, 'name' => 'Maternité', 'service' => 'MATERNITE', 'capacity' => 2]);
        foreach (['MAT-01', 'MAT-02', 'MAT-03'] as $code) {
            Bed::create(['clinic_id' => $this->clinic->id, 'room_id' => $this->maternity->id, 'code' => $code, 'ward' => 'Maternité', 'status' => 'AVAILABLE']);
        }
    }

    private function user(string $role): User
    {
        return User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', $role)->value('id'), 'status' => 'ACTIVE']);
    }

    private function patient(string $unique): Patient
    {
        return Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => $unique, 'first_name' => 'Patiente', 'last_name' => $unique, 'gender' => 'F', 'date_of_birth' => '1994-02-02']);
    }

    private function bed(string $code): Bed
    {
        return Bed::where('code', $code)->firstOrFail();
    }

    public function test_two_patients_share_the_maternity_room_then_one_is_moved_and_discharged(): void
    {
        $first = $this->patient('MAT-2026-000001');
        $second = $this->patient('MAT-2026-000002');

        // Two patients in the same room, each in her own bed.
        $this->actingAs($this->midwife, 'sanctum')->postJson("/api/v1/beds/{$this->bed('MAT-01')->id}/assign", ['patient_id' => $first->id])->assertOk();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$this->bed('MAT-02')->id}/assign", ['patient_id' => $second->id])->assertOk();
        $this->assertSame(2, Bed::where('room_id', $this->maternity->id)->where('status', 'OCCUPIED')->count());

        // The first patient moves to the free bed; the bed she leaves is cleaned, not free at once.
        $stay = Admission::where('patient_id', $first->id)->where('status', 'ADMIS')->firstOrFail();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/transfer", ['bed_id' => $this->bed('MAT-03')->id, 'reason' => 'Salle de travail'])
            ->assertOk();
        $this->assertSame('OCCUPIED', $this->bed('MAT-03')->status);
        $this->assertSame($first->id, $this->bed('MAT-03')->patient_id);
        $this->assertNotSame('OCCUPIED', $this->bed('MAT-01')->status);

        // Discharge: the nurse writes the summary, the stay closes.
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/discharge", [
            'discharge_summary' => 'Accouchement eutocique, nouveau-né en bonne santé, sortie à J2.',
        ])->assertOk();
        $this->assertSame('SORTI', $stay->fresh()->status);
    }

    public function test_a_reception_account_cannot_discharge_a_patient(): void
    {
        $patient = $this->patient('MAT-2026-000003');
        $this->actingAs($this->midwife, 'sanctum')->postJson("/api/v1/beds/{$this->bed('MAT-01')->id}/assign", ['patient_id' => $patient->id])->assertOk();
        $stay = Admission::where('patient_id', $patient->id)->firstOrFail();

        $this->actingAs($this->user('RECEPTIONIST'), 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/discharge", [
            'discharge_summary' => 'Sortie demandée par l’accueil, ce texte ne doit pas passer.',
        ])->assertForbidden();
        $this->assertSame('ADMIS', $stay->fresh()->status);
    }
}
