<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Settings;
use App\Filament\Pages\WebSetup;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Support\ChangeHistory;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/** Historial de cambios de Configuración: quién cambió qué y cuándo, dicho con las palabras de cada pantalla. */
class ChangeHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_change_in_ajustes_is_kept_with_who_made_it_and_what_it_said_before(): void
    {
        $this->actingAs($admin = User::factory()->admin()->create(['name' => 'Carla Vega']));

        Livewire::test(Settings::class)
            ->fillForm(['booking' => ['horizon_days' => 30, 'min_lead_minutes' => 60], 'reports' => ['doctors_see_own' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        // La primera vez se guardan todos los ajustes de la hoja: al historial entran solo los que cambiaron.
        $changes = AuditLog::query()->with('auditable')->where('auditable_type', Setting::class)->get();
        $this->assertCount(3, $changes);
        $this->assertTrue($changes->every(fn (AuditLog $change) => $change->user_id === $admin->id && $change->event === 'updated'));

        $horizon = $changes->sole(fn (AuditLog $change) => $change->auditable->key === 'booking.horizon_days');
        $lead = $changes->sole(fn (AuditLog $change) => $change->auditable->key === 'booking.min_lead_minutes');
        $reports = $changes->sole(fn (AuditLog $change) => $change->auditable->key === 'reports.doctors_see_own');

        Livewire::test(ManageAuditLogs::class)
            ->assertCanSeeTableRecords($changes)
            ->assertTableColumnStateSet('user.name', 'Carla Vega', $horizon)
            ->assertTableColumnStateSet('section', 'Ajustes y página web', $horizon)
            ->assertTableColumnStateSet('subject', 'Hasta cuántos días adelante se puede reservar', $horizon)
            ->assertTableColumnStateSet('summary', '45 días → 30 días', $horizon)
            ->assertTableColumnStateSet('summary', '2 horas antes → 1 hora antes', $lead)
            ->assertTableColumnStateSet('summary', 'No → Sí', $reports);

        // Guardar otra vez sin tocar nada no deja rastro; cambiar uno ya guardado, sí.
        Livewire::test(Settings::class)->call('save')->assertHasNoFormErrors();
        $this->assertSame(3, AuditLog::query()->where('auditable_type', Setting::class)->count());

        Livewire::test(Settings::class)->fillForm(['booking' => ['horizon_days' => 60]])->call('save')->assertHasNoFormErrors();

        $again = AuditLog::query()->with('auditable')->where('auditable_type', Setting::class)->latest('id')->first();
        $this->assertSame([['value' => 30], ['value' => 60]], [$again->old_values, $again->new_values]);
        $this->assertSame('30 días → 60 días', ChangeHistory::summary($again));
    }

    public function test_what_the_system_writes_down_by_itself_is_not_a_change_of_somebody(): void
    {
        Setting::set('backups.files', ['at' => 1, 'ok' => true]);
        Setting::set('backups.files', ['at' => 2, 'ok' => true]);

        $this->assertSame(0, AuditLog::query()->where('auditable_type', Setting::class)->count());
    }

    public function test_changes_to_users_are_kept_and_the_password_never_is(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $rosa = User::factory()->reception()->create(['name' => 'Rosa Quispe']);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($rosa), data: ['role' => 'admin', 'password' => 'Secreta-2026'])
            ->assertHasNoActionErrors();

        $logs = AuditLog::query()->where('auditable_type', User::class)->where('auditable_id', $rosa->id)->oldest('id')->get();
        $this->assertSame(['created', 'updated', 'password_changed'], $logs->pluck('event')->all());
        $this->assertStringNotContainsString('password', (string) json_encode($logs->map->only(['old_values', 'new_values'])));

        Livewire::test(ManageAuditLogs::class)
            ->assertTableColumnStateSet('section', 'Usuarios', $logs[1])
            ->assertTableColumnStateSet('subject', 'Rosa Quispe', $logs[1])
            ->assertTableColumnStateSet('summary', 'Rol: Recepción → Administrador', $logs[1])
            ->assertTableColumnStateSet('summary', 'Se cambió su contraseña', $logs[2])
            ->assertTableColumnStateSet('summary', 'Se creó', $logs[0]);
    }

    public function test_the_history_of_doctors_and_treatments_that_was_already_kept_can_now_be_read(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $doctor = Doctor::factory()->create(['name' => 'Dra. Ana Torres', 'is_active' => true]);
        $range = DoctorSchedule::create(['doctor_id' => $doctor->id, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00']);
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30, 'price_from' => 120, 'aftercare' => 'No comas por dos horas.']);

        $doctor->update(['is_active' => false]);
        $range->update(['ends_at' => '12:30']);
        $treatment->update(['duration_minutes' => 45, 'price_from' => 150, 'aftercare' => 'No comas por una hora.']);
        $range->delete();
        $doctor->delete();
        $doctor->restore();

        $last = fn (string $class, string $event): AuditLog => AuditLog::query()->with('auditable')->where('auditable_type', $class)->where('event', $event)->latest('id')->firstOrFail();

        $page = Livewire::test(ManageAuditLogs::class)
            ->assertTableColumnStateSet('summary', 'Activo: Sí → No', AuditLog::query()->where('auditable_type', Doctor::class)->where('event', 'updated')->oldest('id')->firstOrFail())
            ->assertTableColumnStateSet('subject', 'Dra. Ana Torres · horario del lunes', $last(DoctorSchedule::class, 'updated'))
            ->assertTableColumnStateSet('summary', 'Hasta: 13:00 → 12:30', $last(DoctorSchedule::class, 'updated'))
            ->assertTableColumnStateSet('summary', 'Se agregó el tramo 09:00–13:00', $last(DoctorSchedule::class, 'created'))
            // El tramo ya no existe, pero se sigue sabiendo de quién era y de qué día.
            ->assertTableColumnStateSet('subject', 'Dra. Ana Torres · horario del lunes', $last(DoctorSchedule::class, 'deleted'))
            ->assertTableColumnStateSet('summary', 'Se quitó el tramo 09:00–12:30', $last(DoctorSchedule::class, 'deleted'))
            ->assertTableColumnStateSet('summary', 'Se borró', $last(Doctor::class, 'deleted'))
            ->assertTableColumnStateSet('summary', 'Se restauró', $last(Doctor::class, 'restored'))
            // Un texto largo no se copia entero a la lista.
            ->assertTableColumnStateSet('summary', 'Precio normal: S/ 120.00 → S/ 150.00 · Duración de la cita: 30 min → 45 min · Cuidados después de la atención: se cambió el texto', $last(Treatment::class, 'updated'));

        // Al restaurar quedaban dos registros del mismo momento: se muestra uno.
        $page->assertCanNotSeeTableRecords(AuditLog::query()->where('event', 'updated')->where('new_values', '{"deleted_at":null}')->get());

        // El detalle sí trae el texto completo, el de antes y el de ahora.
        $page->mountAction(TestAction::make('detail')->table($last(Treatment::class, 'updated')))
            ->assertMountedActionModalSee(['Limpieza dental', 'Antes', 'Ahora', 'No comas por dos horas.', 'No comas por una hora.']);
    }

    public function test_the_history_is_filtered_by_section_and_by_person(): void
    {
        $carla = User::factory()->admin()->create(['name' => 'Carla Vega']);
        $jorge = User::factory()->admin()->create(['name' => 'Jorge Mamani']);
        $doctor = Doctor::factory()->create();
        $treatment = Treatment::factory()->create();

        $this->actingAs($carla);
        $doctor->update(['specialty' => 'Implantología']);
        $range = DoctorSchedule::create(['doctor_id' => $doctor->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00']);
        $this->actingAs($jorge);
        $treatment->update(['duration_minutes' => 50]);

        $ofDoctor = AuditLog::query()->where('auditable_type', Doctor::class)->where('event', 'updated')->sole();
        $ofRange = AuditLog::query()->where('auditable_type', DoctorSchedule::class)->where('auditable_id', $range->id)->sole();
        $ofTreatment = AuditLog::query()->where('auditable_type', Treatment::class)->where('event', 'updated')->sole();

        Livewire::test(ManageAuditLogs::class)
            ->assertCanSeeTableRecords([$ofDoctor, $ofRange, $ofTreatment])
            // "Doctores" junta la ficha del doctor y su horario.
            ->filterTable('section', 'Doctores')
            ->assertCanSeeTableRecords([$ofDoctor, $ofRange])
            ->assertCanNotSeeTableRecords([$ofTreatment])
            ->resetTableFilters()
            ->filterTable('user_id', $jorge->id)
            ->assertCanSeeTableRecords([$ofTreatment])
            ->assertCanNotSeeTableRecords([$ofDoctor, $ofRange]);
    }

    public function test_only_the_administrator_reads_the_history_and_nobody_changes_it(): void
    {
        $log = AuditLog::create(['event' => 'updated', 'auditable_type' => Treatment::class, 'auditable_id' => Treatment::factory()->create()->id]);
        $history = AuditLogResource::getUrl('index');

        $this->actingAs(User::factory()->reception()->create())->get($history)->assertForbidden();
        $this->actingAs(Doctor::factory()->create()->user)->get($history)->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get($history)->assertOk()->assertSee('Quién cambió qué y cuándo en Configuración y en la página web.');

        $this->assertFalse($admin->can('create', AuditLog::class));
        $this->assertFalse($admin->can('update', $log));
        $this->assertFalse($admin->can('delete', $log));
    }

    public function test_every_setting_and_every_field_has_its_name_in_the_history(): void
    {
        $keys = fn (string $page): array => array_keys((new ReflectionMethod($page, 'settingKeys'))->invoke(null));

        $this->assertSame([], array_values(array_diff([...$keys(Settings::class), ...$keys(WebSetup::class)], array_keys(ChangeHistory::SETTINGS))));

        foreach (ChangeHistory::FIELDS as $class => $labels) {
            $unnamed = array_diff((new $class)->getFillable(), array_keys($labels), ChangeHistory::INTERNAL, ['password', 'name', 'key']);

            $this->assertSame([], array_values($unnamed), "Datos de {$class} sin nombre en el historial.");
        }
    }
}
