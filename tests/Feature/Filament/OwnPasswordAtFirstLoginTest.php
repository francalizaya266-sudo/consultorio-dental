<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\ChoosePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** La contraseña que pone el administrador es temporal: cada persona elige la suya la primera vez que entra. */
class OwnPasswordAtFirstLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_user_created_by_the_administrator_must_choose_their_own_password(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)
            ->mountAction('create')
            ->assertMountedActionModalSee('Es solo para su primer ingreso: al entrar, el panel le pide elegir la suya.')
            ->setActionData(['name' => 'Rosa Quispe', 'email' => 'rosa@consultorio.test', 'role' => 'reception', 'password' => 'Temporal-2026'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $rosa = User::query()->where('email', 'rosa@consultorio.test')->sole();
        $this->assertTrue($rosa->must_change_password);

        // En la lista se ve quién todavía no la eligió.
        Livewire::test(ManageUsers::class)->assertTableColumnHasDescription('name', 'rosa@consultorio.test · Todavía no eligió su contraseña', $rosa);

        // Entra con la temporal: cualquier pantalla la lleva a elegir la suya.
        $this->actingAs($rosa);
        $this->get(Dashboard::getUrl())->assertRedirect(ChoosePassword::getUrl());
        $this->get(Filament::getPanel('admin')->route('agenda.print'))->assertRedirect(ChoosePassword::getUrl());
        $this->get(ChoosePassword::getUrl())->assertOk()->assertSee(['Elige tu contraseña', 'La contraseña con la que entraste te la puso otra persona.']);

        Livewire::test(ChoosePassword::class)
            // No vale repetir la que le dieron, ni una débil, ni escribirla distinto las dos veces.
            ->fillForm(['password' => 'Temporal-2026', 'passwordConfirmation' => 'Temporal-2026'])
            ->call('save')
            ->assertHasFormErrors(['password'])
            ->assertSee('Elige una distinta de la que te dieron.')
            ->fillForm(['password' => 'corta', 'passwordConfirmation' => 'corta'])
            ->call('save')
            ->assertHasFormErrors(['password'])
            ->fillForm(['password' => 'Solo-mia-2026', 'passwordConfirmation' => 'Otra-cosa-2026'])
            ->call('save')
            ->assertHasFormErrors(['passwordConfirmation'])
            ->fillForm(['password' => 'Solo-mia-2026', 'passwordConfirmation' => 'Solo-mia-2026'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Contraseña guardada')
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        $rosa->refresh();
        $this->assertFalse($rosa->must_change_password);
        $this->assertTrue(Hash::check('Solo-mia-2026', $rosa->password));

        // Ya entra a todo, y la pantalla de elegir la devuelve al panel.
        $this->get(Dashboard::getUrl())->assertOk();
        $this->get(ChoosePassword::getUrl())->assertRedirect(Filament::getPanel('admin')->getUrl());

        // Queda que la cambió ella, no qué puso.
        $own = AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $rosa->id)->where('event', 'password_changed')->sole();
        $this->assertSame($rosa->id, $own->user_id);
    }

    public function test_a_password_changed_by_someone_else_is_temporary_and_ones_own_is_not(): void
    {
        $admin = User::factory()->admin()->create();
        $rosa = User::factory()->reception()->create();
        $this->actingAs($admin);

        // Guardar su ficha sin tocar la contraseña no le pide nada.
        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($rosa), data: ['name' => 'Rosa Quispe'])
            ->assertHasNoActionErrors();
        $this->assertFalse($rosa->refresh()->must_change_password);

        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($rosa))
            ->assertMountedActionModalSee('Si le pones una nueva, al entrar el panel le pide elegir la suya.')
            ->setActionData(['password' => 'Temporal-2026'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertTrue($rosa->refresh()->must_change_password);

        // La que el administrador se pone a sí mismo ya es la suya.
        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($admin))
            ->assertMountedActionModalDontSee('al entrar el panel le pide elegir la suya')
            ->setActionData(['password' => 'Mia-de-verdad-2026'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertFalse($admin->refresh()->must_change_password);
    }

    public function test_the_access_created_with_a_doctor_and_the_emergency_password_are_temporary_too(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)
            ->fillForm(['name' => 'Dr. Luis Ramos', 'access' => 'new', 'access_email' => 'luis@consultorio.test', 'access_password' => 'Temporal-2026'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Doctor::query()->where('name', 'Dr. Luis Ramos')->sole()->user()->sole()->must_change_password);

        $locked = User::factory()->admin()->create(['email' => 'duena@consultorio.test']);

        $this->artisan('usuario:clave', ['correo' => 'duena@consultorio.test'])
            ->expectsOutputToContain('Al entrar con ella, el panel le pide elegir la suya.')
            ->assertSuccessful();

        $this->assertTrue($locked->refresh()->must_change_password);

        // Quien pone su contraseña con el enlace del correo ya la eligió.
        event(new PasswordReset($locked));

        $this->assertFalse($locked->refresh()->must_change_password);
    }

    public function test_leaving_without_choosing_closes_the_session(): void
    {
        $this->actingAs(User::factory()->reception()->create(['must_change_password' => true]));

        Livewire::test(ChoosePassword::class)
            ->call('leave')
            ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

        $this->assertGuest();
    }

    public function test_the_role_shows_what_each_one_can_see_before_choosing(): void
    {
        $doctor = Doctor::factory()->create()->user;
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)
            ->mountAction('create')
            ->assertMountedActionModalSee(['Administrador', 'Todo el panel: agenda, pacientes, caja, reportes, inventario y Configuración.', 'Recepción', 'No ve la historia clínica, los reportes ni Configuración.'])
            // Aquí no se crea un doctor: va en Doctores, con su ficha.
            ->assertMountedActionModalDontSee('Su agenda, sus pacientes y la historia clínica')
            ->unmountAction()
            ->mountAction(TestAction::make('edit')->table($doctor))
            ->assertMountedActionModalSee('Su agenda, sus pacientes y la historia clínica: atiende, arma planes y receta. No ve la caja del consultorio.');
    }
}
