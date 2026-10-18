<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Role, App\Models\Permission, App\Models\User;

class RBACTest extends TestCase {
    use RefreshDatabase;

    public function test_roles_seeded(): void {
        $this->seed();
        $this->assertDatabaseCount('roles', 11);
    }

    public function test_permissions_seeded(): void {
        $this->seed();
        $expected = \Database\Seeders\PermissionSeeder::definitions();
        $this->assertDatabaseCount('permissions', count($expected));
        foreach ($expected as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission['name']]);
        }
    }

    public function test_role_has_permissions(): void {
        $this->seed();
        $doctor = Role::where('name', 'DOCTOR')->first();
        $this->assertGreaterThan(0, $doctor->permissions()->count());
    }

    public function test_super_admin_manages_the_platform_without_medical_access(): void {
        $this->seed();
        $permissions = Role::where('name', 'SUPER_ADMIN')->first()->permissions()->pluck('name')->all();

        foreach (['manage:clinics', 'manage:subscriptions', 'view:saas_dashboard'] as $platformPermission) {
            $this->assertContains($platformPermission, $permissions);
        }
        foreach (['read:patient', 'read:dpe', 'read:consultation', 'read:prescription', 'read:lab_request', 'read:imaging', 'read:invoice', 'read:vitals'] as $medicalPermission) {
            $this->assertNotContains($medicalPermission, $permissions);
        }
    }
}
