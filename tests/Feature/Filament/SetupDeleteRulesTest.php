<?php

namespace Tests\Feature\Filament;

use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Enums\TreatmentPlanStatus;
use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Consent;
use App\Models\ConsentTemplate;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\Constraints\SeeInOrder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La misma regla en toda la Configuración: lo que se registró por error y nunca se usó se puede borrar; lo que
 * ya tiene historia se conserva y se apaga (o se archiva), y el panel dice cómo.
 */
class SetupDeleteRulesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_package_nobody_used_is_deleted_and_one_already_in_a_plan_is_switched_off_instead(): void
    {
        $unused = TreatmentPackage::factory()->create(['name' => 'Paquete de prueba']);
        $used = TreatmentPackage::factory()->create(['name' => 'Ortodoncia completa']);
        $plan = TreatmentPlan::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => Doctor::factory()->create()->id, 'title' => 'Plan', 'status' => TreatmentPlanStatus::Draft]);
        $plan->items()->create(['treatment_id' => Treatment::factory()->create()->id, 'treatment_package_id' => $used->id, 'description' => 'Brackets', 'unit_price' => 2500]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction(TestAction::make('delete')->table($unused))
            ->assertMountedActionModalSee('Solo se borra un paquete que nunca se usó en un plan. Los demás se apagan con "Se ofrece".')
            ->callMountedAction()
            ->assertNotified('Paquete borrado')
            ->callAction(TestAction::make('delete')->table($used))
            ->assertNotified('Este paquete ya está en un plan o en una campaña. Para que no se ofrezca más, apaga "Se ofrece": así los planes que lo usan no cambian.');

        $this->assertSame([$used->id], TreatmentPackage::query()->pluck('id')->all());
    }

    public function test_only_the_administrator_deletes_packages(): void
    {
        $package = TreatmentPackage::factory()->create();
        $this->actingAs(Doctor::factory()->create()->user);

        Livewire::test(ManageTreatmentPackages::class)
            ->assertActionVisible(TestAction::make('edit')->table($package))
            ->assertActionHidden(TestAction::make('delete')->table($package));
    }

    public function test_the_package_form_names_its_fields_like_the_rest_of_the_catalogue(): void
    {
        $package = TreatmentPackage::factory()->create(['name' => 'Ortodoncia completa']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTreatmentPackages::class)
            ->mountAction(TestAction::make('edit')->table($package))
            ->assertMountedActionModalDontSee('Editar Ortodoncia completa')
            ->tap(fn (Testable $page) => $this->assertWindowShowsInOrder($page, ['Editar paquete', 'Nombre', 'Se ofrece', 'Qué incluye', 'Precio normal', 'Precio del paquete', 'Duración del tratamiento', '2x1', 'Cómo se puede pagar']));
    }

    public function test_a_consent_text_nobody_signed_is_deleted_and_a_signed_one_is_kept(): void
    {
        $draft = ConsentTemplate::create(['name' => 'Borrador', 'body' => 'Texto de prueba.']);
        $signed = ConsentTemplate::create(['name' => 'Consentimiento general', 'body' => 'Yo, {firmante}, autorizo.']);
        $patient = Patient::factory()->create();
        Consent::create(['patient_id' => $patient->id, 'consent_template_id' => $signed->id, 'title' => $signed->name, 'body_snapshot' => 'Firmado.', 'signer_name' => $patient->full_name, 'signed_at' => now()]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageConsentTemplates::class)
            ->mountAction(TestAction::make('delete')->table($draft))
            ->assertMountedActionModalSee('Solo se borra un texto con el que nunca se firmó. Los demás se apagan con "En uso".')
            ->callMountedAction()
            ->assertNotified('Texto de consentimiento borrado')
            ->callAction(TestAction::make('delete')->table($signed))
            ->assertNotified('Con este texto ya se firmaron consentimientos. Para que no se proponga más, apaga "En uso": lo firmado no cambia.');

        $this->assertSame([$signed->id], ConsentTemplate::query()->pluck('id')->all());
    }

    public function test_a_new_consent_text_is_written_from_the_button_at_the_top_and_doctors_only_read(): void
    {
        $template = ConsentTemplate::create(['name' => 'Consentimiento general', 'body' => 'Yo, {firmante}, autorizo.']);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageConsentTemplates::class)
            ->assertActionHasLabel('create', 'Nuevo texto')
            ->mountAction('create')
            // "En uso" va arriba, junto al nombre: antes quedaba al final, debajo del texto largo.
            ->tap(fn (Testable $page) => $this->assertWindowShowsInOrder($page, ['Nombre', 'En uso', 'Tratamiento', 'Texto']))
            ->setActionData(['name' => 'Extracción', 'body' => 'Autorizo la extracción.'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Texto de consentimiento guardado');

        $this->assertTrue(ConsentTemplate::query()->where('name', 'Extracción')->sole()->is_active);

        $this->actingAs(Doctor::factory()->create()->user);

        Livewire::test(ManageConsentTemplates::class)
            ->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('delete')->table($template));
    }

    public function test_someone_who_never_signed_in_is_deleted_and_whoever_already_worked_keeps_their_name(): void
    {
        $admin = User::factory()->admin()->create();
        $mistake = User::factory()->reception()->create(['name' => 'Recepción duplicada']);
        $worked = User::factory()->reception()->create(['last_login_at' => now()->subDay()]);
        $doctor = Doctor::factory()->create()->user;
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('delete')->table($mistake))
            ->assertMountedActionModalSee('Solo se borra a quien nunca entró al panel. A los demás se les apaga "Puede entrar al panel".')
            ->callMountedAction()
            ->assertNotified('Usuario borrado')
            ->callAction(TestAction::make('delete')->table($worked))
            ->assertNotified('Esta persona ya entró al panel o tiene ficha de doctor. Para que no entre más, apaga "Puede entrar al panel": así se conserva lo que hizo.')
            // Quien tiene ficha de doctor tampoco, aunque nunca haya entrado.
            ->callAction(TestAction::make('delete')->table($doctor))
            ->assertNotified('Esta persona ya entró al panel o tiene ficha de doctor. Para que no entre más, apaga "Puede entrar al panel": así se conserva lo que hizo.')
            // Y nadie se borra a sí mismo.
            ->assertActionHidden(TestAction::make('delete')->table($admin));

        $this->assertEqualsCanonicalizing([$admin->id, $worked->id, $doctor->id], User::query()->pluck('id')->all());
    }

    public function test_the_users_page_asks_only_what_the_panel_uses_and_sends_doctors_to_their_own_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageUsers::class)
            // En la lista y en la ficha, el mismo nombre para lo mismo.
            ->assertSee('Puede entrar al panel')
            ->assertActionHasLabel('newDoctor', 'Nuevo doctor (en Doctores)')
            ->mountAction('create')
            ->assertMountedActionModalSee('Para administración o recepción. Un doctor se crea en Doctores, junto con su acceso.')
            // El celular no se usaba para nada: ya no se pide.
            ->assertMountedActionModalDontSee('Celular');
    }

    public function test_a_quick_option_is_archived_instead_of_deleted_once_it_was_used(): void
    {
        $admin = User::factory()->admin()->create();
        $used = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'Profilaxis completa', 'body' => 'Se realiza profilaxis.']);
        $used->recordUse($admin);
        $unused = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'De prueba', 'body' => '...']);
        $this->actingAs($admin);

        Livewire::test(ManageTextSnippets::class)
            ->mountAction(TestAction::make('delete')->table($unused))
            ->assertMountedActionModalSee('Solo se borra una opción que nunca se usó. Las que ya se usaron se archivan.')
            ->callMountedAction()
            ->assertNotified('Opción rápida borrada')
            ->callAction(TestAction::make('delete')->table($used))
            ->assertNotified('Esta opción ya se usó en historias. Para que deje de ofrecerse, archívala.')
            ->assertActionHidden(TestAction::make('unarchive')->table($used))
            ->mountAction(TestAction::make('archive')->table($used))
            ->assertMountedActionModalSee('Deja de ofrecerse al escribir la historia. Queda en esta lista y se puede volver a usar.')
            ->callMountedAction()
            ->assertNotified('"Profilaxis completa" archivada')
            // Archivada, ya no estorba en la lista: se ve solo si se pide.
            ->assertCanNotSeeTableRecords([$used]);

        $this->assertSame([$used->id], TextSnippet::query()->pluck('id')->all());
        $this->assertSame(SnippetStatus::Archived, $used->refresh()->status);
        $this->assertFalse(TextSnippet::query()->availableFor($admin, SnippetField::Procedures)->exists());

        Livewire::test(ManageTextSnippets::class)
            ->assertCanNotSeeTableRecords([$used])
            ->filterTable('archived')
            ->assertCanSeeTableRecords([$used])
            ->assertActionHidden(TestAction::make('archive')->table($used))
            ->callAction(TestAction::make('unarchive')->table($used))
            ->assertNotified('"Profilaxis completa" se ofrece de nuevo');

        $this->assertSame(SnippetStatus::Active, $used->refresh()->status);
        $this->assertTrue(TextSnippet::query()->availableFor($admin, SnippetField::Procedures)->exists());
    }

    public function test_a_doctor_archives_their_own_quick_options_but_not_the_ones_of_the_team(): void
    {
        $doctor = Doctor::factory()->create()->user;
        $own = TextSnippet::create(['user_id' => $doctor->id, 'field' => SnippetField::Procedures, 'label' => 'La mía', 'body' => '...']);
        $proposed = TextSnippet::create(['user_id' => $doctor->id, 'field' => SnippetField::Procedures, 'label' => 'Propuesta', 'body' => '...', 'status' => SnippetStatus::Proposed]);
        $shared = TextSnippet::create(['field' => SnippetField::Procedures, 'label' => 'Del equipo', 'body' => '...']);

        $this->assertTrue($doctor->can('archive', $own));
        // Mientras espera como propuesta no se archiva: primero la responde el administrador.
        $this->assertFalse($doctor->can('archive', $proposed));
        $this->assertFalse($doctor->can('archive', $shared));
        $this->assertFalse($doctor->can('delete', $shared));
        $this->assertTrue(User::factory()->admin()->create()->can('archive', $shared));

        $this->actingAs($doctor);

        Livewire::test(ManageTextSnippets::class)
            ->callAction(TestAction::make('archive')->table($own))
            ->assertNotified('"La mía" archivada');

        $this->assertSame(SnippetStatus::Archived, $own->refresh()->status);
    }

    public function test_deleting_a_doctor_says_the_rule_and_what_happened(): void
    {
        $doctor = Doctor::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee('Solo se borra a un doctor que nunca tuvo citas ni historias. A los demás se les apaga "Activo". Se puede restaurar.')
            ->callMountedAction()
            ->assertNotified('Doctor borrado');

        $this->assertSoftDeleted($doctor);
    }

    /** @param  list<string>  $texts  Lo que debe leerse, de arriba abajo, en la ventana abierta. */
    private function assertWindowShowsInOrder(Testable $page, array $texts): void
    {
        $this->assertThat(array_map(e(...), $texts), new SeeInOrder($page->getMountedActionModalHtml()));
    }
}
