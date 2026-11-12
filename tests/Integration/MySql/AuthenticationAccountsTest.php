<?php

namespace Tests\Integration\MySql;

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\MySql\Support\AccountFixtures;
use Tests\MySqlTestCase;

#[Group('mysql-accounts')]
final class AuthenticationAccountsTest extends MySqlTestCase
{
    use AccountFixtures;

    #[DataProvider('roles')]
    public function test_active_accounts_authenticate_against_mysql_and_regenerate_the_session(string $role): void
    {
        $user = $this->account($role);
        $this->withSession(['test_marker' => 'retained']);
        $oldSession = session()->getId();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertSet('password', '')->assertRedirect(route($role.'.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->get(route($role.'.dashboard'))->assertOk();
    }

    public static function roles(): array
    {
        return [['admin'], ['client']];
    }

    public function test_wrong_password_and_unknown_email_are_rejected_generically(): void
    {
        $user = $this->account();
        foreach ([[$user->email, 'wrong-password'], ['missing@example.test', 'password']] as [$email, $password]) {
            Livewire::test(Login::class)->set('email', $email)->set('password', $password)->call('login')
                ->assertHasErrors('email')->assertSee('These credentials do not match our records.');
            $this->assertGuest();
        }
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_authenticate_or_use_existing_sessions(string $role): void
    {
        $user = $this->account($role);
        $sessionKey = Auth::guard()->getName();
        $this->actingAs($user)->withSession([$sessionKey => $user->id])->get(route($role.'.dashboard'))->assertOk();
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->get(route($role.'.dashboard'))->assertForbidden();
        Auth::logout();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    #[DataProvider('ineligibleRegistrations')]
    public function test_pending_rejected_and_unverified_accounts_never_gain_access(string $state, bool $active, bool $verified): void
    {
        $user = $this->applicant('admin', ['registration_status' => $state, 'is_active' => $active, 'email_verified_at' => $verified ? now() : null]);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get(route('client.dashboard'))->assertForbidden();
    }

    public static function ineligibleRegistrations(): array
    {
        return [['pending', false, false], ['pending', true, true], ['rejected', false, true], ['rejected', true, true], ['approved', true, false]];
    }

    public function test_current_two_role_boundaries_apply_to_management_and_portal_routes(): void
    {
        $admin = $this->account('admin');
        $staff = $this->account();
        $this->assertSame(['admin', 'client'], array_column(UserRole::cases(), 'value'));
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($staff->isClient());
        $this->assertFalse($staff->isAdmin());
        foreach (['admin.dashboard', 'admin.users.index', 'admin.clients.index', 'super-admin.registrations.index', 'super-admin.administrators.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }
        $this->actingAs($staff)->get(route('client.dashboard'))->assertOk()->assertSee('Staff Dashboard');
        $this->actingAs($admin)->get(route('client.dashboard'))->assertForbidden();
    }

    public function test_guests_cannot_use_account_management_or_either_dashboard(): void
    {
        foreach (['admin.dashboard', 'client.dashboard', 'admin.users.index', 'super-admin.registrations.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }
}
