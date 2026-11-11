<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\Backups;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Earnings;
use App\Filament\Pages\OwingBalances;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Summary;
use App\Filament\Pages\WebSetup;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\RelationManagers\TreatmentsRelationManager;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Installments\InstallmentResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Filament\Resources\SmileCases\SmileCaseResource;
use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Resources\TreatmentPackages\TreatmentPackageResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WhatsappMessages\WhatsappMessageResource;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    /**
     * Qué puede abrir cada rol: [admin, recepción, doctor].
     *
     * @return array<string, array{0: string, 1: list<bool>}>
     */
    public static function pages(): array
    {
        return [
            'hoy' => ['dashboard', [true, true, true]],
            'resumen' => ['summary', [true, false, false]],
            'ingresos' => ['earnings', [true, false, false]],
            'agenda' => ['agenda', [true, true, true]],
            'citas' => ['appointments', [true, true, true]],
            'nueva cita' => ['appointments.create', [true, true, true]],
            'avisos' => ['messages', [true, true, true]],
            'solicitudes de cambio' => ['requests', [true, true, true]],
            'bloqueos' => ['blocks', [true, true, true]],
            'pacientes' => ['patients', [true, true, true]],
            'nuevo paciente' => ['patients.create', [true, true, true]],
            'ficha de paciente' => ['patients.view', [true, true, false]],
            'historia clínica' => ['patients.clinical', [true, false, false]],
            'atenciones' => ['consultations', [true, false, true]],
            'celulares' => ['accounts', [true, true, false]],
            'ficha de celular' => ['accounts.view', [true, true, false]],
            'pagos' => ['payments', [true, true, false]],
            'cuotas por cobrar' => ['installments', [true, true, false]],
            'saldos pendientes' => ['owing', [true, true, false]],
            'ingresos para imprimir' => ['earnings.print', [true, false, false]],
            'ingresos en Excel' => ['earnings.excel', [true, false, false]],
            'opciones rápidas' => ['snippets', [true, false, true]],
            'tratamientos' => ['treatments', [true, false, true]],
            'paquetes' => ['packages', [true, false, true]],
            'doctores' => ['doctors', [true, false, false]],
            'nuevo doctor' => ['doctors.create', [true, false, false]],
            'editar doctor' => ['doctors.edit', [true, false, false]],
            'usuarios' => ['users', [true, false, false]],
            'textos de los avisos' => ['templates', [true, false, false]],
            'textos de consentimiento' => ['consents', [true, false, true]],
            'campañas' => ['campaigns', [true, false, false]],
            'nueva campaña' => ['campaigns.create', [true, false, false]],
            'editar campaña' => ['campaigns.edit', [true, false, false]],
            'preguntas de la web' => ['faqs', [true, false, false]],
            'opiniones' => ['reviews', [true, false, false]],
            'antes y después' => ['cases', [true, false, false]],
            'ajustes' => ['settings', [true, false, false]],
            'puesta a punto de la web' => ['web', [true, false, false]],
            'copias de seguridad' => ['backups', [true, false, false]],
        ];
    }

    /** @param  list<bool>  $allowed */
    #[DataProvider('pages')]
    public function test_each_role_only_opens_what_it_needs(string $page, array $allowed): void
    {
        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();

        $treatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $otherDoctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()->create();
        Appointment::factory()->for($otherDoctor)->for($treatment)->for($patient)->create();
        $campaign = Campaign::query()->create([
            'name' => 'Limpieza de verano', 'slug' => 'limpieza-verano',
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        $url = match ($page) {
            'dashboard' => Dashboard::getUrl(),
            'summary' => Summary::getUrl(),
            'earnings' => Earnings::getUrl(),
            'earnings.print', 'earnings.excel' => Filament::getPanel('admin')->route('reports.'.$page),
            'agenda' => Agenda::getUrl(),
            'appointments' => AppointmentResource::getUrl('index'),
            'appointments.create' => AppointmentResource::getUrl('create'),
            'messages' => WhatsappMessageResource::getUrl('index'),
            'requests' => RescheduleRequestResource::getUrl('index'),
            'blocks' => ScheduleBlockResource::getUrl('index'),
            'patients' => PatientResource::getUrl('index'),
            'patients.create' => PatientResource::getUrl('create'),
            'patients.view' => PatientResource::getUrl('view', ['record' => $patient]),
            'patients.clinical' => PatientResource::getUrl('clinical', ['record' => $patient]),
            'consultations' => ConsultationResource::getUrl('index'),
            'accounts' => AccountResource::getUrl('index'),
            'accounts.view' => AccountResource::getUrl('view', ['record' => Account::sole()]),
            'payments' => PaymentResource::getUrl('index'),
            'installments' => InstallmentResource::getUrl('index'),
            'snippets' => TextSnippetResource::getUrl('index'),
            'treatments' => TreatmentResource::getUrl('index'),
            'packages' => TreatmentPackageResource::getUrl('index'),
            'doctors' => DoctorResource::getUrl('index'),
            'doctors.create' => DoctorResource::getUrl('create'),
            'doctors.edit' => DoctorResource::getUrl('edit', ['record' => $doctor]),
            'users' => UserResource::getUrl('index'),
            'templates' => MessageTemplateResource::getUrl('index'),
            'consents' => ConsentTemplateResource::getUrl('index'),
            'campaigns' => CampaignResource::getUrl('index'),
            'campaigns.create' => CampaignResource::getUrl('create'),
            'campaigns.edit' => CampaignResource::getUrl('edit', ['record' => $campaign]),
            'faqs' => FaqResource::getUrl('index'),
            'reviews' => ReviewResource::getUrl('index'),
            'cases' => SmileCaseResource::getUrl('index'),
            'settings' => Settings::getUrl(),
            'web' => WebSetup::getUrl(),
            'owing' => OwingBalances::getUrl(),
            'backups' => Backups::getUrl(),
        };

        $users = [
            'administrador' => User::factory()->admin(isDentist: true)->create(),
            'recepción' => User::factory()->reception()->create(),
            'doctor' => $doctor->user,
        ];

        foreach (array_values($users) as $i => $user) {
            $response = $this->actingAs($user)->get($url);

            $this->assertContains(
                $response->getStatusCode(),
                $allowed[$i] ? [200] : [403, 404],
                array_keys($users)[$i]." en {$page}: respuesta {$response->getStatusCode()}",
            );
        }
    }

    public function test_validation_messages_are_in_spanish(): void
    {
        $errors = Validator::make(['document' => str_repeat('1', 21)], ['name' => 'required', 'document' => 'max:20'], attributes: ['name' => 'Nombre', 'document' => 'Documento'])->errors();

        $this->assertSame(['Completa el campo Nombre.', 'Documento no puede tener más de 20 caracteres.'], $errors->all());
    }

    public function test_inactive_users_cannot_enter(): void
    {
        $user = User::factory()->reception()->create(['is_active' => false]);

        $this->actingAs($user)->get(Dashboard::getUrl())->assertForbidden();
    }

    public function test_the_panel_always_says_who_is_signed_in_and_with_which_role(): void
    {
        $this->actingAs(User::factory()->reception()->create(['name' => 'María Torres', 'email' => 'maria@consultorio.test']));

        $this->get(Filament::getProfileUrl())
            ->assertOk()
            ->assertSeeInOrder(['user-badge', 'María Torres', 'Recepción', 'Sesión iniciada como', 'María Torres', 'Recepción', 'maria@consultorio.test']);

        $this->actingAs(User::factory()->doctor()->create(['name' => 'Elena Ríos']));

        $this->get(Filament::getProfileUrl())->assertSeeInOrder(['Sesión iniciada como', 'Elena Ríos', 'Doctor']);
    }

    public function test_each_person_changes_their_own_password_from_my_profile(): void
    {
        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);

        $this->get(Filament::getProfileUrl())->assertOk()->assertSee('Mi perfil');

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'clave-nueva-2026',
                'passwordConfirmation' => 'clave-nueva-2026',
                'currentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('clave-nueva-2026', $reception->fresh()->password));
    }

    public function test_errors_explain_what_happened_in_spanish_and_offer_a_way_out(): void
    {
        $this->seed(CatalogSeeder::class);

        // El paciente con un enlace roto sigue en la web.
        $this->get('/esto-no-existe')
            ->assertNotFound()
            ->assertSee('No encontramos esta página')
            ->assertSee('Ir al inicio')
            ->assertSee('Reservar cita');

        // En el panel, sin el menú de la web y de vuelta al panel.
        $this->actingAs(User::factory()->reception()->create())
            ->get(Settings::getUrl())
            ->assertForbidden()
            ->assertSee('No tienes acceso a esta sección')
            ->assertSee('Volver al panel')
            ->assertDontSee('Reservar cita');
    }

    public function test_pages_tell_the_browser_how_to_protect_them(): void
    {
        $this->get('/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/panel/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_a_doctor_profile_page_lists_its_treatments(): void
    {
        $treatment = Treatment::factory()->create(['name' => 'Endodoncia molar']);
        $doctor = Doctor::factory()->withSchedule()->create();
        $doctor->treatments()->attach($treatment, ['duration_minutes' => 90]);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(TreatmentsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => EditDoctor::class])
            ->assertCanSeeTableRecords([$treatment])
            ->assertSee('90 min');
    }
}
