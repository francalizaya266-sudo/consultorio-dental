<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Booking\AppointmentService;
use App\Services\Patients\PatientInsights;
use App\Services\WhatsApp\WhatsAppOutbox;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class TreatmentRulesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_the_checkup_follows_the_months_written_on_the_treatment(): void
    {
        $this->freezeMonday();
        $guard = Treatment::factory()->create(['name' => 'Férula de descarga', 'checkup_months' => 3]);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($guard, $cleaning);

        $visited = function (Treatment $treatment) use ($doctor): Patient {
            $patient = Patient::factory()->create(['last_visit_at' => '2026-07-02']);
            Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-07-02 10:00')->create(['status' => AppointmentStatus::Completed]);

            return $patient;
        };

        // Los dos vinieron hace 4 meses: a la férula le toca a los 3; a la limpieza, a los 6 de siempre.
        $withGuard = $visited($guard);
        $cleaned = $visited($cleaning);

        $this->assertSame([$withGuard->id], Patient::query()->dueForCheckup()->pluck('id')->all());

        $insights = app(PatientInsights::class);
        $this->assertTrue($insights->checkup($withGuard)['due']);
        $this->assertSame('2026-10-02', $insights->checkup($withGuard)['on']->toDateString());
        $this->assertFalse($insights->checkup($cleaned)['due']);
    }

    public function test_the_next_session_is_suggested_at_the_days_written_on_the_treatment(): void
    {
        $this->freezeMonday();
        $crown = Treatment::factory()->create(['name' => 'Corona de porcelana', 'next_session_days' => 14]);
        $filling = Treatment::factory()->create(['name' => 'Curación con resina']);
        $doctor = $this->doctorOffering($crown, $filling);

        $withPlanFor = function (Treatment $treatment) use ($doctor): Patient {
            $patient = Patient::factory()->create();
            $plan = TreatmentPlan::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Accepted, 'accepted_at' => now()]);
            $plan->items()->create(['treatment_id' => $treatment->id, 'description' => $treatment->name, 'unit_price' => 300, 'sessions_planned' => 2, 'accepted_at' => now()]);

            return $patient;
        };

        $insights = app(PatientInsights::class);
        $crownVisit = $insights->suggestedNextVisit($withPlanFor($crown));
        $fillingVisit = $insights->suggestedNextVisit($withPlanFor($filling));

        $this->assertSame('2026-11-16', $crownVisit['on']->toDateString());
        $this->assertSame('A las 2 semanas', $crownVisit['why']);
        $this->assertSame('2026-11-09', $fillingVisit['on']->toDateString());
        $this->assertSame('A la semana', $fillingVisit['why']);
    }

    public function test_the_reminder_says_what_the_treatment_asks_before_coming(): void
    {
        $this->freezeMonday();
        $extraction = Treatment::factory()->create(['name' => 'Extracción simple', 'before_instructions' => 'Ven habiendo comido algo ligero.']);
        $cleaning = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $extractionVisit = Appointment::factory()->for($extraction)->at('2026-11-03 10:00')->create();
        $cleaningVisit = Appointment::factory()->for($cleaning)->at('2026-11-03 11:00')->create();

        $this->artisan('citas:preparar-recordatorios')->assertSuccessful();

        $reminderFor = fn (Appointment $appointment) => app(WhatsAppOutbox::class)->render(WhatsappMessage::query()->whereBelongsTo($appointment)->sole());
        $withInstructions = $reminderFor($extractionVisit);
        $plain = $reminderFor($cleaningVisit);

        $this->assertStringContainsString("\nAntes de venir: Ven habiendo comido algo ligero.\n\nConfirma tu asistencia aquí:", $withInstructions);
        $this->assertStringNotContainsString('Antes de venir', $plain);
        $this->assertStringNotContainsString('{indicaciones}', $plain);
        $this->assertStringNotContainsString("\n\n\n", $plain);
    }

    public function test_closing_a_visit_leaves_the_aftercare_of_its_treatment_ready_to_send(): void
    {
        $this->freezeMonday();
        $extraction = Treatment::factory()->create(['name' => 'Extracción simple', 'aftercare' => "- Hoy no escupas ni te enjuagues.\n- Come blando y frío."]);
        $evaluation = Treatment::factory()->create(['name' => 'Evaluación']);
        $doctor = $this->doctorOffering($extraction, $evaluation);
        $reception = User::factory()->reception()->create();
        $appointments = app(AppointmentService::class);

        $today = Appointment::factory()->for($doctor)->for($extraction)->at('2026-11-02 07:00')->confirmed()->create();
        $withoutAftercare = Appointment::factory()->for($doctor)->for($evaluation)->at('2026-11-02 07:30')->confirmed()->create();
        $lastWeek = Appointment::factory()->for($doctor)->for($extraction)->at('2026-10-26 10:00')->confirmed()->create();

        $appointments->markCompleted($today, $reception);
        $appointments->markCompleted($withoutAftercare, $reception);
        $appointments->markCompleted($lastWeek, $reception);

        // Solo la de hoy con cuidados escritos: a la de la semana pasada ya no le sirven.
        $notice = WhatsappMessage::query()->where('template_key', MessageTemplateKey::Aftercare)->sole();
        $this->assertTrue($notice->appointment->is($today));

        $body = app(WhatsAppOutbox::class)->render($notice);
        $this->assertStringContainsString("Para que tu extracción simple quede bien:\n\n- Hoy no escupas ni te enjuagues.\n- Come blando y frío.\n\nSi tienes dolor fuerte", $body);
    }

    public function test_the_treatment_form_proposes_the_usual_texts_for_that_kind_of_treatment(): void
    {
        $extraction = Treatment::factory()->create(['name' => 'Extracción simple']);
        $other = Treatment::factory()->create(['name' => 'Férula de descarga']);
        $this->actingAs(User::factory()->admin(isDentist: true)->create());

        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($extraction))
            ->assertMountedActionModalSee(['Seguimiento y avisos', 'Usar texto sugerido'])
            ->callAction(TestAction::make('suggest_aftercare')->schemaComponent('aftercare'))
            ->callAction(TestAction::make('suggest_before_instructions')->schemaComponent('before_instructions'))
            ->fillForm(['checkup_months' => 3, 'next_session_days' => 10])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $extraction->refresh();
        $this->assertStringContainsString('Muerde la gasa 30 minutos', $extraction->aftercare);
        $this->assertStringContainsString('Ven habiendo comido algo ligero', $extraction->before_instructions);
        $this->assertSame(3, $extraction->checkupMonths());
        $this->assertSame(10, $extraction->nextSessionDays());

        // Para un tratamiento que el sistema no reconoce no hay texto que sugerir.
        Livewire::test(ManageTreatments::class)
            ->mountAction(TestAction::make('edit')->table($other))
            ->assertMountedActionModalDontSee('Usar texto sugerido');
    }
}
