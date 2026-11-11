<?php

namespace Tests\Feature\Filament;

use App\Enums\DocumentType;
use App\Enums\PatientRelationship;
use App\Filament\Pages\Agenda;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Models\Account;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ConsentService;
use App\Services\Patients\PatientRegistrar;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    public function test_patients_that_may_already_be_registered_are_found_by_document_name_and_phone(): void
    {
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $rosa = Patient::factory()->for($account)->create(['first_name' => 'Rosa María', 'last_name' => 'Huamán Torres', 'birth_date' => '1956-01-20', 'document_number' => '07654321']);
        $mateo = Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo']);
        Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Huamán Quispe', 'birth_date' => '1990-05-05']);

        $registrar = app(PatientRegistrar::class);
        $reasons = fn (array $data) => $registrar->possibleDuplicates($data)->mapWithKeys(fn (array $row) => [$row['patient']->id => $row['reason']])->all();

        $this->assertSame([$rosa->id => 'mismo documento'], $reasons(['document_number' => ' 0765-4321 ']));
        $this->assertSame([$rosa->id => 'mismo nombre y fecha de nacimiento'], $reasons(['first_name' => 'Rosa', 'last_name' => 'Huamán', 'birth_date' => '1956-01-20']));
        $this->assertSame([$rosa->id => 'mismo nombre'], $reasons(['first_name' => 'rosa maría', 'last_name' => 'Huamán Torres']));
        $this->assertSame([$mateo->id => 'mismo celular', $rosa->id => 'mismo celular'], $reasons(['phone' => '987 654 321']));
        $this->assertSame([], $reasons(['first_name' => 'Rosa', 'last_name' => 'Huamán']));
        $this->assertSame([], $registrar->possibleDuplicates(['document_number' => '07654321'], ignore: $rosa)->all());
    }

    public function test_registering_someone_already_registered_opens_their_file_instead(): void
    {
        $rosa = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Huamán', 'document_number' => '07654321']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Rosa', 'last_name' => 'Huamán Torres', 'document_number' => '07654321'])
            ->assertSee(['Ya hay un paciente con este documento: ¿es él?', 'Es Rosa Huamán', 'DNI 07654321', 'Mismo documento', 'No, es otra persona'])
            ->set('data.same_as', $rosa->id)
            ->assertRedirect(PatientResource::getUrl('view', ['record' => $rosa]));

        // Sin elegir, no se registra.
        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Rosa', 'last_name' => 'Huamán'])
            ->call('create')
            ->assertHasFormErrors(['same_as' => 'required']);

        $this->assertSame(1, Patient::query()->count());
    }

    public function test_documents_are_checked_by_type_and_saved_clean(): void
    {
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Ana', 'last_name' => 'Ríos', 'document_type' => DocumentType::Dni->value, 'document_number' => '1234567'])
            ->call('create')
            ->assertHasFormErrors(['document_number']);

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Ana', 'last_name' => 'Ríos', 'document_type' => DocumentType::ForeignerCard->value, 'document_number' => '001234567'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Luis', 'last_name' => 'Paz', 'document_type' => DocumentType::Dni->value, 'document_number' => '4512 3456'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('45123456', Patient::query()->where('first_name', 'Luis')->sole()->document_number);
    }

    public function test_a_phone_has_one_holder_and_a_wrong_relationship_can_be_corrected(): void
    {
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $mother = Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe']);
        // Mateo quedó como titular del celular de su mamá.
        $mateo = Patient::factory()->for($account)->create(['first_name' => 'Mateo', 'relationship' => PatientRelationship::Self, 'birth_date' => now()->subYears(7)]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Lucía', 'last_name' => 'Rojas', 'phone' => '987654321', 'relationship' => PatientRelationship::Self->value, 'same_as' => 'new'])
            ->call('create')
            ->assertHasFormErrors(['relationship']);

        Livewire::test(EditPatient::class, ['record' => $mateo->getRouteKey()])
            ->assertSchemaStateSet(['relationship' => PatientRelationship::Self])
            ->call('save')
            ->assertHasFormErrors(['relationship'])
            ->fillForm(['relationship' => PatientRelationship::Child->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PatientRelationship::Child, $mateo->fresh()->relationship);
        $this->assertSame(PatientRelationship::Self, $mother->fresh()->relationship);
    }

    public function test_booking_for_a_new_patient_who_already_exists_uses_the_existing_one(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['duration_minutes' => 30]);
        $doctor = $this->doctorOffering($treatment);
        $ana = Patient::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '41234567']);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(Agenda::class)
            ->mountAction('createAppointment', ['doctor' => $doctor->id, 'date' => '2026-11-03', 'time' => '16:00'])
            ->setActionData(['new_patient' => true])
            ->setActionData(['patient' => ['first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '41234567'], 'treatment_id' => $treatment->id])
            ->setActionData(['patient' => ['same_as' => (string) $ana->id]])
            ->assertActionDataSet(['new_patient' => false, 'patient_id' => $ana->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame('2026-11-03 16:00', $ana->appointments()->sole()->starts_at->format('Y-m-d H:i'));
    }

    public function test_the_birth_date_is_read_back_in_words_with_the_age(): void
    {
        $this->freezeMonday();
        $this->actingAs(User::factory()->reception()->create());

        // El navegador puede mostrarla como día/mes o mes/día: en palabras se ve si quedó al revés.
        Livewire::test(CreatePatient::class)
            ->assertDontSee('años')
            ->fillForm(['birth_date' => '1985-04-12'])
            ->assertSee('12 de abril de 1985 · 41 años');
    }

    #[DataProvider('birthDatesInWords')]
    public function test_the_birth_date_in_words_covers_babies_and_ignores_what_is_not_a_past_date(mixed $typed, ?string $expected): void
    {
        $this->freezeMonday();

        $this->assertSame($expected, PatientForm::birthDateInWords($typed));
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function birthDatesInWords(): array
    {
        return [
            'adult' => ['1985-04-12', '12 de abril de 1985 · 41 años'],
            'one year old' => ['2025-06-01', '1 de junio de 2025 · 1 año'],
            'baby' => ['2026-03-01', '1 de marzo de 2026 · menos de un año'],
            'empty' => [null, null],
            'in the future' => ['2027-01-01', null],
            'not a date' => ['abc', null],
        ];
    }

    public function test_a_minor_has_a_guardian_who_signs_for_them(): void
    {
        $this->freezeMonday();
        $this->actingAs(User::factory()->reception()->create());

        // Al poner la fecha de nacimiento de un menor, se pide su apoderado.
        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Mateo', 'last_name' => 'Rojas', 'birth_date' => '2019-05-10'])
            ->assertSee('Es menor de edad: su apoderado firma los consentimientos y el plan de tratamiento.')
            ->fillForm(['guardian_name' => 'Carla Rojas Díaz', 'guardian_document' => '4123 4567', 'guardian_relationship' => PatientRelationship::Parent->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $mateo = Patient::query()->where('first_name', 'Mateo')->sole();
        $this->assertSame(['signer_name' => 'Carla Rojas Díaz', 'signer_document' => '41234567', 'signer_relationship' => PatientRelationship::Parent], app(ConsentService::class)->defaultSigner($mateo));

        $this->get(PatientResource::getUrl('view', ['record' => $mateo]))
            ->assertOk()
            ->assertSee(['Apoderado', 'Carla Rojas Díaz · Padre / madre · 41234567'])
            ->assertDontSee('Falta: apoderado');

        // Sin apoderado ni titular que firme, se pide.
        $lucia = Patient::factory()->create(['first_name' => 'Lucía', 'birth_date' => '2015-01-01']);
        $this->get(PatientResource::getUrl('view', ['record' => $lucia]))
            ->assertOk()
            ->assertSee(['Falta: apoderado', 'Falta: quién firma por él']);

        Livewire::test(CreatePatient::class)
            ->fillForm(['first_name' => 'Ana', 'last_name' => 'Paz', 'birth_date' => '1990-05-10'])
            ->assertDontSee('Es menor de edad');
    }

    public function test_the_family_card_links_each_relative_and_sends_the_my_account_link(): void
    {
        $account = Account::factory()->create(['phone' => '+51987654321']);
        $mother = Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe']);
        $mateo = Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo', 'last_name' => 'Quispe']);
        $this->actingAs(User::factory()->reception()->create());

        $this->get(PatientResource::getUrl('view', ['record' => $mother]))
            ->assertOk()
            ->assertSee(['Familia con este celular', 'Mateo Quispe</a> · Hijo(a)', PatientResource::getUrl('view', ['record' => $mateo])], escape: false)
            ->assertSee('Enviar enlace de Mi cuenta');

        Livewire::test(ViewPatient::class, ['record' => $mother->getRouteKey()])
            ->mountAction(TestAction::make('sendAccessLink')->schemaComponent('family', 'infolist'))
            ->assertSchemaStateSet(fn (array $state) => $this->assertStringContainsString('este es tu enlace para entrar a Mi cuenta', $state['body']) ?? []);
    }
}
