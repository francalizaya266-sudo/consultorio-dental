<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\MessageTemplateKey;
use App\Enums\PatientRelationship;
use App\Enums\PaymentMethod;
use App\Enums\SnippetStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Pages\WebSetup;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\ChargesRelationManager;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Support\MobileBar;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\ScheduleBlock;
use App\Models\Setting;
use App\Models\SmileCase;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Web\PublicImages;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

        // El celular ya tiene a su mamá: se pregunta si es ella antes de registrarlo.
        Livewire::test(CreatePatient::class)
            ->fillForm([
                'first_name' => 'Mateo',
                'last_name' => 'Quispe Rojas',
                'phone' => substr($mother->account->phone, 3),
                'relationship' => PatientRelationship::Child->value,
            ])
            ->assertSee('Este celular ya tiene a '.$mother->first_name.': ¿es uno de ellos?')
            ->fillForm(['same_as' => 'new'])
            ->call('create')
            ->assertHasNoFormErrors();

        $child = Patient::query()->where('first_name', 'Mateo')->where('last_name', 'Quispe Rojas')->sole();
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
            ->callAction('registerPayment', [
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

    public function test_only_what_was_registered_by_mistake_can_be_deleted_so_the_agenda_keeps_working(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $unusedTreatment = Treatment::factory()->create();
        $doctor = $this->doctorOffering($treatment);
        $unusedDoctor = Doctor::factory()->create();
        $patient = Patient::factory()->create();
        $mistake = Patient::factory()->create();
        Appointment::factory()->for($patient)->for($doctor)->for($treatment)->at('2026-11-02 10:00')->confirmed()->create();
        $this->actingAs(User::factory()->admin(isDentist: true)->create());

        Livewire::test(ManageTreatments::class)
            ->callAction(TestAction::make('delete')->table($treatment))
            ->assertNotified('Este tratamiento ya tiene citas o cobros. Para que no se ofrezca más, apaga "Activo": así se conserva su historial.')
            ->callAction(TestAction::make('delete')->table($unusedTreatment));

        Livewire::test(EditDoctor::class, ['record' => $doctor->getKey()])->callAction('delete');
        Livewire::test(EditDoctor::class, ['record' => $unusedDoctor->getKey()])->callAction('delete');
        Livewire::test(EditPatient::class, ['record' => $patient->getKey()])->callAction('delete');
        Livewire::test(EditPatient::class, ['record' => $mistake->getKey()])->callAction('delete');

        $this->assertNotSoftDeleted($treatment);
        $this->assertNotSoftDeleted($doctor);
        $this->assertNotSoftDeleted($patient);
        $this->assertSoftDeleted($unusedTreatment);
        $this->assertSoftDeleted($unusedDoctor);
        $this->assertSoftDeleted($mistake);

        // Lo que tiene la cita sigue en pie, así que Hoy y la agenda abren.
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Limpieza dental');
        $this->get(Agenda::getUrl())->assertOk();

        // Recepción ni siquiera ve el botón.
        $this->actingAs(User::factory()->reception()->create());
        Livewire::test(EditPatient::class, ['record' => $patient->getKey()])->assertActionHidden('delete');
    }

    public function test_payments_open_on_the_last_week_and_older_ones_are_one_click_away(): void
    {
        $thisWeek = Payment::factory()->for(Patient::factory())->create(['paid_at' => now()->subDays(3)]);
        // El primer día de la semana también entra, aunque el calendario guarde la fecha con hora.
        $firstDay = Payment::factory()->for(Patient::factory())->create(['paid_at' => today()->subDays(6)->setTime(9, 30)]);
        $payment = Payment::factory()->for(Patient::factory())->create(['paid_at' => now()->subDays(20)]);
        $this->actingAs(User::factory()->reception()->create());

        Livewire::test(ManagePayments::class)
            ->assertCanSeeTableRecords([$thisWeek, $firstDay])
            ->assertCanNotSeeTableRecords([$payment])
            ->assertSee('Últimos 7 días');

        Payment::query()->whereKey([$thisWeek->getKey(), $firstDay->getKey()])->delete();

        Livewire::test(ManagePayments::class)
            ->assertCanNotSeeTableRecords([$payment])
            ->assertSee('No hay pagos en estas fechas')
            ->callAction(TestAction::make('allDates')->table())
            ->assertCanSeeTableRecords([$payment]);
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

        // Solo se marca lo que no es lo de siempre: la del equipo va sin etiqueta, la propia dice "Solo tuya".
        $this->actingAs($doctor);
        Livewire::test(ManageTextSnippets::class)
            ->assertSee(['Mi limpieza', 'Solo tuya', 'Postoperatorio'])
            ->assertDontSee(['Equipo', 'Activa'])
            ->assertActionHidden(TestAction::make('edit')->table($shared))
            ->callAction(TestAction::make('propose')->table($mine));

        $this->actingAs($admin);
        Livewire::test(ManageTextSnippets::class)
            ->assertSee('Propuesta de '.$doctor->name)
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

    public function test_the_admin_uploads_and_removes_the_logo_in_the_web_setup(): void
    {
        Storage::fake(PublicImages::DISK);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(WebSetup::class)
            ->fillForm(['web' => ['logo' => UploadedFile::fake()->image('logo.png', 600, 200)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $logo = Setting::get('web.logo');
        $this->assertStringStartsWith('marca/', $logo);
        $this->assertStringEndsWith('.png', $logo, 'El logo no se pasa a JPG: conserva el fondo transparente.');
        Storage::disk(PublicImages::DISK)->assertExists($logo);

        $this->get(route('site.home'))->assertSee('class="logo"', false);

        Livewire::test(WebSetup::class)
            ->fillForm(['web' => ['logo' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(Setting::get('web.logo'));
        $this->get(route('site.home'))->assertDontSee('class="logo"', false)->assertSee('class="mark"', false);
    }

    public function test_the_admin_sets_the_home_photo_and_the_google_stars_in_the_web_setup(): void
    {
        Storage::fake(PublicImages::DISK);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(WebSetup::class)
            ->fillForm(['clinic' => ['google_rating' => '6']])
            ->call('save')
            ->assertHasFormErrors(['clinic.google_rating']);

        Livewire::test(WebSetup::class)
            ->fillForm([
                'web' => ['hero_image' => UploadedFile::fake()->image('consultorio.jpg', 2400, 1200)],
                'clinic' => ['google_rating' => '4,9', 'google_reviews_count' => '87'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([4.9, 87], [Setting::get('clinic.google_rating'), Setting::get('clinic.google_reviews_count')]);
        $this->assertStringStartsWith('inicio/', Setting::get('web.hero_image'));
        Storage::disk(PublicImages::DISK)->assertExists(Setting::get('web.hero_image'));

        // Sin puntaje, no quedan estrellas a medias en la web.
        Livewire::test(WebSetup::class)
            ->fillForm(['clinic' => ['google_rating' => '', 'google_reviews_count' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([null, null], [Setting::get('clinic.google_rating'), Setting::get('clinic.google_reviews_count')]);
    }

    public function test_the_web_setup_lists_what_is_missing_with_its_link_and_goes_away_when_all_is_done(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $ana = Doctor::factory()->create(['name' => 'Dra. Ana Torres']);
        Doctor::factory()->create(['name' => 'Dr. Luis Ramos', 'photo_path' => 'doctores/luis.jpg']);
        // Quien no sale en la web no necesita foto.
        Doctor::factory()->create(['name' => 'Dr. Pablo Ccori', 'is_public' => false]);
        Setting::set('clinic.maps_url', 'https://maps.app.goo.gl/consultorio');
        // Se reserva por la web, pero quien lo atiende no recibe reservas por la web: no aparece en la reserva.
        $whitening = Treatment::factory()->create(['name' => 'Blanqueamiento', 'online_bookable' => true]);
        $whitening->doctors()->attach(Doctor::factory()->create(['accepts_online_booking' => false, 'is_public' => false]));

        $this->assertSame('5', WebSetup::getNavigationBadge());

        Livewire::test(WebSetup::class)
            ->assertSeeInOrder([
                'Lo que falta para que la web dé confianza', '1 de 6 listos',
                'La foto de cada doctor', 'Falta la de Dra. Ana Torres.', 'Subir su foto',
                'Una foto del consultorio en el inicio', 'Subir la foto',
                'Quién atiende cada tratamiento de la reserva', 'Blanqueamiento no aparece en la reserva', 'Ver tratamientos',
                'El enlace a sus opiniones en Google', 'Pegar el enlace',
                'Un caso de antes y después', 'Agregar un caso',
                // Lo listo va al final, sin enlace.
                'La ubicación en Google Maps',
            ])
            ->assertSee(DoctorResource::getUrl('edit', ['record' => $ana]), false)
            ->assertSee(WebSetup::getUrl().'#opiniones', false)
            ->assertDontSee('Dr. Pablo Ccori');

        $ana->update(['photo_path' => 'doctores/ana.jpg']);
        $whitening->doctors()->attach($ana);
        Setting::set('web.hero_image', 'inicio/consultorio.jpg');
        Setting::set('clinic.reviews_url', 'https://g.page/r/consultorio/review');
        SmileCase::factory()->create();

        $this->assertNull(WebSetup::getNavigationBadge());
        Livewire::test(WebSetup::class)->assertDontSee('Lo que falta para que la web dé confianza');
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

    public function test_a_doctor_is_registered_with_their_access_in_one_step(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateDoctor::class)
            ->assertSchemaStateSet(['access' => 'new'])
            ->fillForm(['name' => 'Dra. Ana Torres', 'slug' => 'ana-torres', 'access_email' => 'ana@consultorio.test', 'access_password' => 'corto'])
            ->call('create')
            ->assertHasFormErrors(['access_password' => 'min'])
            ->fillForm(['access_password' => 'clave-segura-1'])
            ->call('create')
            ->assertHasNoFormErrors();

        $doctor = Doctor::query()->where('slug', 'ana-torres')->sole();
        $this->assertSame(['Dra. Ana Torres', 'ana@consultorio.test', UserRole::Doctor], [$doctor->user->name, $doctor->user->email, $doctor->user->role]);
        $this->assertTrue(Hash::check('clave-segura-1', $doctor->user->password));

        // Sin acceso por ahora: el doctor queda sin usuario, y se le puede dar después desde su ficha.
        Livewire::test(CreateDoctor::class)
            ->fillForm(['name' => 'Dr. Luis Paz', 'slug' => 'luis-paz', 'access' => 'none'])
            ->call('create')
            ->assertHasNoFormErrors();

        $luis = Doctor::query()->where('slug', 'luis-paz')->sole();
        $this->assertNull($luis->user_id);

        Livewire::test(EditDoctor::class, ['record' => $luis->getKey()])
            ->assertSchemaStateSet(['access' => 'none'])
            ->fillForm(['access' => 'new', 'access_email' => 'ana@consultorio.test', 'access_password' => 'clave-segura-2'])
            ->call('save')
            ->assertHasFormErrors(['access_email' => 'unique'])
            ->fillForm(['access_email' => 'luis@consultorio.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('luis@consultorio.test', $luis->fresh()->user->email);

        // En Usuarios ya no se da de alta a un doctor: lleva a Doctores.
        Livewire::test(ManageUsers::class)
            ->assertActionHasUrl('newDoctor', DoctorResource::getUrl('create'))
            ->mountAction('create')
            ->assertFormFieldExists('role', 'mountedActionSchema0', fn (Select $field) => ! array_key_exists(UserRole::Doctor->value, $field->getOptions()));
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
            ->assertSee('Les toca control')
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

        $reception = User::factory()->reception()->create();
        $this->actingAs($reception);

        // Una sola línea de tiempo, con su filtro: ya no hay pestañas de Citas y Pagos que la repitan,
        // y el saldo va una sola vez (la etiqueta bajo el nombre), sin tarjeta "Cuenta".
        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSee(['María Quispe', 'Línea de tiempo', 'Limpieza dental · Pendiente', 'Pagó S/ 80.00 · Yape', 'Constancia'])
            ->assertSeeInOrder(['Todo', 'Citas', 'Pagos'])
            ->assertSee('A favor S/ 80.00')
            ->assertDontSee(['Ver María Quispe', 'Penicilina', 'Correo', 'Notas internas', 'Último pago', 'Sin citas todavía', 'Sin pagos todavía']);
        $this->assertSame([ChargesRelationManager::class], PatientResource::getRelations());

        // Un pago se anula desde su línea, con su motivo.
        $payment = $patient->payments()->sole();
        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->mountAction('voidPayment', ['payment' => $payment->id])
            ->setActionData(['reason' => 'Se cobró dos veces'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Pago anulado')
            ->assertSee(['Pagó S/ 80.00 · Yape (anulado)', 'Anulado: Se cobró dos veces', 'Al día'])
            ->assertDontSee('A favor');
        $this->assertSame([$reception->id, 'Se cobró dos veces'], [$payment->fresh()->voided_by_user_id, $payment->fresh()->void_reason]);

        $this->actingAs($doctor->user);

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSeeInOrder(['Atención: Alergia: Penicilina', 'Datos del paciente', 'Línea de tiempo'])
            ->assertDontSee('Pagó S/ 80.00');
    }

    public function test_the_patient_file_sums_up_what_matters_in_chips_under_the_name(): void
    {
        $this->freezeMonday();
        $treatment = Treatment::factory()->create(['name' => 'Limpieza dental']);
        $doctor = $this->doctorOffering($treatment);
        $patient = Patient::factory()
            ->for(Account::factory()->state(['phone' => '+51987654321']))
            ->create(['first_name' => 'Rosa', 'last_name' => 'Quispe', 'birth_date' => '1990-11-02', 'district' => 'San Isidro', 'origin' => null]);
        Charge::factory()->for($patient)->create(['amount' => 120]);
        Payment::factory()->for($patient)->create(['amount' => 80]);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-04-01 10:00')->create(['status' => AppointmentStatus::Completed]);
        Appointment::factory()->for($doctor)->for($treatment)->for($patient)->at('2026-11-04 10:00')->confirmed()->create();

        $this->actingAs(User::factory()->reception()->create());

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSee(['36 años', 'San Isidro', 'Debe S/ 40.00', 'Próxima cita:', '10:00', 'Hoy cumple 36 años', 'Falta: ¿cómo nos encontró?'])
            ->assertSee('https://wa.me/51987654321')
            ->assertSee(PatientResource::getUrl('edit', ['record' => $patient]));

        // El doctor no ve la plata; sin cita por delante y con 7 meses sin venir desde su limpieza, le toca control.
        $patient->appointments()->where('starts_at', '>=', today())->delete();
        $patient->update(['last_visit_at' => now()->subMonths(7), 'origin' => AppointmentOrigin::Instagram]);
        $this->actingAs($doctor->user);

        $this->get(PatientResource::getUrl('view', ['record' => $patient]))
            ->assertOk()
            ->assertSeeInOrder(['Siguiente paso', 'Le toca su control y limpieza', 'Última visita hace 7 meses', 'Agendar', 'Última visita hace 7 meses', 'Origen: Instagram'])
            ->assertDontSee(['Debe S/ 40.00', 'Falta: ¿cómo nos encontró?']);
    }

    public function test_the_phone_bar_shows_what_each_role_uses_most(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());

        // Dos avisos listos para enviar; el recordatorio de mañana todavía no cuenta.
        $patient = Patient::factory()->create();
        foreach ([null, now()->subHour(), now()->addDay()] as $dueAt) {
            WhatsappMessage::create(['account_id' => $patient->account_id, 'patient_id' => $patient->id, 'template_key' => MessageTemplateKey::Reminder, 'phone' => '+51987654321', 'due_at' => $dueAt]);
        }

        // Los avisos se mandan desde el celular: van en la barra con cuántos toca enviar. Caja queda en el menú.
        $this->actingAs(User::factory()->reception()->create());
        $this->get(Dashboard::getUrl())->assertOk()->assertSee(['Accesos rápidos', 'Avisos, 2 por enviar']);
        $this->assertSame(['Hoy', 'Agenda', 'Nueva cita', 'Pacientes', 'Avisos'], array_column(MobileBar::items(), 'label'));
        $this->assertSame([null, null, null, null, '2'], array_column(MobileBar::items(), 'badge'));

        // El doctor ve solo los avisos de sus citas.
        $this->actingAs($doctor->user);
        $this->assertSame(['Hoy', 'Agenda', 'Nueva cita', 'Pacientes', 'Avisos'], array_column(MobileBar::items(), 'label'));
        $this->assertNull(MobileBar::items()[4]['badge']);
    }
}
