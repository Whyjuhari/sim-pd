<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_md5_user_can_log_in_and_reach_role_dashboard(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create([
            'username' => 'legacy-admin',
            'password' => md5('123456'),
        ]);

        $response = $this->post(route('login.attempt'), [
            'username' => 'legacy-admin',
            'password' => '123456',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(Hash::check('123456', $admin->fresh()->password));
        $this->assertNotSame(md5('123456'), $admin->fresh()->password);
        $this->get(route('dashboard'))->assertRedirect(route('dashboard.admin'));
        $this->get(route('dashboard.admin'))->assertOk()->assertSee('Administrasi SIM-PD');
    }

    public function test_role_middleware_blocks_another_dashboard(): void
    {
        $user = User::factory()->role(User::ROLE_USER)->create();

        $this->actingAs($user)->get(route('dashboard.admin'))->assertForbidden();
    }

    public function test_every_supported_role_reaches_its_own_dashboard(): void
    {
        $dashboards = [
            User::ROLE_ADMIN => 'dashboard.admin',
            User::ROLE_OFFICER => 'dashboard.officer',
            User::ROLE_PROGRAM => 'dashboard.program',
            User::ROLE_USER => 'dashboard.user',
            User::ROLE_VERIFIER => 'dashboard.verifier',
            User::ROLE_HEAD => 'dashboard.head',
        ];

        foreach ($dashboards as $role => $dashboardRoute) {
            $user = User::factory()->role($role)->create();
            $dashboard = route($dashboardRoute);

            $this->actingAs($user)->get('/dashboard')->assertRedirect($dashboard);
            $this->get($dashboard)->assertOk();
        }
    }

    public function test_invalid_login_is_rejected(): void
    {
        User::factory()->create(['username' => 'pegawai']);

        $this->post(route('login.attempt'), ['username' => 'pegawai', 'password' => 'salah'])
            ->assertSessionHas('login_error');
        $this->assertGuest();
    }

    public function test_legacy_login_and_dashboard_urls_remain_compatible_aliases(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create([
            'username' => 'admin-alias',
        ]);

        $this->post('/cek_login.php', [
            'username' => $admin->username,
            'password' => '123456',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard_admin.php')->assertRedirect(route('dashboard.admin'));
    }

    public function test_logout_is_post_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertMethodNotAllowed();
        $this->post(route('logout'))->assertRedirect(route('login', ['pesan' => 'logout']));
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        User::factory()->create(['username' => 'pegawai-throttle']);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.attempt'), [
                'username' => 'pegawai-throttle',
                'password' => 'salah',
            ])->assertSessionHas('login_error', 'Username atau Password salah.');
        }

        $this->post(route('login.attempt'), [
            'username' => 'pegawai-throttle',
            'password' => 'salah',
        ])->assertSessionHas('login_error', fn (string $message): bool => str_contains($message, 'Terlalu banyak percobaan login'));
    }
}
