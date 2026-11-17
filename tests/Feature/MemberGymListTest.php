<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The member app's gym list shows only the gyms the member's package opens. */
class MemberGymListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['basic' => 'Basic Gym', 'basic_plus' => 'Plus Gym', 'premium' => 'Premium Gym', 'platinum' => 'Old Premium Gym'] as $tier => $name) {
            Gym::forceCreate(['name' => $name, 'address' => 'Bole', 'tier' => $tier, 'is_active' => true]);
        }
    }

    /** @return array<string, array{string, string, string[]}> */
    public static function packages(): array
    {
        return [
            'Fit Basic (staff)'        => ['staff', 'basic', ['Basic Gym']],
            'Fit Basic Plus (director)' => ['director', 'basic_plus', ['Basic Gym', 'Plus Gym']],
            'Fit Premium (chief)'      => ['chief', 'premium', ['Basic Gym', 'Old Premium Gym', 'Plus Gym', 'Premium Gym']],
        ];
    }

    /** @dataProvider packages */
    public function test_only_the_gyms_of_the_package(string $level, string $tier, array $names): void
    {
        $company = Company::forceCreate(['name' => 'Ethio Telecom', 'contact_email' => 'hr@et.test', 'contact_person' => 'HR', 'contact_phone' => '1', 'address' => 'a', 'city' => 'Addis Ababa']);
        $user = User::forceCreate(['name' => 'A', 'email' => "$level@et.test", 'password' => 'x', 'role' => 'employee', 'is_active' => true, 'company_id' => $company->id]);
        Employee::forceCreate(['user_id' => $user->id, 'company_id' => $company->id, 'fan_number' => "FAN-$level", 'level' => $level]);
        Sanctum::actingAs($user);

        $r = $this->getJson('/api/v1/mobile/gyms?per_page=50')->assertOk()->assertJsonPath('meta.member_tier', $tier);
        $got = collect($r->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame($names, $got);
        // Spellings are normalised for the app (platinum → premium).
        $this->assertNotContains('platinum', collect($r->json('data'))->pluck('tier')->all());
    }
}
