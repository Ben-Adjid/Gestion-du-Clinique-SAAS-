<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Role names shown to users are in French, whatever the stored display name. */
class RoleLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_is_shown_with_a_french_label(): void
    {
        $this->seed([RoleSeeder::class]);

        $this->assertSame('Responsable RH', Role::where('name', 'HR_MANAGER')->first()->display_name);
        $this->assertSame('Technicien de laboratoire', Role::where('name', 'LABORATORY_TECHNICIAN')->first()->display_name);
        $this->assertSame('Responsable de clinique', Role::where('name', 'ADMIN_CLINIC')->first()->toArray()['display_name']);
    }
}
