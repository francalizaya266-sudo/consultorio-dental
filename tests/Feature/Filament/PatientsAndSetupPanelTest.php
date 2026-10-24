<?php

namespace Tests\Feature\Filament;

use App\Enums\PatientRelationship;
use App\Enums\PaymentMethod;
use App\Enums\SnippetStatus;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\ScheduleBlock;
use App\Models\Setting;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PatientsAndSetupPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_a_new_patient_with_a_known_phone_joins_that_family(): void
    {
        $mother = Patient::factory()->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreatePatient::class)
            ->fillForm([
                'first_name' => 'Mateo',
                'last_name' => 'Quispe Rojas',
                'phone' => substr($mother->account->phone, 3),
                'relationship' => PatientRelationship::Child->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $child = Patient::query()->where('first_name', 'Mateo')->sole();
        $this->assertTrue($child->account->is($mother->account));
        $this->assertSame(PatientRelationship::Child, $child->relationship);
    }

    public function test_an_invalid_phone_is_rejected(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Ana', 'last_name' => 'Ríos', 'phone' => '12345'])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    public function test_reception_blocks_and_unblocks_web_bookings_for_a_phone(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ViewAccount::class, ['record' => $account->getKey()])
            ->callAction('block', ['reason' => 'Reservó y no vino tres veces']);

        $this->assertTrue($account->fresh()->isBlocked());

        Livewire::test(ViewAccount::class, ['record' => $account->getKey()])
            ->callAction('unblock');

        $this->assertFalse($account->fresh()->isBlocked());
    }

    public function test_payments_are_voided_not_deleted_and_leave_the_total(): void
    {
        $patient = Patient::factory()->create();
        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);

        Livewire::test(ManagePayments::class)
            ->callAction('create', [
                'patient_id' => $patient->id,
                'amount' => '150',
                'method' => PaymentMethod::Yape->value,
                'reference' => '0045123',
                'paid_at' => now()->format('Y-m-d H:i'),
            ])
            ->assertHasNoFormErrors();

        $payment = Payment::sole();
        $this->assertTrue($payment->receivedBy->is($reception));

        Livewire::test(ManagePayments::class)
            ->callAction(TestAction::make('void')->table($payment), ['reason' => 'Se registró dos veces']);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_doctor_can_only_block_their_own_agenda(): void
    {
        $doctor = Doctor::factory()->create();
        $other = Doctor::factory()->create();
        $this->actingAs($doctor->user);

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', [
                'doctor_id' => $other->id,
                'type' => 'course',
                'starts_at' => '2026-11-10 09:00',
                'ends_at' => '2026-11-10 13:00',
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue(ScheduleBlock::sole()->doctor->is($doctor));
    }

    public function test_a_block_over_existing_appointments_warns_to_move_them(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        Appointment::factory()->for($doctor)->for($treatment)->at('2026-11-10 10:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManageScheduleBlocks::class)
            ->callAction('create', [
                'doctor_id' => $doctor->id,
                'type' => 'vacation',
                'starts_at' => '2026-11-10 08:00',
                'ends_at' => '2026-11-10 19:00',
            ])
            ->assertNotified('Hay 1 cita en ese horario');
    }

    public function test_doctors_keep_their_own_options_and_admins_share_them(): void
    {
        $doctor = Doctor::factory()->create()->user;
        $admin = User::factory()->admin(isDentist: true)->create();

        $this->actingAs($doctor);
        Livewire::test(ManageTextSnippets::class)
            ->callAction('create', ['label' => 'Mi limpieza', 'body' => 'Profilaxis con ultrasonido.', 'field' => 'procedures', 'shortcut' => 'milimp'])
            ->assertHasNoFormErrors();

        $this->actingAs($admin);
        Livewire::test(ManageTextSnippets::class)
            ->callAction('create', ['label' => 'Postoperatorio', 'body' => 'No enjuagar por 24 horas.', 'field' => 'indications', 'shared' => true])
            ->assertHasNoFormErrors();

        $mine = TextSnippet::query()->where('label', 'Mi limpieza')->sole();
        $shared = TextSnippet::query()->where('label', 'Postoperatorio')->sole();
        $this->assertTrue($mine->user->is($doctor));
        $this->assertTrue($shared->isShared());

        $this->actingAs($doctor);
        Livewire::test(ManageTextSnippets::class)
            ->assertActionHidden(TestAction::make('edit')->table($shared))
            ->callAction(TestAction::make('propose')->table($mine));

        $this->actingAs($admin);
        Livewire::test(ManageTextSnippets::class)
            ->callAction(TestAction::make('approve')->table($mine->fresh()));

        $this->assertTrue($mine->fresh()->isShared());
        $this->assertSame(SnippetStatus::Active, $mine->fresh()->status);
    }

    public function test_the_admin_changes_booking_limits_from_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Settings::class)
            // Los datos del recetario de la clínica ya vienen puestos.
            ->assertSchemaStateSet(fn (array $state) => $this->assertSame(
                ['Clínica Vega Implant', '+51918874568', 'Jr. Apurímac 648', 'Juliaca', 'Tu sonrisa, nuestra pasión'],
                [$state['clinic']['name'], $state['clinic']['whatsapp'], $state['clinic']['address'], $state['clinic']['district'], $state['clinic']['slogan']],
            ) ?? [])
            ->fillForm(['booking' => ['max_web_appointments_per_patient' => 3], 'clinic' => ['whatsapp' => '912 345 678', 'slogan' => 'Sonríe sin miedo']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, Setting::get('booking.max_web_appointments_per_patient'));
        $this->assertSame(['+51912345678', 'Sonríe sin miedo'], [Setting::get('clinic.whatsapp'), Setting::get('clinic.slogan')]);
    }

    public function test_the_doctor_schedule_is_edited_on_the_doctor_page(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->set('data.schedules', [
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 1, 'starts_at' => '15:00', 'ends_at' => '19:00'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $doctor->schedules()->where('weekday', 1)->count());
    }

    public function test_patients_away_for_six_months_can_be_listed_to_call_them_back(): void
    {
        $this->freezeMonday();
        $away = Patient::factory()->create(['first_visit_at' => '2025-01-10', 'last_visit_at' => '2026-04-20']);
        $alreadyBooked = Patient::factory()->create(['first_visit_at' => '2025-01-10', 'last_visit_at' => '2026-03-02']);
        $recent = Patient::factory()->create(['first_visit_at' => '2025-01-10', 'last_visit_at' => '2026-09-15']);
        $neverCame = Patient::factory()->create(['first_visit_at' => null, 'last_visit_at' => null]);
        Appointment::factory()->for($alreadyBooked)->for(Treatment::factory())->confirmed()->at('2026-11-05 10:00')->create();
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ListPatients::class)
            ->assertSee('Sin venir hace 6 meses')
            ->set('activeTab', 'checkup')
            ->assertCanSeeTableRecords([$away])
            ->assertCanNotSeeTableRecords([$alreadyBooked, $recent, $neverCame]);
    }

    public function test_the_patient_file_shows_one_timeline_and_the_medical_alert_only_to_who_attends(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create(['first_name' => 'María', 'last_name' => 'Quispe', 'email' => null, 'admin_notes' => null]);
        $patient->medicalBackground()->create(['allergies' => 'Penicilina']);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-10-20 10:00')->create();
        Payment::factory()->for($patient)->create(['amount' => 80, 'method' => PaymentMethod::Yape, 'paid_at' => '2026-10-20 11:00']);

        $this->actingAs(User::factory()->reception()->create());

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSee(['María Quispe', 'Línea de tiempo', 'Limpieza dental · Pendiente', 'Pagó S/ 80.00 · Yape'])
            ->assertDontSee(['Ver María Quispe', 'Penicilina', 'Correo', 'Notas internas']);

        $this->actingAs($doctor->user);

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSeeInOrder(['Atención: Alergia: Penicilina', 'Datos del paciente', 'Línea de tiempo'])
            ->assertDontSee('Pagó S/ 80.00');
    }
}
