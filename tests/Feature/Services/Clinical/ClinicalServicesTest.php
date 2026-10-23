<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\AppointmentStatus;
use App\Enums\Dentition;
use App\Enums\OdontogramType;
use App\Enums\ToothState;
use App\Exceptions\ClinicalException;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\OdontogramCondition;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\OdontogramService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ClinicalServicesTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
    }

    public function test_attending_an_appointment_opens_the_consultation_with_the_treatment_template_and_a_first_odontogram(): void
    {
        $treatment = Treatment::where('slug', 'limpieza-dental')->firstOrFail();
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['birth_date' => now()->subYears(30)]);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-02 10:00')->confirmed()->create();

        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);
        $this->assertSame('Acude para control y limpieza dental.', $consultation->chief_complaint);
        $this->assertStringContainsString('profilaxis', $consultation->procedures);
        $this->assertSame(OdontogramType::Initial, $consultation->odontogram->type);
        $this->assertSame(Dentition::Permanent, $consultation->odontogram->dentition);

        // Tocar "Atender" otra vez retoma la misma atención.
        $this->assertTrue($consultation->is($this->consultations()->startFromAppointment($appointment->fresh(), $doctor->user)));
    }

    public function test_the_next_consultation_starts_from_the_previous_odontogram(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['birth_date' => now()->subYears(9)]);

        $first = $this->consultations()->startWithoutAppointment($patient, $doctor->user);
        $this->assertSame(Dentition::Mixed, $first->odontogram->dentition);
        $this->odontograms()->toggle($first->odontogram, $this->condition('caries'), ToothState::Bad, 16, 'O');

        $second = $this->consultations()->startWithoutAppointment($patient, $doctor->user);

        $this->assertSame(OdontogramType::Evolution, $second->odontogram->type);
        $this->assertNotSame($first->odontogram->id, $second->odontogram->id);
        $this->assertSame([16], $second->odontogram->entries()->pluck('tooth')->all());
    }

    public function test_only_the_doctor_of_the_appointment_can_attend_it(): void
    {
        $treatment = Treatment::factory()->create();
        $appointment = Appointment::factory()->for($this->doctorOffering($treatment))->for($treatment)->at('2026-11-02 10:00')->create();
        $otherDoctor = $this->doctorOffering($treatment);

        $this->expectExceptionObject(ClinicalException::notYourAppointment());
        $this->consultations()->startFromAppointment($appointment, $otherDoctor->user);
    }

    public function test_users_without_a_doctor_profile_cannot_open_consultations(): void
    {
        $this->expectExceptionObject(ClinicalException::noDoctorProfile());
        $this->consultations()->startWithoutAppointment(Patient::factory()->create(), User::factory()->admin(isDentist: true)->create());
    }

    public function test_closing_completes_the_appointment_and_reopening_is_allowed_for_24_hours(): void
    {
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $appointment = Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();
        $consultation = $this->consultations()->startFromAppointment($appointment, $doctor->user);

        $this->consultations()->close($consultation, $doctor->user);

        $this->assertNotNull($consultation->fresh()->closed_at);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertNotNull($appointment->patient->fresh()->last_visit_at);
        $this->assertFalse($doctor->user->can('update', $consultation->fresh()));
        $this->assertTrue($doctor->user->can('reopen', $consultation->fresh()));

        $this->consultations()->reopen($consultation->fresh(), $doctor->user);
        $this->assertNull($consultation->fresh()->closed_at);
        $this->assertTrue(AuditLog::where('auditable_type', $consultation->getMorphClass())->where('auditable_id', $consultation->id)->where('event', 'updated')->exists());

        $this->consultations()->close($consultation->fresh(), $doctor->user);
        $this->travel(25)->hours();

        $this->assertFalse($doctor->user->can('reopen', $consultation->fresh()));
        $this->expectExceptionObject(ClinicalException::reopenExpired());
        $this->consultations()->reopen($consultation->fresh(), $doctor->user);
    }

    public function test_applying_a_template_fills_empty_fields_and_appends_to_written_ones(): void
    {
        $treatment = Treatment::where('slug', 'extraccion-simple')->firstOrFail();
        $doctor = $this->doctorOffering($treatment);
        $consultation = Consultation::factory()->for($doctor)->create(['procedures' => 'Se tomó radiografía periapical.', 'indications' => null]);

        $this->consultations()->applyTemplate($consultation, $this->consultations()->templateFor($treatment, $doctor->user));
        $this->consultations()->applyTemplate($consultation->fresh(), $this->consultations()->templateFor($treatment, $doctor->user));

        $procedures = $consultation->fresh()->procedures;
        $this->assertStringStartsWith('Se tomó radiografía periapical.', $procedures);
        $this->assertSame(1, substr_count($procedures, 'sindesmotomía'));
        $this->assertStringContainsString('No enjuagarse', $consultation->fresh()->indications);
    }

    public function test_tapping_a_surface_marks_it_and_tapping_again_removes_it(): void
    {
        $consultation = $this->consultations()->startWithoutAppointment(Patient::factory()->create(), $this->doctorOffering(Treatment::factory()->create())->user);
        $odontogram = $consultation->odontogram;
        $caries = $this->condition('caries');

        $entry = $this->odontograms()->toggle($odontogram, $caries, ToothState::Bad, 36, 'O', abbreviation: 'CD');
        $this->assertSame(['tooth' => 36, 'surface' => 'O', 'abbreviation' => 'CD'], $entry->only(['tooth', 'surface', 'abbreviation']));

        // Mismo hallazgo con otra sigla: se actualiza en vez de duplicarse.
        $this->odontograms()->toggle($odontogram, $caries, ToothState::Bad, 36, 'O', abbreviation: 'CE');
        $this->assertSame(1, $odontogram->entries()->count());

        $this->assertNull($this->odontograms()->toggle($odontogram, $caries, ToothState::Bad, 36, 'O', abbreviation: 'CE'));
        $this->assertSame(0, $odontogram->entries()->count());
    }

    public function test_the_odontogram_validates_teeth_surfaces_spans_and_colors(): void
    {
        $consultation = $this->consultations()->startWithoutAppointment(Patient::factory()->create(), $this->doctorOffering(Treatment::factory()->create())->user);
        $odontogram = $consultation->odontogram;

        // Una pieza ausente solo existe en azul, aunque se haya elegido rojo.
        $absent = $this->odontograms()->toggle($odontogram, $this->condition('ausente'), ToothState::Bad, 18, 'O');
        $this->assertSame(ToothState::Good, $absent->state);
        $this->assertNull($absent->surface);

        $bridge = $this->odontograms()->toggle($odontogram, $this->condition('protesis_fija'), ToothState::Good, 14, toTooth: 16);
        $this->assertSame(16, $bridge->to_tooth);

        foreach ([
            fn () => $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 55, 'O'),
            fn () => $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36),
            fn () => $this->odontograms()->toggle($odontogram, $this->condition('caries'), ToothState::Bad, 36, 'P'),
            fn () => $this->odontograms()->toggle($odontogram, $this->condition('protesis_fija'), ToothState::Bad, 14),
            fn () => $this->odontograms()->toggle($odontogram, $this->condition('protesis_fija'), ToothState::Bad, 14, toTooth: 44),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Se esperaba un error del odontograma.');
            } catch (ClinicalException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectExceptionObject(ClinicalException::teethOutsideDentition());
        $this->odontograms()->changeDentition($odontogram, Dentition::Primary);
    }

    private function condition(string $code): OdontogramCondition
    {
        return OdontogramCondition::where('code', $code)->firstOrFail();
    }

    private function consultations(): ConsultationService
    {
        return app(ConsultationService::class);
    }

    private function odontograms(): OdontogramService
    {
        return app(OdontogramService::class);
    }
}
