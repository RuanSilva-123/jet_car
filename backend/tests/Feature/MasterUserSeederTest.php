<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\MasterUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_master_user_from_config(): void
    {
        config(['jetcar.master' => [
            'name' => 'Master',
            'email' => 'Master@JetCar.test',
            'password' => 'secret-password',
        ]]);

        $this->seed(MasterUserSeeder::class);

        $master = User::sole();
        $this->assertSame('master@jetcar.test', $master->email);
        $this->assertSame(UserRole::Master, $master->role);
        $this->assertTrue($master->is_active);
        $this->assertTrue(Hash::check('secret-password', $master->password));
    }

    public function test_it_does_not_overwrite_an_existing_master(): void
    {
        config(['jetcar.master' => [
            'name' => 'Master',
            'email' => 'master@jetcar.test',
            'password' => 'first-password',
        ]]);
        $this->seed(MasterUserSeeder::class);

        config(['jetcar.master.password' => 'second-password']);
        $this->seed(MasterUserSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('first-password', User::sole()->password));
    }

    public function test_it_skips_when_credentials_are_missing(): void
    {
        config(['jetcar.master' => ['name' => 'Master', 'email' => null, 'password' => null]]);

        $this->seed(MasterUserSeeder::class);

        $this->assertSame(0, User::count());
    }
}
