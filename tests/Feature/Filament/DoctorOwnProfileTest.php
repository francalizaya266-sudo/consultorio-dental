<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** En "Mi perfil", cada doctor cambia cómo sale en la web (foto, presentación, formación) y ve su horario sin poder cambiarlo. */
class DoctorOwnProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('web');
    }

    public function test_a_doctor_changes_their_photo_presentation_and_education_from_my_profile(): void
    {
        $doctor = Doctor::factory()->create(['name' => 'Dra. Ana Torres', 'bio' => 'Atiendo adultos.', 'education' => null]);
        $this->actingAs($doctor->user);

        Livewire::test(EditProfile::class)
            ->assertSee(['Cómo sales en la página web', 'Tu horario de atención'])
            ->assertSchemaStateSet(['doctor.bio' => 'Atiendo adultos.', 'doctor.education' => null])
            ->fillForm(['doctor' => [
                'photo_path' => UploadedFile::fake()->image('ana.jpg', 900, 900),
                'bio' => 'Atiendo a niños y a adultos con miedo al dentista.',
                'education' => "Cirujana dentista, UNMSM\nEspecialidad en Odontopediatría",
            ]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $doctor->refresh();
        $this->assertSame(
            ['Atiendo a niños y a adultos con miedo al dentista.', "Cirujana dentista, UNMSM\nEspecialidad en Odontopediatría"],
            [$doctor->bio, $doctor->education],
        );
        $this->assertStringStartsWith('equipo/', $doctor->photo_path);
        Storage::disk('web')->assertExists($doctor->photo_path);

        // El administrador ve en el historial que lo cambió la doctora.
        $change = AuditLog::query()->where('auditable_type', Doctor::class)->where('event', 'updated')->sole();
        $this->assertSame($doctor->user_id, $change->user_id);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageAuditLogs::class)
            ->assertTableColumnStateSet('user.name', $doctor->user->name, $change)
            ->assertTableColumnStateSet('subject', 'Dra. Ana Torres', $change);
    }

    public function test_what_the_admin_decides_about_a_doctor_cannot_be_changed_from_my_profile(): void
    {
        $doctor = Doctor::factory()->withSchedule([['09:00', '13:00']], [1])->create([
            'name' => 'Dra. Ana Torres', 'specialty' => 'Ortodoncia', 'cop_number' => '12345', 'is_public' => false,
        ]);
        $owner = $doctor->user_id;
        $this->actingAs($doctor->user);

        // Aunque alguien arme el pedido a mano con datos que la hoja no tiene.
        Livewire::test(EditProfile::class)
            ->assertFormFieldDoesNotExist('doctor.specialty')
            ->assertFormFieldDoesNotExist('schedules')
            ->set('data.doctor.name', 'Dra. Otra')
            ->set('data.doctor.specialty', 'Implantología')
            ->set('data.doctor.cop_number', '99999')
            ->set('data.doctor.is_public', true)
            ->set('data.doctor.user_id', User::factory()->create()->id)
            ->set('data.doctor.bio', 'Hola.')
            ->call('save')
            ->assertHasNoFormErrors();

        $doctor->refresh();
        $this->assertSame(
            ['Dra. Ana Torres', 'Ortodoncia', '12345', false, $owner, 'Hola.'],
            [$doctor->name, $doctor->specialty, $doctor->cop_number, $doctor->is_public, $doctor->user_id, $doctor->bio],
        );

        // Su ficha en Configuración › Doctores, con el horario para cambiar, sigue siendo del administrador.
        $this->get(DoctorResource::getUrl('edit', ['record' => $doctor]))->assertForbidden();
        $this->assertSame(1, $doctor->schedules()->count());
    }

    public function test_a_doctor_reads_their_schedule_with_its_seasons(): void
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::create(['doctor_id' => $doctor->id, 'weekday' => 1, 'starts_at' => '15:00', 'ends_at' => '19:00']);
        DoctorSchedule::create(['doctor_id' => $doctor->id, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00']);
        DoctorSchedule::create(['doctor_id' => $doctor->id, 'weekday' => 6, 'starts_at' => '09:00', 'ends_at' => '12:00', 'valid_from' => '2026-12-15', 'valid_until' => '2027-03-15']);
        $this->actingAs($doctor->user);

        Livewire::test(EditProfile::class)
            ->assertSeeInOrder([
                'Tu horario de atención',
                'Lo cambia el administrador',
                'Lunes: 09:00–13:00 y 15:00–19:00',
                'Sábado: 09:00–12:00 (solo del 15/12/2026 al 15/03/2027)',
            ])
            ->assertDontSee('Todavía no tienes horario');

        // Sin horario, lo dice: así no se le pueden dar citas.
        $this->actingAs(Doctor::factory()->create()->user);

        Livewire::test(EditProfile::class)->assertSee('Todavía no tienes horario: no se te pueden dar citas.');
    }

    public function test_only_who_has_a_doctor_profile_sees_that_part_of_my_profile(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(EditProfile::class)
            ->assertDontSee(['Cómo sales en la página web', 'Tu horario de atención'])
            ->fillForm(['name' => 'Rosa Quispe'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Rosa Quispe', auth()->user()->fresh()->name);

        // La administradora que también atiende tiene ficha de doctora: también cambia la suya.
        $admin = User::factory()->admin(isDentist: true)->create();
        $own = Doctor::factory()->create(['user_id' => $admin->id, 'bio' => null]);
        $other = Doctor::factory()->create(['bio' => 'No me toques.']);
        $this->actingAs($admin);

        Livewire::test(EditProfile::class)
            ->assertSee('Cómo sales en la página web')
            ->fillForm(['doctor' => ['bio' => 'Dirijo el consultorio y atiendo implantes.']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Dirijo el consultorio y atiendo implantes.', $own->fresh()->bio);
        $this->assertSame('No me toques.', $other->fresh()->bio);
    }
}
