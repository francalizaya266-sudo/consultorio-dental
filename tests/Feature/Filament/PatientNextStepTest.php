<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PaymentMethod;
use App\Enums\PlanPaymentMode;
use App\Enums\RequestedBy;
use App\Enums\RescheduleRequestStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\PatientSummary;
use App\Filament\Widgets\NextPatient;
use App\Filament\Widgets\TodayAppointments;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\ConsentTemplate;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Dashboard\FollowUps;
use App\Services\Patients\PatientInsights;
use App\Services\WhatsApp\WhatsAppOutbox;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PatientNextStepTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_file_says_what_is_next_for_each_one_with_the_button_to_do_it(): void
    {
        $this->freezeMonday();
        $endo = Treatment::factory()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($endo);
        $rosa = Patient::factory()->create(['first_name' => 'Rosa']);
        $plan = TreatmentPlan::create(['patient_id' => $rosa->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()->subWeek()]);
        $item = $plan->items()->create(['treatment_id' => $endo->id, 'description' => 'Endodoncia', 'tooth' => '46', 'unit_price' => 400, 'sessions_planned' => 2, 'accepted_at' => now()->subWeek()]);
        $visit = Appointment::factory()->for($doctor)->for($endo)->for($rosa)->at('2026-11-02 07:00')->create(['status' => AppointmentStatus::InProgress, 'treatment_plan_item_id' => $item->id]);
        $consultation = Consultation::factory()->for($rosa)->for($doctor)->create(['appointment_id' => $visit->id, 'attended_at' => '2026-11-02 07:05']);

        // El doctor que la atiende: terminar lo que dejó abierto.
        $this->actingAs($doctor->user);
        $this->get(PatientResource::getUrl('view', ['record' => $rosa]))
            ->assertOk()
            ->assertSeeInOrder(['Siguiente paso', 'Continuar la atención', 'Endodoncia · empezó hace 55 minutos', 'Continuar'])
            ->assertSee(ConsultationResource::getUrl('edit', ['record' => $consultation]), escape: false);

        // Recepción no atiende: para ella lo que sigue es darle la cita de su plan, sin la pieza.
        $this->actingAs(User::factory()->reception()->create());
        $this->get(PatientResource::getUrl('view', ['record' => $rosa]))
            ->assertOk()
            ->assertSeeInOrder(['Siguiente paso', 'Agendar: Endodoncia', 'Sesión 1 de 2 · de su plan con '.$doctor->name])
            ->assertSee(AppointmentResource::newUrl(['patient' => $rosa->id, 'doctor' => $doctor->id, 'plan_item' => $item->id, 'treatment' => $endo->id]))
            ->assertDontSee(['Continuar la atención', 'pieza 46']);

        // Con la siguiente sesión ya agendada no hay nada que agendar.
        Appointment::factory()->for($doctor)->for($endo)->for($rosa)->at('2026-11-09 10:00')->confirmed()->create(['treatment_plan_item_id' => $item->id]);
        $this->assertNull(PatientSummary::nextStep($rosa, auth()->user()));
    }

    public function test_a_visit_today_whose_consent_is_missing_asks_to_sign_it_there(): void
    {
        $this->freezeMonday();
        $extraction = Treatment::factory()->create(['name' => 'Extracción simple']);
        $doctor = $this->doctorOffering($extraction);
        $patient = Patient::factory()->create();
        ConsentTemplate::create(['name' => 'Consentimiento de extracción', 'treatment_id' => $extraction->id, 'body' => 'Extracción de {paciente}.']);
        $visit = Appointment::factory()->for($doctor)->for($extraction)->for($patient)->at('2026-11-02 10:00')->confirmed()->create();

        $this->actingAs($doctor->user);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertSeeInOrder(['Falta firmar el consentimiento', 'Extracción simple · cita de hoy a las 10:00', 'Firmar'])
            ->call('mountAction', 'consentForVisit', ['appointment' => $visit->id])
            ->assertSchemaStateSet(['treatment_id' => $extraction->id, 'signer_name' => $patient->full_name]);

        // Recepción no firma consentimientos: no se lo pide.
        $this->actingAs(User::factory()->reception()->create());
        $this->assertNotSame('consent', PatientSummary::nextStep($patient, auth()->user())['key'] ?? null);
    }

    public function test_the_checkup_comes_due_by_what_was_done_last(): void
    {
        $this->freezeMonday();
        $ortho = Treatment::factory()->create(['name' => 'Control de ortodoncia']);
        $implant = Treatment::factory()->create(['name' => 'Implante dental']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($ortho, $implant, $cleaning);

        $visited = function (Treatment $treatment, string $when) use ($doctor): Patient {
            $patient = Patient::factory()->create(['last_visit_at' => $when]);
            Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at($when.' 10:00')->create(['status' => AppointmentStatus::Completed]);

            return $patient;
        };

        $braces = $visited($ortho, '2026-09-25');        // 5 semanas: el control de ortodoncia es cada mes.
        $bracesRecent = $visited($ortho, '2026-10-20');  // 2 semanas: todavía no.
        $implanted = $visited($implant, '2026-03-02');   // 8 meses: el implante se controla al año.
        $cleaned = $visited($cleaning, '2026-03-02');    // 8 meses: la limpieza, a los 6.

        $this->assertEqualsCanonicalizing([$braces->id, $cleaned->id], Patient::query()->dueForCheckup()->pluck('id')->all());

        $this->actingAs($doctor->user);
        $this->get(PatientResource::getUrl('view', ['record' => $braces]))
            ->assertOk()
            ->assertSeeInOrder(['Siguiente paso', 'Le toca su control de ortodoncia', 'Última visita hace 1 mes', 'Agendar']);
        $this->get(PatientResource::getUrl('view', ['record' => $implanted]))
            ->assertOk()
            ->assertDontSee('Le toca su')
            ->assertSee('Última visita hace 8 meses');

        $insights = app(PatientInsights::class);
        $this->assertFalse($insights->checkup($bracesRecent)['due']);
        $this->assertSame('2027-03-02', $insights->checkup($implanted)['on']->toDateString());

        // El aviso por WhatsApp dice qué control le toca.
        $draft = fn (Patient $patient) => app(WhatsAppOutbox::class)->draft(app(FollowUps::class)->draft(MessageTemplateKey::CheckupRecall, $patient));
        $this->assertStringContainsString('Ya es momento de tu control de ortodoncia.', $draft($braces));
        $this->assertStringContainsString('Ya es momento de tu control y limpieza.', $draft($cleaned));
    }

    public function test_no_shows_usual_day_and_payment_method_are_read_from_the_history(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->for(Account::factory()->state(['phone' => '+51987654321']))->create();
        $other = Patient::factory()->create();

        // Martes en la mañana, tres veces; faltó dos de sus últimas cinco (la cancelada no cuenta).
        foreach (['2026-09-08 10:00', '2026-09-22 09:00', '2026-10-06 11:00'] as $when) {
            Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at($when)->create(['status' => AppointmentStatus::Completed]);
        }
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-10-13 10:00')->create(['status' => AppointmentStatus::NoShow]);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-10-20 10:00')->create(['status' => AppointmentStatus::NoShow]);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-10-27 10:00')->cancelled()->create();
        Appointment::factory()->for($doctor)->for($treatment)->for($other)->at('2026-10-27 10:00')->create(['status' => AppointmentStatus::NoShow]);
        Payment::factory()->for($patient)->count(2)->create(['method' => PaymentMethod::Yape]);

        $insights = app(PatientInsights::class);
        $this->assertSame(['missed' => 2, 'of' => 5], $insights->noShows($patient));
        $this->assertSame([$patient->id => ['missed' => 2, 'of' => 5], $other->id => ['missed' => 1, 'of' => 1]], PatientInsights::noShowsFor([$patient->id, $other->id]));
        $this->assertSame('Suele venir los martes en la mañana', $insights->usualVisit($patient)['label']);
        $this->assertNull($insights->usualVisit($other));

        $this->actingAs(User::factory()->reception()->create());
        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSee(['Faltó a 2 de sus últimas 5 citas', 'tel:+51987654321', 'Suele venir los martes en la mañana', 'Paga con Yape']);
    }

    public function test_the_balance_says_when_an_installment_is_late_and_is_collected_right_there(): void
    {
        $this->freezeMonday();
        $doctor = Doctor::factory()->create();
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Ortodoncia', 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonths(2)]);
        $plan->installments()->create(['number' => 1, 'due_on' => '2026-10-02', 'amount' => 250]);
        $plan->installments()->create(['number' => 2, 'due_on' => '2026-11-05', 'amount' => 250]);
        app(BillingService::class)->chargeDueInstallments();

        $insights = app(PatientInsights::class);
        $this->assertSame(250.0, $insights->lateInstallments($patient)['due']);
        $upcoming = $insights->upcomingInstallment($patient, today()->addWeek());
        $this->assertSame([250.0, '2026-11-05'], [$upcoming['due'], $upcoming['on']->toDateString()]);
        $this->assertNull($insights->upcomingInstallment($patient, today()->addDays(2)));

        Payment::factory()->for($patient)->create(['method' => PaymentMethod::Plin, 'amount' => 10]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertSee('Debe S/ 240.00 · cuota vencida el 2 oct.')
            ->call('mountAction', 'collectPayment')
            ->assertSchemaStateSet(['amount' => 240.0, 'method' => PaymentMethod::Plin]);
    }

    public function test_birthday_greeting_and_plan_reminder_come_written_from_the_file_and_the_visit(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->for(Account::factory())->create(['first_name' => 'Rosa', 'birth_date' => '1990-11-02']);
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Proposed, 'proposed_at' => now()->subDays(9)]);
        $plan->items()->create(['description' => 'Curación con resina', 'unit_price' => 150]);
        $visit = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-09 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertSeeInOrder(['Presupuesto sin respuesta', 'S/ 150.00 · se le presentó hace 1 semana', 'Recordar por WhatsApp'])
            ->assertSee('Hoy cumple 36 años · saludar')
            ->call('mountAction', 'followUp', ['template' => 'birthday'])
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('¡Feliz cumpleaños, Rosa!', $state['body']) ?? []);

        Livewire::test(ViewAppointment::class, ['record' => $visit->getRouteKey()])
            ->assertSee('Presupuesto sin respuesta')
            ->call('mountAction', 'followUp', ['template' => 'plan_follow_up'])
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('¿Pudiste revisar tu plan de tratamiento con '.$doctor->name.'?', $state['body']) ?? []);
    }

    public function test_the_day_lists_warn_about_alerts_reschedules_no_shows_and_the_plan_session(): void
    {
        $this->freezeMonday();
        $endo = Treatment::factory()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($endo);
        $patient = Patient::factory()->create(['first_name' => 'Rosa']);
        MedicalBackground::create(['patient_id' => $patient->id, 'allergies' => 'Penicilina']);
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::InProgress, 'accepted_at' => now()->subWeek()]);
        $item = $plan->items()->create(['treatment_id' => $endo->id, 'description' => 'Endodoncia', 'tooth' => '46', 'unit_price' => 400, 'sessions_planned' => 3, 'sessions_done' => 1, 'accepted_at' => now()->subWeek()]);
        foreach (['2026-10-12 10:00', '2026-10-19 10:00'] as $when) {
            Appointment::factory()->for($doctor)->for($endo)->for($patient)->at($when)->create(['status' => AppointmentStatus::NoShow]);
        }
        $today = Appointment::factory()->for($doctor)->for($endo)->for($patient)->at('2026-11-02 10:00')->confirmed()->create(['treatment_plan_item_id' => $item->id]);
        $today->rescheduleRequests()->create(['requested_by' => RequestedBy::Patient, 'status' => RescheduleRequestStatus::Pending]);

        // Recepción: pidió cambio, suele faltar y qué sesión es; la alergia es del doctor.
        $this->actingAs(User::factory()->reception()->create());
        Livewire::test(TodayAppointments::class)
            ->assertTableColumnStateSet('flags', ['Pidió cambio', 'Faltó 2 de 2', 'Sesión 2 de 3'], $today);
        Livewire::test(Agenda::class)
            ->assertSee(['Pidió cambio', 'Faltó 2 de 2', 'Endodoncia · sesión 2 de 3'])
            ->assertDontSee('Alerta médica');

        $this->actingAs($doctor->user);
        Livewire::test(Agenda::class)
            ->assertSee(['Alerta médica', 'title="Alergia: Penicilina"'], escape: false);
        Livewire::test(NextPatient::class)
            ->assertSee('Endodoncia · pieza 46 · sesión 2 de 3');
    }

    public function test_attending_shows_the_last_blood_pressure_and_what_follows_in_the_plan(): void
    {
        $this->freezeMonday();
        $endo = Treatment::factory()->create(['name' => 'Endodoncia']);
        $doctor = $this->doctorOffering($endo);
        $patient = Patient::factory()->create();
        Consultation::factory()->for($patient)->for($doctor)->create(['attended_at' => '2026-10-12 10:00', 'closed_at' => '2026-10-12 10:40', 'vital_signs' => ['blood_pressure' => '140/90']]);
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::InProgress, 'accepted_at' => now()->subWeek()]);
        $endodontics = $plan->items()->create(['treatment_id' => $endo->id, 'description' => 'Endodoncia', 'tooth' => '46', 'unit_price' => 400, 'sessions_planned' => 2, 'accepted_at' => now()->subWeek(), 'sort_order' => 1]);
        $plan->items()->create(['description' => 'Corona de porcelana', 'tooth' => '46', 'unit_price' => 900, 'accepted_at' => now()->subWeek(), 'sort_order' => 2]);
        $visit = Appointment::factory()->for($doctor)->for($endo)->for($patient)->at('2026-11-02 10:00')->create(['status' => AppointmentStatus::InProgress, 'treatment_plan_item_id' => $endodontics->id]);
        $consultation = Consultation::factory()->for($patient)->for($doctor)->create(['appointment_id' => $visit->id, 'vital_signs' => null]);
        $consultation->planItems()->attach($endodontics);

        $this->actingAs($doctor->user);

        // Hoy va la primera de dos sesiones: lo que sigue es la segunda.
        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->assertSee(['La última: 140/90 el 12 oct. 2026', 'Sigue en su plan: Endodoncia · pieza 46 · sesión 2 de 2']);

        $endodontics->update(['sessions_done' => 1]);
        $this->assertSame('Corona de porcelana', app(PatientInsights::class)->planAfter($consultation)['item']->description);
    }

    public function test_checkout_mentions_the_installment_due_in_the_next_days(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'price_from' => 120]);
        $doctor = $this->doctorOffering($cleaning);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->for($doctor)->for($cleaning)->for($patient)->at('2026-11-02 07:00')->create(['status' => AppointmentStatus::Completed]);
        $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Ortodoncia', 'status' => TreatmentPlanStatus::InProgress, 'payment_mode' => PlanPaymentMode::Installments, 'accepted_at' => now()->subMonth()]);
        $plan->installments()->create(['number' => 1, 'due_on' => '2026-11-05', 'amount' => 250]);
        $this->actingAs($reception = User::factory()->reception()->create());
        app(BillingService::class)->chargeAppointment($appointment, $reception);

        Livewire::test(TodayAppointments::class)
            ->mountAction(TestAction::make('checkout')->table($appointment))
            ->assertSchemaStateSet(['upcoming' => 'El jueves 5 de noviembre vence su cuota de S/ 250.00: si quiere, la puede pagar hoy.']);
    }

    public function test_booking_proposes_the_day_and_times_the_patient_usually_comes(): void
    {
        $this->freezeMonday();
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental', 'duration_minutes' => 30]);
        $doctor = $this->doctorOffering($cleaning);
        $patient = Patient::factory()->create();

        // Viene los miércoles en la tarde.
        foreach (['2026-09-09 16:00', '2026-09-23 17:00', '2026-10-14 16:30'] as $when) {
            Appointment::factory()->for($doctor)->for($cleaning)->for($patient)->at($when)->create(['status' => AppointmentStatus::Completed]);
        }

        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment')
            ->setActionData(['treatment_id' => $cleaning->id, 'doctor_id' => $doctor->id])
            ->setActionData(['patient_id' => $patient->id])
            ->assertActionDataSet(['date' => '2026-11-04'])
            ->assertMountedActionModalSee('Suele venir los miércoles en la tarde: esos horarios van primero.')
            ->assertSchemaComponentExists('slot', checkComponentUsing: function (ToggleButtons $slot): bool {
                $slots = array_keys($slot->getOptions());

                return $slots[0] === '15:00' && in_array('09:00', $slots, true);
            });

        // Desde la ficha, con el paciente en el enlace, el día ya viene elegido.
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['patient' => $patient->id])
            ->assertActionDataSet(['patient_id' => $patient->id, 'date' => '2026-11-04']);

        // Si se pidió otro día (se miraba ese en la agenda, o lo trae el enlace), se respeta.
        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['date' => '2026-11-06', 'doctor' => $doctor->id])
            ->setActionData(['patient_id' => $patient->id])
            ->assertActionDataSet(['date' => '2026-11-06']);
    }
}
