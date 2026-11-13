<?php

namespace Tests\Feature\Filament;

use App\Enums\PlanPaymentMode;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Pages\Settings;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Agenda\AvailabilityService;
use App\Services\Web\PublicImages;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

/**
 * Ajustes › Avisos y lo que se sumó a Ajustes en la etapa 3: las horas de los avisos se cambian en el panel,
 * la razón social y el RUC salen en lo impreso, las reglas de reserva se leen en una frase y se puede volver
 * a los valores recomendados.
 */
class SettingsNoticesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_reminder_is_prepared_from_the_hour_chosen_in_ajustes(): void
    {
        $this->travelTo('2026-10-26 10:00');
        Appointment::factory()->at('2026-11-03 10:00')->create();
        $this->saveInAjustes(['reminders' => ['day_before_at' => '07:30']]);

        $this->assertSame('07:30', Setting::get('reminders.day_before_at'));

        $this->travelTo('2026-11-02 07:10');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(0, WhatsappMessage::count());

        // Antes salía recién a las 9:00, la hora fija.
        $this->travelTo('2026-11-02 07:40');
        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_unconfirmed_appointments_pass_to_call_from_the_hour_chosen_in_ajustes(): void
    {
        $this->travelTo('2026-10-26 10:00');
        $pending = Appointment::factory()->at('2026-11-03 10:00')->create();
        $this->saveInAjustes(['reminders' => ['flag_unconfirmed_at' => '11:00']]);

        $this->travelTo('2026-11-02 10:40');
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();
        $this->assertFalse($pending->fresh()->needsCall());

        // Antes pasaba a "llamar" recién a las 4:00 de la tarde.
        $this->travelTo('2026-11-02 11:10');
        $this->artisan('citas:marcar-sin-confirmar')->assertSuccessful();
        $this->assertTrue($pending->fresh()->needsCall());
    }

    public function test_the_hour_to_call_must_come_after_the_reminder(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['reminders' => ['day_before_at' => '10:00', 'flag_unconfirmed_at' => '10:00']])
            ->call('save')
            ->assertHasFormErrors(['reminders.flag_unconfirmed_at'])
            ->assertSee('Tiene que ser después del recordatorio: primero se le recuerda y después se revisa si confirmó.');

        $this->assertSame(['09:00', '16:00'], [Setting::get('reminders.day_before_at'), Setting::get('reminders.flag_unconfirmed_at')]);
    }

    public function test_an_hour_that_is_not_in_the_list_is_kept_until_someone_changes_it(): void
    {
        // Puesta por un técnico cuando las horas no se veían en el panel.
        Setting::set('reminders.day_before_at', '09:15');

        $this->saveInAjustes(['clinic' => ['slogan' => 'Sonrisas que duran']]);

        $this->assertSame(['09:15', 'Sonrisas que duran'], [Setting::get('reminders.day_before_at'), Setting::get('clinic.slogan')]);

        // Y una hora inventada no entra.
        Livewire::test(Settings::class)
            ->fillForm(['reminders' => ['day_before_at' => '25:99']])
            ->call('save')
            ->assertHasFormErrors(['reminders.day_before_at']);
    }

    public function test_the_notice_of_a_cuota_is_prepared_the_days_before_chosen_in_ajustes(): void
    {
        $this->freezeMonday();
        $plan = TreatmentPlan::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => Doctor::factory()->create()->id, 'title' => 'Ortodoncia', 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonth()]);
        $plan->installments()->create(['number' => 1, 'due_on' => '2026-11-09', 'amount' => 250]);

        // Vence en 7 días: con los 3 días con que viene el sistema, todavía no.
        $this->artisan('cuotas:preparar-avisos')->expectsOutput('Avisos de cuota preparados: 0')->assertSuccessful();

        $this->saveInAjustes(['reminders' => ['installment_days_before' => 7]]);

        $this->artisan('cuotas:preparar-avisos')->expectsOutput('Avisos de cuota preparados: 1')->assertSuccessful();
    }

    public function test_the_notices_of_cuotas_wait_for_the_hour_of_the_reminders(): void
    {
        $this->freezeMonday();
        $this->artisan('schedule:list')->assertSuccessful();
        $notices = collect(app(Schedule::class)->events())->sole(fn (Event $event) => str_contains((string) $event->command, 'cuotas:preparar-avisos'));

        // Lunes 8:00: todavía no son las 9:00 con que viene el sistema.
        $this->assertFalse($notices->filtersPass($this->app));

        $this->travelTo('2026-11-02 09:05');
        $this->assertTrue($notices->filtersPass($this->app));

        // Con la hora cambiada en el panel, vale desde la siguiente pasada: no hay que reiniciar nada.
        $this->saveInAjustes(['reminders' => ['day_before_at' => '07:30']]);
        $this->travelTo('2026-11-03 07:20');
        $this->assertFalse($notices->filtersPass($this->app));

        $this->travelTo('2026-11-03 07:35');
        $this->assertTrue($notices->filtersPass($this->app));
    }

    public function test_the_notices_tab_says_whether_they_go_out_alone_or_by_hand(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->assertSeeInOrder(['Recordatorio de la cita', 'El recordatorio queda listo a las', 'Si no confirma, pasa a "llamar" a las', 'El aviso de una cuota se prepara', 'Cómo salen los avisos'], escape: false)
            ->assertSee('Los avisos se envían a mano')
            ->assertDontSee('Los avisos salen solos');

        config(['consultorio.whatsapp.driver' => 'cloud_api']);

        Livewire::test(Settings::class)
            ->assertSee('Los avisos salen solos por la conexión oficial de WhatsApp')
            ->assertDontSee('Los avisos se envían a mano');
    }

    public function test_the_shortest_gap_that_counts_as_free_time_is_changed_in_ajustes(): void
    {
        $this->freezeMonday();
        // Atiende el martes solo 45 minutos, sin citas.
        $doctor = Doctor::factory()->withSchedule([['09:00', '09:45']], [2])->create();
        $gaps = fn (): array => app(AvailabilityService::class)->dayGaps($doctor, CarbonImmutable::parse('2026-11-03'))['gaps'];

        $this->assertCount(1, $gaps());

        $this->saveInAjustes(['agenda' => ['min_useful_gap_minutes' => 60]]);

        $this->assertSame([], $gaps());

        // Menos de 10 minutos no es un hueco para nadie.
        Livewire::test(Settings::class)
            ->fillForm(['agenda' => ['min_useful_gap_minutes' => 5]])
            ->call('save')
            ->assertHasFormErrors(['agenda.min_useful_gap_minutes']);
    }

    public function test_the_ruc_has_eleven_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm(['clinic' => ['ruc' => '2060-1234567']])
            ->call('save')
            ->assertHasFormErrors(['clinic.ruc'])
            ->assertSee('El RUC tiene 11 números, sin espacios ni guiones.');

        $this->assertNull(Setting::get('clinic.ruc'));

        Livewire::test(Settings::class)
            ->fillForm(['clinic' => ['ruc' => '20601234567', 'legal_name' => 'Vega Implant S.A.C.']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['20601234567', 'Vega Implant S.A.C.'], [Setting::get('clinic.ruc'), Setting::get('clinic.legal_name')]);
    }

    public function test_printed_sheets_carry_the_logo_the_legal_name_and_the_ruc(): void
    {
        $payment = Payment::factory()->create(['amount' => 200]);
        $receipt = Filament::getPanel('admin')->route('print.receipt', ['payment' => $payment]);
        $this->actingAs(User::factory()->reception()->create());

        // Sin llenar nada, la hoja sale como siempre: solo el nombre.
        $this->get($receipt)->assertOk()->assertDontSee(['RUC', 'class="clinic-logo"'], escape: false);

        Setting::set('clinic.legal_name', 'Vega Implant S.A.C.');
        Setting::set('clinic.ruc', '20601234567');
        Setting::set('web.logo', 'logo/consultorio.jpg');

        $this->get($receipt)
            ->assertOk()
            ->assertSee('Vega Implant S.A.C. · RUC 20601234567')
            ->assertSee('<img class="clinic-logo" src="'.e(PublicImages::url('logo/consultorio.jpg')).'" alt="">', escape: false);

        // Con solo uno de los dos, sale el que hay.
        Setting::set('clinic.legal_name', null);

        $this->get($receipt)->assertOk()->assertSee('RUC 20601234567')->assertDontSee(' · RUC');
    }

    public function test_the_booking_rules_read_as_one_sentence_that_follows_what_is_on_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->assertSee('Así queda: un paciente puede reservar con al menos 2 horas de anticipación y hasta 45 días adelante, con 2 citas futuras como máximo (6 entre toda la familia). Cambia su cita por su cuenta si faltan más de 24 horas y la cancela hasta 12 horas antes.')
            ->fillForm(['booking' => ['min_lead_minutes' => 1440, 'horizon_days' => 1, 'max_web_appointments_per_patient' => 1, 'self_reschedule_min_hours' => 1, 'self_cancel_min_hours' => 0]])
            ->assertSee('Así queda: un paciente puede reservar con al menos 1 día de anticipación y hasta 1 día adelante, con 1 cita futura como máximo (6 entre toda la familia). Cambia su cita por su cuenta si faltan más de 1 hora y la cancela hasta el último momento.')
            ->fillForm(['booking' => ['min_lead_minutes' => 30]])
            ->assertSee('reservar con al menos 30 minutos de anticipación')
            ->fillForm(['booking' => ['min_lead_minutes' => 0]])
            ->assertSee('reservar hasta el último momento y hasta 1 día adelante');
    }

    public function test_volver_a_los_valores_recomendados_fills_the_form_and_saves_nothing_by_itself(): void
    {
        Setting::set('booking.horizon_days', 3);
        Setting::set('booking.self_cancel_min_hours', 60);
        Setting::set('agenda.buffer_minutes', 45);
        $this->actingAs(User::factory()->admin()->create());

        $page = Livewire::test(Settings::class)
            ->callAction(TestAction::make('recommendedBooking')->schemaComponent('reservas-y-cambios-desde-la-web::data::section'))
            ->assertNotified('Valores recomendados puestos')
            ->assertSchemaStateSet(['booking.horizon_days' => 45, 'booking.self_cancel_min_hours' => 12])
            // Solo cambia su sección: la limpieza entre citas sigue como estaba.
            ->assertSchemaStateSet(['agenda.buffer_minutes' => 45]);

        // Todavía no se guardó nada: falta revisar y presionar Guardar.
        $this->assertSame([3, 60], [Setting::get('booking.horizon_days'), Setting::get('booking.self_cancel_min_hours')]);

        $page->callAction(TestAction::make('recommendedAgenda')->schemaComponent('agenda::data::section'))
            ->assertSchemaStateSet(['agenda.buffer_minutes' => 10, 'agenda.min_useful_gap_minutes' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([45, 12, 10], [Setting::get('booking.horizon_days'), Setting::get('booking.self_cancel_min_hours'), Setting::get('agenda.buffer_minutes')]);
    }

    /**
     * La administradora cambia algo en Ajustes y guarda.
     *
     * @param  array<string, array<string, mixed>>  $values
     */
    private function saveInAjustes(array $values): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->fillForm($values)
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
