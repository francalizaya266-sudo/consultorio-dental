<?php

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Doctor;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** En Usuarios nadie se deja a sí mismo sin acceso, ni vuelve doctor a alguien que no tiene ficha de doctor. */
class UserAccessSafeguardsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_admin_cannot_take_away_their_own_role_or_access(): void
    {
        $admin = User::factory()->admin()->create();
        $colleague = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($admin))
            ->assertMountedActionModalSee('Tu propio acceso y tu rol solo los puede cambiar otro administrador.')
            ->setActionData(['name' => 'Ana Admin', 'role' => UserRole::Reception->value, 'is_active' => false])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        // Su nombre sí cambia; su rol y su acceso, no.
        $admin->refresh();
        $this->assertSame(['Ana Admin', UserRole::Admin, true], [$admin->name, $admin->role, $admin->is_active]);

        // A otro administrador sí se los puede cambiar.
        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($colleague))
            ->assertMountedActionModalDontSee('Tu propio acceso y tu rol solo los puede cambiar otro administrador.')
            ->setActionData(['role' => UserRole::Reception->value, 'is_active' => false])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $colleague->refresh();
        $this->assertSame([UserRole::Reception, false], [$colleague->role, $colleague->is_active]);
    }

    public function test_nobody_becomes_a_doctor_from_the_users_page(): void
    {
        $reception = User::factory()->reception()->create();
        $doctor = Doctor::factory()->create()->user;
        $this->actingAs(User::factory()->admin()->create());

        // Ni al darlo de alta ni al cambiarle el rol: un doctor se crea en Doctores, con su ficha.
        Livewire::test(ManageUsers::class)
            ->callAction('create', data: ['name' => 'Luis Paz', 'email' => 'luis@consultorio.test', 'role' => UserRole::Doctor->value, 'password' => 'clave-segura-1'])
            ->assertHasActionErrors(['role']);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($reception), data: ['role' => UserRole::Doctor->value])
            ->assertHasActionErrors(['role']);

        $this->assertSame(UserRole::Reception, $reception->fresh()->role);
        $this->assertSame(0, User::query()->where('email', 'luis@consultorio.test')->count());

        // Quien ya es doctor conserva su rol al editarle otros datos.
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($doctor), data: ['name' => 'Dra. Ana Torres'])
            ->assertHasNoActionErrors();

        $this->assertSame(['Dra. Ana Torres', UserRole::Doctor], [$doctor->fresh()->name, $doctor->fresh()->role]);
    }

    public function test_a_password_needs_letters_and_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)
            ->callAction('create', data: ['name' => 'Rosa Recepción', 'email' => 'rosa@consultorio.test', 'role' => UserRole::Reception->value, 'password' => 'solamenteletras'])
            ->assertHasActionErrors(['password']);

        Livewire::test(ManageUsers::class)
            ->callAction('create', data: ['name' => 'Rosa Recepción', 'email' => 'rosa@consultorio.test', 'role' => UserRole::Reception->value, 'password' => 'letras-y-2026'])
            ->assertHasNoActionErrors();

        $this->assertSame(UserRole::Reception, User::query()->where('email', 'rosa@consultorio.test')->sole()->role);
    }

    public function test_switching_someones_access_says_what_happened(): void
    {
        $reception = User::factory()->reception()->create(['name' => 'Rosa Recepción']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)
            ->call('updateTableColumnState', 'is_active', (string) $reception->id, false)
            ->assertNotified('Rosa Recepción ya no puede entrar al panel');

        $this->assertFalse($reception->fresh()->is_active);
    }
}
