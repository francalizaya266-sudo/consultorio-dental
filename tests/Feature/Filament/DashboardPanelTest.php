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
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\TreatmentPlans\Pages\ListTreatmentPlans;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Filament\Widgets\DayMomentCard;
use App\Filament\Widgets\FollowUpsToday;
use App\Filament\Widgets\MonthOverview;
use App\Filament\Widgets\NextPatient;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Filament\Widgets\WaitingRoom;
use App\Filament\Widgets\WeekGaps;
use App\Models\Appointment;
use App\Models\Charge;
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
use App\Services\Dashboard\DoctorDay;
use App\Services\Dashboard\FollowUps;
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
        // Lo del mes va al final, después de todo lo del día.
        $this->assertSame([SystemHealth::class, TodayStats::class, TodayAppointments::class, WaitingRoom::class, FollowUpsToday::class, WeekGaps::class, MonthOverview::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(['Lunes 2 de noviembre', 'Nueva cita', 'Llegó sin cita', 'Cobrar', 'Anotar en lista de espera']);

        // Quien administra y también atiende ve a quien lo espera en "Tu próximo paciente", no otra vez en la sala.
        $dentist = User::factory()->admin(isDentist: true)->create();
        Doctor::factory()->withSchedule()->create(['user_id' => $dentist->id]);
        $this->actingAs($dentist);
        $this->assertSame([SystemHealth::class, TodayStats::class, NextPatient::class, TodayAppointments::class, FollowUpsToday::class, WeekGaps::class, MonthOverview::class], $this->visibleWidgets());

        $this->actingAs(User::factory()->reception()->create());
        $this->assertSame([TodayStats::class, TodayAppointments::class, WaitingRoom::class, FollowUpsToday::class, WeekGaps::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk();
        Livewire::test(TodayStats::class)
            ->assertSeeInOrder(['Citas de hoy', 'Por confirmar', 'Cobrado hoy'])
            // Las horas libres se ven en "Huecos de la semana", no también aquí.
            ->assertDontSee(['Horas libres', 'Cambios pedidos', 'Avisos por enviar']);

        // El doctor ve su paciente; cómo va el mes, solo si el consultorio se lo permite.
        $this->actingAs($doctor->user);
        $this->assertSame([TodayStats::class, NextPatient::class, TodayAppointments::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(['Nueva cita', 'Llegó sin cita'])->assertDontSee(['Cobrar', 'Anotar en lista de espera']);
        Livewire::test(TodayStats::class)
            ->assertSeeInOrder(['Citas de hoy', 'Por confirmar', 'Cambios pedidos', 'Avisos por enviar'])
            ->assertDontSee(['Cobrado hoy', 'Horas libres']);

        Setting::set('reports.doctors_see_own', true);
        $this->assertSame([TodayStats::class, NextPatient::class, TodayAppointments::class, MonthOverview::class], $this->visibleWidgets());
        $this->get(Dashboard::getUrl())->assertOk();
    }

    public function test_each_visit_is_settled_on_the_card_without_opening_it(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($treatment);
        $visit = fn (string $firstName, string $when) => Appointment::factory()->for($doctor)->for($treatment)
            ->for(Patient::factory()->create(['first_name' => $firstName, 'last_name' => 'Prueba']))->at($when);
        $rosa = $visit('Rosa', '2026-11-02 10:00')->create();
        $pedro = $visit('Pedro', '2026-11-02 16:00')->confirmed()->create();
        $ana = $visit('Ana', '2026-11-02 17:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        // 08:00: se confirma desde la tarjeta.
        Livewire::test(DayMomentCard::class)
            ->callAction('confirm', arguments: ['appointment' => $rosa->id])
            ->assertNotified('Cita confirmada')
            ->assertDontSee('10:00 · Rosa Prueba');
        $this->assertSame(AppointmentStatus::Confirmed, $rosa->refresh()->status);

        // 19:30: nadie anotó si vinieron. Pedro vino (queda atendido y con su cargo); Ana no.
        $rosa->update(['status' => AppointmentStatus::Completed]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 19:30', 'America/Lima'));

        Livewire::test(DayMomentCard::class)
            ->assertSeeInOrder(['Citas sin cerrar', '16:00 · Pedro Prueba', 'Vino', 'No vino', '17:00 · Ana Prueba'])
            ->mountAction('complete', ['appointment' => $pedro->id])
            ->assertMountedActionModalSee('Limpieza dental')
            ->callMountedAction()
            ->assertNotified('Cita atendida')
            ->callAction('missed', arguments: ['appointment' => $ana->id])
            ->assertNotified('Marcada como inasistencia')
            ->assertDontSee(['Citas sin cerrar', 'Ana Prueba'])
            ->assertSeeInOrder(['Se fueron debiendo', 'Pedro Prueba', 'Cobrar', 'Falta el cierre de caja']);

        $this->assertSame(AppointmentStatus::Completed, $pedro->refresh()->status);
        $this->assertSame(AppointmentStatus::NoShow, $ana->refresh()->status);
        $this->assertSame(120.0, $pedro->patient->balance());

        // A quien se fue debiendo se le cobra ahí mismo, con el "Cobrar" del cierre del día.
        Livewire::test(DayMomentCard::class)
            ->mountAction('collect', ['patient' => $pedro->patient_id])
            ->assertActionDataSet(['amount' => 120.0])
            ->callMountedAction()
            ->assertNotified('Pago registrado')
            ->assertDispatched(Dashboard::CHANGED)
            ->assertDontSee(['Se fueron debiendo', 'Pedro Prueba']);
        $this->assertSame(0.0, $pedro->patient->balance());
    }

    public function test_who_stayed_checked_in_for_hours_is_no_longer_waiting(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $arrived = fn (string $firstName, string $when) => Appointment::factory()->for($doctor)->for($treatment)
            ->for(Patient::factory()->create(['first_name' => $firstName, 'last_name' => 'Prueba']))->at($when)
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => $when]);
        $arrived('Rosa', '2026-11-02 09:00');
        $arrived('Pedro', '2026-11-02 18:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 18:20', 'America/Lima'));
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(WaitingRoom::class)->assertSee('Pedro Prueba')->assertDontSee('Rosa Prueba');

        // Para su doctor tampoco es "tu próximo paciente": es una cita que quedó sin cerrar.
        $this->assertSame('Pedro', (new DoctorDay($doctor->id))->next()->patient->first_name);
        $this->assertSame(1, (new DoctorDay($doctor->id))->waiting());
    }

    public function test_home_shows_what_to_confirm_before_opening_and_what_is_left_when_closing(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $pedro = Patient::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Huamán']);
        $rosasVisit = Appointment::factory()->for($doctor)->for($treatment)->for($rosa)->at('2026-11-02 10:00')->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($pedro)->at('2026-11-02 16:00')->confirmed()->create();
        Appointment::factory()->for($doctor)->for($treatment)->for(Patient::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas']))->at('2026-11-02 17:00')->confirmed()->create();
        $this->actingAs(User::factory()->reception()->create());

        // 08:00, antes de abrir: lo que falta confirmar.
        $this->assertContains(DayMomentCard::class, $this->visibleWidgets());
        Livewire::test(DayMomentCard::class)
            ->assertSeeInOrder(['Antes de abrir', '1 cita de hoy sin confirmar', '10:00 · Rosa Quispe', 'Limpieza dental', 'Confirmar'])
            // La cita completa y no solo "Pedro": el doctor de la prueba lleva un nombre al azar y puede llamarse igual.
            ->assertDontSee(['16:00 · Pedro Huamán', 'Para cerrar el día']);

        // Durante el día mandan la agenda y la sala.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 11:00', 'America/Lima'));
        $this->assertNotContains(DayMomentCard::class, $this->visibleWidgets());

        // 19:30: Rosa se fue debiendo y la cita de Pedro quedó sin cerrar.
        $rosasVisit->update(['status' => AppointmentStatus::Completed]);
        Charge::factory()->for($rosa)->create(['amount' => 120, 'description' => 'Limpieza dental', 'charged_at' => '2026-11-02 10:40']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 19:30', 'America/Lima'));
        Livewire::test(DayMomentCard::class)
            ->assertSeeInOrder(['Para cerrar el día', 'Se fueron debiendo', 'S/ 120.00'])
            ->assertDontSee('Cobrado hoy')
            ->assertSeeInOrder(['Se fueron debiendo', 'Rosa Quispe', 'Citas sin cerrar', '16:00 · Pedro Huamán', '17:00 · Ana Rojas', 'Falta el cierre de caja', 'Cerrar la caja'])
            ->assertDontSee('Antes de abrir');

        // El doctor no la ve: lo suyo es su próximo paciente.
        $this->actingAs($doctor->user);
        $this->assertNotContains(DayMomentCard::class, $this->visibleWidgets());
    }

    public function test_what_is_done_on_one_card_shows_on_the_others_at_once(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($treatment);
        $rosa = Appointment::factory()->for($doctor)->for($treatment)->for($this->patient('Rosa', 'Quispe'))->at('2026-11-02 10:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        // Las tarjetas ya están abiertas cuando recepción toca un botón en otra.
        $stats = Livewire::test(TodayStats::class)->assertSee('Hoy y mañana · reenvía el aviso o llama');
        $agenda = Livewire::test(TodayAppointments::class)->assertSee('Pendiente');
        $room = Livewire::test(WaitingRoom::class)->assertSee('Nadie esperando');

        // "Confirmar" en "Antes de abrir" avisa a las demás, que se vuelven a pintar sin esperar su recarga de 30 s.
        Livewire::test(DayMomentCard::class)
            ->callAction('confirm', arguments: ['appointment' => $rosa->id])
            ->assertDispatched(Dashboard::CHANGED);
        $stats->dispatch(Dashboard::CHANGED)->assertSee('Hoy y mañana, todo confirmado');
        $agenda->dispatch(Dashboard::CHANGED)->assertSee('Confirmada')->assertDontSee('Pendiente');

        // "Llegó" en la agenda: la sala de espera la muestra al momento.
        $agenda->callTableAction('arrived', $rosa)->assertDispatched(Dashboard::CHANGED);
        $room->dispatch(Dashboard::CHANGED)->assertSee('Rosa Quispe')->assertDontSee('Nadie esperando');

        // Los botones de arriba también avisan: un cobro cambia "Cobrado hoy".
        Charge::factory()->for($rosa->patient)->create(['amount' => 120]);
        $stats->dispatch(Dashboard::CHANGED)->assertSee(['S/ 0.00', 'Falta cobrar S/ 120.00 a 1 paciente']);
        Livewire::test(Dashboard::class)
            ->mountAction('registerPayment')
            ->setActionData(['patient_id' => $rosa->patient_id, 'amount' => 120])
            ->callMountedAction()
            ->assertNotified('Pago registrado')
            ->assertDispatched(Dashboard::CHANGED);
        $stats->dispatch(Dashboard::CHANGED)->assertSee('S/ 120.00')->assertDontSee('Falta cobrar');

        // Las del costado y la del doctor escuchan el mismo aviso.
        Livewire::test(FollowUpsToday::class)->dispatch(Dashboard::CHANGED)->assertOk();
        Livewire::test(WeekGaps::class)->dispatch(Dashboard::CHANGED)->assertOk();
        $this->actingAs($doctor->user);
        Livewire::test(NextPatient::class)->dispatch(Dashboard::CHANGED)->assertSee('Rosa Quispe');
    }

    public function test_the_numbers_of_the_day_count_what_the_lists_they_lead_to_show(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $visit = fn (string $firstName, string $when, array $attributes = []) => Appointment::factory()->for($doctor)->for($treatment)
            ->for($this->patient($firstName, 'Prueba'))->at($when)->create($attributes);
        $today = $visit('Rosa', '2026-11-02 10:00');
        $tomorrow = $visit('Pedro', '2026-11-03 10:00');
        $later = $visit('Ana', '2026-11-05 10:00');
        $visit('Luis', '2026-11-02 11:00', ['status' => AppointmentStatus::NoShow]);
        $visit('Carmen', '2026-11-02 12:00', ['status' => AppointmentStatus::Cancelled]);
        $this->actingAs(User::factory()->reception()->create());

        // "Citas de hoy" cuenta las filas de la agenda de abajo: quien faltó sigue ahí; la cancelada, no.
        Livewire::test(TodayStats::class)
            ->assertSeeHtmlInOrder(['Citas de hoy', '<strong class="dash-kpi-value">2</strong>', '0 atendidas · 1 por llegar · 1 no asistió'])
            // "Por confirmar" son las de hoy y mañana, y lleva a la lista con esas mismas.
            ->assertSeeHtmlInOrder(['Por confirmar', '<strong class="dash-kpi-value">2</strong>', 'Hoy y mañana'])
            ->assertSeeHtml('href="'.e(AppointmentResource::toConfirmUrl(today(), today()->addDay())).'"');
        Livewire::test(TodayAppointments::class)->assertSee(['Rosa Prueba', 'Luis Prueba'])->assertDontSee('Carmen Prueba');

        Livewire::test(ListAppointments::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$today, $tomorrow, $later])
            ->filterTable('dates', ['from' => '2026-11-02', 'until' => '2026-11-03'])
            ->assertCanSeeTableRecords([$today, $tomorrow])
            ->assertCanNotSeeTableRecords([$later]);
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

    public function test_the_month_says_where_it_would_close_at_this_pace(): void
    {
        $patient = $this->patient('Rosa', 'Quispe');
        Payment::factory()->for($patient)->create(['amount' => 300, 'paid_at' => '2026-11-01 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 150, 'paid_at' => '2026-11-02 07:30']);
        Payment::factory()->for($patient)->create(['amount' => 200, 'paid_at' => '2026-10-01 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 1000, 'paid_at' => '2026-10-31 10:00']);
        Payment::factory()->for($patient)->create(['amount' => 999, 'paid_at' => '2026-10-31 11:00', 'voided_at' => '2026-10-31 12:00']);
        $this->actingAs(User::factory()->admin()->create());

        $this->assertSame(1200.0, (new MonthFigures)->figures()['collected_last_month']);

        // Los primeros días no se proyecta: un solo pago lo cambia todo.
        Livewire::test(MonthOverview::class)
            ->assertSee('Con tan pocos días, las diferencias todavía no dicen mucho.')
            ->assertDontSee('A este ritmo');

        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00', 'America/Lima'));
        Payment::factory()->for($patient)->create(['amount' => 550, 'paid_at' => '2026-11-10 09:00']);

        // S/ 1,000 en 10 días de 30: a este ritmo, S/ 3,000.
        Livewire::test(MonthOverview::class)
            ->assertSee('A este ritmo, noviembre cerraría en S/ 3,000.00; octubre cerró en S/ 1,200.00.');

        Setting::set('reports.monthly_goal', 4500);

        Livewire::test(MonthOverview::class)
            ->assertSee('A este ritmo, noviembre cerraría en S/ 3,000.00, por debajo de la meta de S/ 4,500.');
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
        $recent = TreatmentPlan::create(['patient_id' => $luis->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed, 'proposed_at' => now()->subDay()]);

        $ana = $this->patient('Ana', 'Flores');
        $unscheduled = $this->acceptedPlan($ana, $doctor, 'Corona', '46');

        // Aceptó y ya tiene su próxima cita: no hay nada que perseguir.
        $pedro = $this->patient('Pedro', 'Huamán');
        $scheduled = $this->acceptedPlan($pedro, $doctor, 'Endodoncia', '36');
        Appointment::factory()->for($pedro)->for($doctor)->for($treatment)->at('2026-11-03 10:00')->create();

        $carmen = $this->patient('Carmen', 'Rojas', ['birth_date' => '1990-11-02']);
        $jose = $this->patient('José', 'Condori', ['last_visit_at' => now()->subMonths(7)]);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Presupuestos sin respuesta', 'Rosa Quispe', 'S/ 450.00 · Dra. Ana Torres · presentado hace 1 semana'])
            ->assertDontSee('Luis Mamani')
            ->assertSee(['Planes sin próxima cita', 'Ana Flores', 'Falta: Corona', 'Nueva cita'])
            ->assertDontSee(['Pedro Huamán', 'pieza 46'])
            ->assertSee(['Para su control', 'José Condori', 'Cumpleaños de hoy', 'Carmen Rojas', 'Cumple 36 años'])
            // Cada grupo lleva a su pestaña de Presupuestos y planes, donde se les escribe y se les agenda.
            ->assertSeeHtml('href="'.e(TreatmentPlanResource::getUrl('index', ['tab' => 'awaiting'])).'"')
            ->assertSeeHtml('href="'.e(TreatmentPlanResource::getUrl('index', ['tab' => 'unscheduled'])).'"');

        Livewire::test(ListTreatmentPlans::class)
            ->set('activeTab', 'awaiting')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$recent, $unscheduled, $scheduled])
            ->set('activeTab', 'unscheduled')
            ->assertCanSeeTableRecords([$unscheduled])
            ->assertCanNotSeeTableRecords([$waiting, $scheduled]);
    }

    public function test_the_follow_up_numbers_count_budgets_like_the_tabs_they_lead_to(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create();

        // Rosa tiene dos presupuestos sin respuesta: se le escribe una vez, pero la pestaña muestra los dos.
        $rosa = $this->patient('Rosa', 'Quispe');
        foreach ([8, 20] as $daysAgo) {
            TreatmentPlan::create(['patient_id' => $rosa->id, 'doctor_id' => $doctor->id, 'status' => TreatmentPlanStatus::Proposed, 'proposed_at' => now()->subDays($daysAgo)])
                ->items()->create(['description' => 'Ortodoncia', 'unit_price' => 450]);
        }

        $this->actingAs(User::factory()->reception()->create());

        $this->assertSame(2, app(FollowUps::class)->plansAwaitingAnswer()['count']);
        $this->assertCount(1, app(FollowUps::class)->plansAwaitingAnswer()['plans']);

        Livewire::test(ListTreatmentPlans::class)
            ->set('activeTab', 'awaiting')
            ->assertCountTableRecords(2);
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

    public function test_regulars_who_leave_happy_are_asked_for_their_google_review_once_in_six_months(): void
    {
        Setting::set('clinic.reviews_url', 'https://g.page/r/vega/review');
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $visit = fn (Patient $patient, string $at, AppointmentStatus $status = AppointmentStatus::Completed) => Appointment::factory()->for($patient)->for($cleaning)->at($at)->create(['status' => $status]);
        $regular = function (string $firstName, string $lastName) use ($visit): Patient {
            $patient = $this->patient($firstName, $lastName);
            $visit($patient, '2026-05-04 10:00');
            $visit($patient, '2026-11-02 09:00');

            return $patient;
        };

        // Viene seguido, está al día y no falta: hoy es el día para pedírsela.
        $rosa = $regular('Rosa', 'Quispe');
        // Primera vez: todavía no.
        $visit($this->patient('Luis', 'Mamani'), '2026-11-02 09:30');
        // Se fue debiendo.
        Charge::factory()->for($regular('Ana', 'Flores'))->create(['amount' => 120]);
        // Faltó a una cita hace dos meses.
        $visit($regular('Pedro', 'Huamán'), '2026-09-07 10:00', AppointmentStatus::NoShow);
        // Ya se le pidió hace tres meses.
        $carmen = $regular('Carmen', 'Rojas');
        WhatsappMessage::create(['account_id' => $carmen->account_id, 'patient_id' => $carmen->id, 'template_key' => MessageTemplateKey::ReviewRequest, 'phone' => '+51987654321', 'status' => MessageStatus::Sent, 'sent_at' => '2026-08-03 10:00']);

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Pedir su opinión en Google', 'Rosa Quispe', 'Hoy: Limpieza dental · 2 atenciones'])
            ->assertDontSee(['Luis Mamani', 'Ana Flores', 'Pedro Huamán', 'Carmen Rojas'])
            ->mountAction('whatsapp', ['template' => MessageTemplateKey::ReviewRequest->value, 'patient' => $rosa->id])
            ->assertSchemaStateSet(['body' => "Hola Rosa, gracias por confiar en Clínica Vega Implant. Si te sentiste bien atendido, ¿nos dejas tu opinión en Google? Toma un minuto y ayuda a otras personas a encontrarnos:\nhttps://g.page/r/vega/review"])
            ->callMountedAction()
            ->assertHasNoErrors();

        Livewire::test(FollowUpsToday::class)->assertSee(['Rosa Quispe', 'Le escribiste hoy']);

        // La semana siguiente vuelve, contenta otra vez: ya se le pidió, no se le insiste.
        $this->travelTo(now()->addWeek());
        $visit($rosa, '2026-11-09 09:00');

        Livewire::test(FollowUpsToday::class)->assertDontSee('Rosa Quispe');

        // Sin el enlace a Google configurado, no se ofrece a nadie.
        $visit($regular('Elena', 'Vargas'), '2026-11-09 10:00');
        Setting::set('clinic.reviews_url', null);

        Livewire::test(FollowUpsToday::class)->assertDontSee(['Pedir su opinión', 'Elena Vargas']);
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
            // Arriba, el total de la semana (el que antes repetía "Horas libres" en los números del día).
            ->assertSee('Quedan 39'.self::NBSP.'h 20'.self::NBSP.'min libres de hoy a 7 días.')
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
            ->create(['status' => AppointmentStatus::CheckedIn, 'checked_in_at' => now()->subMinutes(25), 'patient_note' => 'Me duele al masticar', 'internal_notes' => 'Trae su radiografía']);

        $unfinished = Consultation::factory()->for($doctor)->for($this->patient('Luz', 'Ramos'))->create(['attended_at' => now()->subDay()]);
        Consultation::factory()->for($doctor)->for($this->patient('Eva', 'Sol'))->create(['attended_at' => now()->subMinutes(40)]);

        $this->actingAs($doctor->user);

        // Quien ya está en sala va primero, aunque su cita sea más tarde.
        Livewire::test(NextPatient::class)
            ->assertSee(['11:00', 'José Condori', '36 años', 'Curación con resina', 'En sala hace 25'.self::NBSP.'min'])
            ->assertSee(['Alergia: Penicilina', 'Limpieza dental · 5 oct. 2026', 'Ver historia'])
            ->assertDontSee('María Quispe')
            // Lo que el paciente escribió al reservar y lo que recepción anotó al agendar.
            ->assertSeeInOrder(['Dejó una nota', 'Me duele al masticar', 'Nota interna', 'Trae su radiografía'])
            ->assertSee(['Atenciones sin terminar', 'Luz Ramos', 'Continuar'])
            // La que quedó de ayer resalta: mientras siga abierta no se cobra ni avanza el plan. La de hoy, no.
            ->assertSeeHtmlInOrder(['Luz Ramos', '<small class="dash-late">abierta hace 1 día</small>', 'Eva Sol', '<small class="">abierta hoy a las 07:20</small>']);

        // En la agenda, la etiqueta ya dice "En sala": la fila solo agrega cuánto lleva.
        Livewire::test(TodayAppointments::class)->assertSee('Esperando 25'.self::NBSP.'min')->assertDontSee('En sala hace');

        Livewire::test(NextPatient::class)
            ->callAction('start', arguments: ['appointment' => $waiting->id])
            ->assertRedirect(ConsultationResource::getUrl('edit', ['record' => $waiting->fresh()->consultation]));

        $this->assertSame(AppointmentStatus::InProgress, $waiting->fresh()->status);
        $this->assertNotNull($unfinished->fresh());
    }

    public function test_more_unfinished_visits_than_fit_on_home_lead_to_their_tab_in_the_list(): void
    {
        $doctor = Doctor::factory()->withSchedule()->create();
        Consultation::factory()->count(4)->for($doctor)->create(['attended_at' => now()->subDay()]);

        $this->actingAs($doctor->user);

        Livewire::test(NextPatient::class)
            ->assertSee('Ver las 4')
            ->assertSeeHtml('href="'.e(ConsultationResource::getUrl('index', ['tab' => 'open'])).'"');
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

        // Los números del día dicen cuánto va y cuánto falta; quién está en sala o en atención lo dicen la sala y la agenda.
        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(TodayStats::class)
            ->assertSee('0 atendidas · 2 por llegar')
            ->assertDontSee('en atención')
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

        // Mientras sigan abiertas no se cobra lo atendido: su número va pintado, como lo demás que no puede esperar.
        Livewire::test(FollowUpsToday::class)
            ->assertSee(['Citas sin cerrar', 'Ya pasó su hora: marca si vino o si no asistió'])
            ->assertSeeHtmlInOrder(['Citas sin cerrar', '<span class="dash-count is-warning">2</span>']);

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
