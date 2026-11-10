<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Team members with custom admin roles must show up in the team list and reach the admin portal. */
class AdminTeamListTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_custom_admin_role_is_listed_and_is_an_admin_user(): void
    {
        Mail::fake();
        $admin = User::forceCreate([
            'name' => 'Admin', 'email' => 'admin@team.test', 'password' => Hash::make('x'),
            'role' => 'fitaccess_admin', 'is_active' => true,
        ]);
        Role::create(['name' => 'admin_operations', 'label' => 'Operations', 'scope' => 'admin', 'is_system' => false]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/team', ['name' => 'Ops Person', 'email' => 'ops@team.test', 'role' => 'admin_operations'])
            ->assertSuccessful();
        $this->postJson('/api/v1/admin/team', ['name' => 'Finance Person', 'email' => 'fin@team.test', 'role' => 'admin_finance'])
            ->assertSuccessful();

        $emails = collect($this->getJson('/api/v1/admin/team')->assertOk()->json())->pluck('email');
        $this->assertTrue($emails->contains('ops@team.test'), 'custom-role member is listed');
        $this->assertTrue($emails->contains('fin@team.test'), 'built-in-role member is listed');

        $ops = User::where('email', 'ops@team.test')->first();
        $this->assertTrue($ops->isAdmin());
        Sanctum::actingAs($ops);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('admin_portal', true);
    }
}
