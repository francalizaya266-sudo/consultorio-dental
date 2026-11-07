<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\ScheduleBlockType;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Widgets\FollowUpsToday;
use App\Filament\Widgets\MonthOverview;
use App\Filament\Widgets\MonthTrend;
use App\Filament\Widgets\NextPatient;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Filament\Widgets\WaitingRoom;
use App\Filament\Widgets\WeekGaps;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\ScheduleBlock;
use App\Models\Setting;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Dashboard\MonthFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class DashboardPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    private const NBSP = "\u{00A0}";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->freezeMonday();
    }

    public function test_each_role_sees_its_own_cards(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create();

        $this->actingAs(User::factory()->admin()->create());
        $this->assertSame([SystemHealth::class, TodayStats::class, TodayAppointments::class, MonthOverview::class, MonthTrend::class, WaitingRoom::class, FollowUpsToday::class, WeekGaps::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(['Lunes 2 de noviembre', 'Nueva cita', 'Registrar pago', 'Anotar en espera']);

        $this->actingAs(User::factory()->reception()->create());
        $this->assertSame([TodayStats::class, TodayAppointments::class, WaitingRoom::class, FollowUpsToday::class, WeekGaps::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk();
        Livewire::test(TodayStats::class)
            ->assertSeeInOrder(['Citas de hoy', 'Sin confirmar', 'Cobrado hoy', 'Horas libres'])
            ->assertDontSee(['Solicitudes de cambio', 'Avisos por enviar']);

        // El doctor ve su paciente; cómo va el mes, solo si el consultorio se lo permite.
        $this->actingAs($doctor->user);
        $this->assertSame([TodayStats::class, NextPatient::class, TodayAppointments::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee(['Registrar pago', 'Anotar en espera']);
        Livewire::test(TodayStats::class)
            ->assertSeeInOrder(['Citas de hoy', 'Sin confirmar', 'Solicitudes de cambio', 'Avisos por enviar'])
            ->assertDontSee(['Cobrado hoy', 'Horas libres']);

        Setting::set('reports.doctors_see_own', true);
        $this->assertSame([TodayStats::class, NextPatient::class, TodayAppointments::class, MonthOverview::class, MonthTrend::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk();
    }

    public function test_only_the_number_that_needs_someone_is_highlighted(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(TodayStats::class)
            ->assertSee('Hoy y mañana, todo confirmado')
            ->assertDontSeeHtml('dash-kpi is-warning');

        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create(['status' => AppointmentStatus::Pending]);

        Livewire::test(TodayStats::class)
            ->assertSee('Hoy y mañana · reenvía el aviso o llama')
            ->assertSeeHtml('dash-kpi is-warning');
    }

    public function test_the_waiting_room_lists_who_has_waited_longest_first(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(WaitingRoom::class)->assertSee('Nadie esperando');

        Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Luis', 'Mamani'))->at('2026-11-02 08:30')
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()->subMinutes(5)]);
        Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Rosa', 'Quispe'))->at('2026-11-02 09:00')
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()->subMinutes(25)]);
        Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Ana', 'Flores'))->at('2026-11-02 09:30')->confirmed()->create();

        Livewire::test(WaitingRoom::class)
            // Rosa llegó antes, aunque su cita sea más tarde; con 25 minutos, su espera va en rojo.
            ->assertSeeInOrder(['Rosa Quispe', 'Limpieza dental · cita de las 09:00', '25'.self::NBSP.'min', 'Luis Mamani', '5'.self::NBSP.'min'])
            ->assertSeeHtml('dash-waiting-time is-long')
            ->assertDontSee(['Ana Flores', $doctor->name]);
    }

    public function test_the_month_at_a_glance_compares_with_the_same_days_of_last_month_and_tracks_the_goal(): void
    {
        $torres = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        $ramos = Doctor::factory()->create(['name' => 'Dr. Luis Ramos']);
        $patient = $this->patient('Rosa', 'Quispe');
        Payment::factory()->for($patient)->create(['doctor_id' => $torres->id, 'amount' => 300, 'paid_at' => '2026-11-01 10:00']);
        Payment::factory()->for($patient)->create(['doctor_id' => $ramos->id, 'amount' => 150, 'paid_at' => '2026-11-02 07:30']);
        Payment::factory()->for($patient)->create(['doctor_id' => $torres->id, 'amount' => 300, 'paid_at' => '2026-10-02 10:00']);
        // Después del 2 de octubre ya no cuenta: se compara con los mismos días.
        Payment::factory()->for($patient)->create(['doctor_id' => $torres->id, 'amount' => 900, 'paid_at' => '2026-10-20 10:00']);
        Setting::set('reports.monthly_goal', 4500);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(MonthOverview::class)
            ->assertSee(['Noviembre hasta hoy', 'Cobrado', 'S/ 450.00', '+50 % vs. 1–2 oct.', 'Por cobrar (a hoy)'])
            // Con dos días del mes, la diferencia se muestra sin verde ni rojo.
            ->assertSee('Con tan pocos días, las diferencias todavía no dicen mucho.')
            // Al 2 de noviembre, S/ 450 de S/ 4,500 es justo el ritmo de 2 días de 30.
            ->assertSee(['Meta del mes', '10 %', 'De S/ 4,500 · faltan S/ 4,050 en 28 días, a buen ritmo']);

        Setting::set('reports.doctors_see_own', true);
        $this->actingAs($ramos->user);

        Livewire::test(MonthOverview::class)
            ->assertSee(['Solo tus citas', 'S/ 150.00'])
            ->assertDontSee(['S/ 450.00', 'Por cobrar', 'Meta del mes']);
    }

    public function test_the_month_chart_adds_up_day_by_day_and_projects_the_close(): void
    {
        $patient = $this->patient('Rosa', 'Quispe');
        Payment::factory()->for($patient)->create(['amount' => 300, 'paid_at' => '2026-11-01 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 150, 'paid_at' => '2026-11-02 07:30']);
        Payment::factory()->for($patient)->create(['amount' => 200, 'paid_at' => '2026-10-01 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 1000, 'paid_at' => '2026-10-31 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 999, 'paid_at' => '2026-10-31 11:00', 'voided_at' => '2026-10-31 12:00']);
        $this->actingAs(User::factory()->admin()->create());

        $series = (new MonthFigures)->cumulative();
        $this->assertSame([300.0, 450.0], $series['this_month']);
        $this->assertCount(31, $series['last_month']);
        $this->assertSame([200.0, 200.0, 1200.0], [$series['last_month'][0], $series['last_month'][29], $series['last_month'][30]]);

        // Los primeros días no se proyecta: un solo pago lo cambia todo.
        Livewire::test(MonthTrend::class)
            ->assertSee(['Cobrado en noviembre, día a día', 'Van S/ 450.00 en 2 días. Desde el día 7 te digo a cuánto cerraría el mes.']);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00', 'America/Lima'));
        Payment::factory()->for($patient)->create(['amount' => 550, 'paid_at' => '2026-11-10 09:00']);

        // S/ 1,000 en 10 días de 30: a este ritmo, S/ 3,000.
        Livewire::test(MonthTrend::class)
            ->assertSee('A este ritmo, noviembre cerraría en S/ 3,000.00; octubre cerró en S/ 1,200.00.');

        Setting::set('reports.monthly_goal', 4500);

        Livewire::test(MonthTrend::class)
            ->assertSee(['Ritmo para la meta', 'A este ritmo, noviembre cerraría en S/ 3,000.00, por debajo de la meta de S/ 4,500.']);
    }

    public function test_reception_sees_whom_to_follow_up_with_and_the_lists_count_the_same(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create(['name' => 'Dra. Ana Torres']);
        $treatment = Treatment::factory()->create();

        $rosa = $this->patient('Rosa', 'Quispe');
        $waiting = TreatmentPlan::create(['patient_id' => $rosa->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed]);
        $waiting->items()->create(['description' => 'Ortodoncia', 'unit_price' => 450]);
        $waiting->update(['proposed_at' => now()->subDays(8)]);

        // Presentado ayer: aún se le da tiempo para pensarlo.
        $luis = $this->patient('Luis', 'Mamani');
        TreatmentPlan::create(['patient_id' => $luis->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed, 'proposed_at' => now()->subDay()]);

        $ana = $this->patient('Ana', 'Flores');
        $this->acceptedPlan($ana, $doctor, 'Corona', '46');

        // Aceptó y ya tiene su próxima cita: no hay nada que perseguir.
        $pedro = $this->patient('Pedro', 'Huamán');
        $this->acceptedPlan($pedro, $doctor, 'Endodoncia', '36');
        Appointment::factory()->for($pedro)->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();

        $carmen = $this->patient('Carmen', 'Rojas', ['birth_date' => '1990-11-02']);
        $jose = $this->patient('José', 'Condori', ['last_visit_at' => now()->subMonths(7)]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Presupuestos sin respuesta', 'Rosa Quispe', 'S/ 450.00 · Dra. Ana Torres · presentado hace 1 semana'])
            ->assertDontSee('Luis Mamani')
            ->assertSee(['Planes sin próxima cita', 'Ana Flores', 'Falta: Corona', 'Nueva cita'])
            ->assertDontSee(['Pedro Huamán', 'pieza 46'])
            ->assertSee(['Para su control', 'José Condori', 'Cumpleaños de hoy', 'Carmen Rojas', 'Cumple 36 años']);

        Livewire::test(ListPatients::class)
            ->set('activeTab', 'proposed')
            ->assertCanSeeTableRecords([$rosa])
            ->assertCanNotSeeTableRecords([$luis, $ana, $pedro])
            ->set('activeTab', 'unscheduled')
            ->assertCanSeeTableRecords([$ana])
            ->assertCanNotSeeTableRecords([$rosa, $pedro, $jose]);
    }

    public function test_a_follow_up_message_opens_ready_to_send_and_marks_the_patient_as_contacted(): void
    {
        $carmen = $this->patient('Carmen', 'Rojas', ['birth_date' => '1990-11-02']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertDontSee('Le escribiste')
            ->mountAction('whatsapp', ['template' => MessageTemplateKey::Birthday->value, 'patient' => $carmen->id])
            ->assertSchemaStateSet(['body' => '¡Feliz cumpleaños, Carmen! 🎂 Todo el equipo de Clínica Vega Implant te desea un gran día.'])
            ->callMountedAction()
            ->assertHasNoErrors();

        $message = WhatsappMessage::sole();
        $this->assertSame([MessageTemplateKey::Birthday, MessageStatus::Sent, $carmen->id], [$message->template_key, $message->status, $message->patient_id]);

        Livewire::test(FollowUpsToday::class)->assertSee('Le escribiste hoy');
    }

    public function test_the_plan_message_names_what_is_pending_without_the_tooth(): void
    {
        $doctor = Doctor::factory()->create();
        $ana = $this->patient('Ana', 'Flores');
        $this->acceptedPlan($ana, $doctor, 'Corona', '46');
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->mountAction('whatsapp', ['template' => MessageTemplateKey::PlanNextSession->value, 'patient' => $ana->id])
            ->assertSchemaStateSet(['body' => "Hola Ana, te escribimos de Clínica Vega Implant.\nEn tu tratamiento queda pendiente: Corona.\n\n¿Te reservamos tu próxima cita? Respóndenos con el día y la hora que te acomodan."]);
    }

    public function test_a_leap_day_birthday_is_greeted_on_february_28_in_other_years(): void
    {
        $leapling = $this->patient('Ana', 'Flores', ['birth_date' => '2000-02-29']);
        $greetedOn = fn (string $date) => Patient::query()->birthdayOn(CarbonImmutable::parse($date))->whereKey($leapling)->exists();

        $this->assertTrue($greetedOn('2027-02-28'));
        $this->assertFalse($greetedOn('2028-02-28'));
        $this->assertTrue($greetedOn('2028-02-29'));
    }

    public function test_presenting_a_plan_records_when_it_was_presented(): void
    {
        $plan = TreatmentPlan::create(['patient_id' => $this->patient('Rosa', 'Quispe')->id, 'doctor_id' => Doctor::factory()->create()->id, 'status' => TreatmentPlanStatus::Draft]);
        $this->assertNull($plan->proposed_at);

        app(TreatmentPlanService::class)->markProposed($plan);

        $this->assertTrue($plan->fresh()->proposed_at->equalTo(now()));
    }

    public function test_the_week_shows_how_full_each_day_is_and_where_an_appointment_still_fits(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create();
        Appointment::factory()->for($doctor)->for(Treatment::factory())->for($this->patient('Rosa', 'Quispe'))->at('2026-11-02 09:00', minutes: 30, buffer: 10)->create();
        ScheduleBlock::create(['doctor_id' => $doctor->id, 'type' => ScheduleBlockType::Course, 'starts_at' => '2026-11-03 00:00', 'ends_at' => '2026-11-03 23:59']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(WeekGaps::class)
            ->assertSeeInOrder(['Hoy', '7'.self::NBSP.'h 20'.self::NBSP.'min libres', '09:40–13:00 · 15:00–19:00', 'Mañana', 'Sin huecos', 'Miércoles 4', '8'.self::NBSP.'h libres'])
            // El domingo no se atiende.
            ->assertDontSee('Domingo');
    }

    public function test_the_doctor_sees_the_patient_waiting_with_their_alerts_and_can_start_from_home(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Curación con resina']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);

        $maria = $this->patient('María', 'Quispe');
        Appointment::factory()->for($doctor)->for($treatment)->for($maria)->at('2026-11-02 10:00')->confirmed()->create();

        $jose = $this->patient('José', 'Condori', ['birth_date' => '1990-05-10']);
        $jose->medicalBackground()->create(['allergies' => 'Penicilina']);
        Appointment::factory()->for($doctor)->for($cleaning)->for($jose)->at('2026-10-05 10:00')->create(['status' => AppointmentStatus::Completed]);
        $waiting = Appointment::factory()->for($doctor)->for($treatment)->for($jose)->at('2026-11-02 11:00')
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()->subMinutes(25)]);

        $unfinished = Consultation::factory()->for($doctor)->for($this->patient('Luz', 'Ramos'))->create(['attended_at' => now()->subDay()]);

        $this->actingAs($doctor->user);

        // Quien ya está en sala va primero, aunque su cita sea más tarde.
        Livewire::test(NextPatient::class)
            ->assertSee(['11:00', 'José Condori', '36 años', 'Curación con resina', 'En sala hace 25'.self::NBSP.'min'])
            ->assertSee(['Alergia: Penicilina', 'Limpieza dental · 5 oct. 2026', 'Ver historia'])
            ->assertDontSee('María Quispe')
            ->assertSee(['Atenciones sin terminar', 'Luz Ramos', 'Continuar']);

        Livewire::test(TodayAppointments::class)->assertSee('En sala hace 25'.self::NBSP.'min');

        Livewire::test(NextPatient::class)
            ->callAction('start', arguments: ['appointment' => $waiting->id])
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => $waiting->fresh()->consultation]));

        $this->assertSame(AppointmentStatus::InProgress, $waiting->fresh()->status);
        $this->assertNotNull($unfinished->fresh());
    }

    public function test_late_in_the_day_home_does_not_offer_a_patient_whose_time_already_passed(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Curación con resina']);
        $doctor = $this->doctorOffering($treatment);
        $rosa = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Rosa', 'Huamán'))->at('2026-11-02 11:00')->confirmed()->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Luis', 'Paz'))->at('2026-11-02 16:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Ana', 'Ríos'))->at('2026-11-02 09:00')->create(['status' => AppointmentStatus::InProgress]);
        $this->actingAs($doctor->user);

        // 16:10: la de las 11:00 nunca llegó; la de las 16:00 va tarde.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 16:10', 'America/Lima'));

        Livewire::test(NextPatient::class)
            ->assertSee(['16:00', 'Luis Paz', 'Va 10'.self::NBSP.'min tarde'])
            ->assertDontSee('Rosa Huamán');

        // En la cita que ya pasó, el paso a la vista es "No asistió" y ya no "Confirmar".
        Livewire::test(ViewAppointment::class, ['record' => $rosa->getRouteKey()])
            ->assertActionVisible('missed')
            ->assertActionHidden('confirm');

        // A las 20:35 ya no queda nadie por venir.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 20:35', 'America/Lima'));
        Livewire::test(NextPatient::class)->assertDontSee(['Luis Paz', 'Rosa Huamán']);

        // Los números del día dicen lo que está pasando, no solo lo terminado.
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(TodayStats::class)
            ->assertSee('1 en atención · 0 atendidas')
            ->assertSee('Aún no se terminó ninguna atención');
    }

    public function test_past_appointments_left_open_show_up_and_the_no_shows_close_in_one_click(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $forgotten = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Ana', 'Ríos'))->at('2026-10-30 10:00')->confirmed()->create();
        $leftInRoom = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Luis', 'Paz'))->at('2026-10-30 11:00')
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => '2026-10-30 10:55']);
        $closed = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Eva', 'Sol'))->at('2026-10-30 12:00')->create(['status' => AppointmentStatus::Completed]);
        $today = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Rut', 'Mar'))->at('2026-11-02 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Citas sin cerrar', 'De días pasados: marca si vino o si no asistió']);

        Livewire::test(ListAppointments::class, ['activeTab' => 'unclosed'])
            ->assertCanSeeTableRecords([$forgotten, $leftInRoom])
            ->assertCanNotSeeTableRecords([$closed, $today])
            ->callTableBulkAction('noShowBulk', [$forgotten, $leftInRoom]);

        // Solo cambia la que nadie marcó; la que quedó en sala espera que alguien diga si se atendió.
        $this->assertSame(AppointmentStatus::NoShow, $forgotten->fresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $leftInRoom->fresh()->status);
    }

    public function test_home_shows_the_first_unfinished_consultations_and_links_to_the_rest(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create();
        foreach (['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco'] as $i => $name) {
            Consultation::factory()->for($doctor)->for($this->patient($name, 'Prueba'))->create(['attended_at' => now()->subDays(5 - $i)]);
        }

        $this->actingAs($doctor->user);

        Livewire::test(NextPatient::class)
            ->assertSee(['Uno Prueba', 'Dos Prueba', 'Tres Prueba', 'Ver las 5'])
            ->assertDontSee(['Cuatro Prueba', 'Cinco Prueba']);
    }

    public function test_the_admin_is_warned_when_backups_stop_or_whatsapp_fails(): void
    {
        $admin = User::factory()->admin(isDentist: true)->create();
        $this->actingAs($admin);

        $this->assertTrue(SystemHealth::canView());
        Livewire::test(SystemHealth::class)->assertSee(['Todavía no hay copias de seguridad', 'Ver copias']);

        Storage::disk('local')->put('respaldos/respaldo_2026-11-02_030000.sql.gz', 'copia');
        $this->assertFalse(SystemHealth::canView());

        $patient = $this->patient('Rosa', 'Quispe');
        WhatsappMessage::create([
            'account_id' => $patient->account_id,
            'patient_id' => $patient->id,
            'template_key' => MessageTemplateKey::Reminder,
            'phone' => $patient->account->phone,
            'status' => MessageStatus::Failed,
            'channel' => MessageChannel::CloudApi,
            'error' => 'Número no válido',
        ]);

        $this->assertTrue(SystemHealth::canView());
        Livewire::test(SystemHealth::class)->assertSee('1 aviso de WhatsApp no se pudo enviar esta semana');

        // Recepción no ve el estado del sistema.
        $this->actingAs(User::factory()->reception()->create());
        $this->assertFalse(SystemHealth::canView());
    }

    public function test_the_admin_sets_a_monthly_goal_in_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            ->assertSee('Meta de cobro del mes')
            ->fillForm(['reports' => ['monthly_goal' => '12000']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(12000, Setting::get('reports.monthly_goal'));
    }

    /** @return list<class-string> */
    private function visibleWidgets(): array
    {
        return array_values(array_filter((new Dashboard)->getWidgets(), fn (string $widget) => $widget::canView()));
    }

    /** Paciente con fecha de nacimiento fija: uno al azar podría cumplir años hoy. */
    private function patient(string $firstName, string $lastName, array $attributes = []): Patient
    {
        return Patient::factory()->create(['first_name' => $firstName, 'last_name' => $lastName, 'birth_date' => '1980-05-15', ...$attributes]);
    }

    private function acceptedPlan(Patient $patient, Doctor $doctor, string $procedure, string $tooth): TreatmentPlan
    {
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $plan->items()->create(['description' => $procedure, 'tooth' => $tooth, 'unit_price' => 800, 'accepted_at' => now()->subWeek()]);

        return $plan;
    }
}
