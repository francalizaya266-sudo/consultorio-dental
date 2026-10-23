<?php

namespace Tests\Feature\Filament;

use App\Enums\ClinicalFileType;
use App\Filament\Actions\SignConsentAction;
use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\Pages\ViewClinicalRecord;
use App\Filament\Resources\Patients\RelationManagers\ClinicalFilesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\ConsentsRelationManager;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicalFile;
use App\Models\Consent;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ClinicalFilesPanelTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        Storage::fake(ClinicalFileService::DISK);
    }

    public function test_the_doctor_uploads_several_photos_from_the_record_and_from_the_consultation(): void
    {
        [$doctor, $patient] = $this->doctorWithPatient();
        $this->actingAs($doctor->user);

        // Livewire descarta las subidas temporales de "hace más de un día": se vuelve a la fecha real.
        $this->travelBack();

        Livewire::test(ClinicalFilesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewClinicalRecord::class])
            ->callAction(TestAction::make('uploadFiles')->table(), data: [
                'files' => [UploadedFile::fake()->image('a.jpg', 2400, 1800), UploadedFile::fake()->image('b.jpg', 800, 600)],
                'type' => ClinicalFileType::Photo->value,
                'title' => 'Sonrisa inicial',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('2 archivos guardados')
            ->assertCanSeeTableRecords(ClinicalFile::all())
            ->assertSee('Sonrisa inicial (2)');

        $this->assertSame(['Sonrisa inicial (1)', 'Sonrisa inicial (2)'], ClinicalFile::orderBy('id')->pluck('title')->all());
        $this->assertSame([ClinicalFileType::Photo], ClinicalFile::pluck('type')->unique()->values()->all());

        $consultation = $patient->consultations()->sole();

        Livewire::test(EditConsultation::class, ['record' => $consultation->getRouteKey()])
            ->callAction(TestAction::make('uploadFiles')->schemaComponent('files'), data: [
                'files' => [UploadedFile::fake()->image('periapical.png', 600, 800)],
                'type' => ClinicalFileType::Xray->value,
                'title' => 'Periapical de 36',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Archivo guardado')
            ->assertSee('Periapical de 36');

        $xray = ClinicalFile::where('type', ClinicalFileType::Xray)->sole();
        $this->assertTrue($consultation->is($xray->consultation));
        $this->assertSame('image/jpeg', $xray->mime_type);
    }

    public function test_a_consent_is_signed_on_screen_printed_and_revoked(): void
    {
        $extraction = Treatment::where('slug', 'extraccion-simple')->firstOrFail();
        $doctor = $this->doctorOffering($extraction);
        $patient = Patient::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Huamán Torres']);
        Appointment::factory()->for($doctor)->for($extraction)->for($patient)->at('2026-11-03 10:00')->confirmed()->create();
        $this->actingAs($doctor->user);

        $component = Livewire::test(ConsentsRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewClinicalRecord::class])
            ->mountAction(TestAction::make('signConsent')->table())
            ->assertSchemaStateSet(['treatment_id' => $extraction->id, 'signer_name' => 'Rosa Huamán Torres', 'signer_relationship' => 'self'])
            ->callMountedAction()
            ->assertHasActionErrors(['signature' => 'required'])
            ->fillForm(['signature' => $this->signature()])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Consentimiento firmado');

        $consent = Consent::sole();
        $this->assertSame('Consentimiento informado general · Extracción simple', $consent->title);

        $this->get(SignConsentAction::printUrl($consent))
            ->assertOk()
            ->assertSee(['Rosa Huamán Torres', 'en mi calidad de paciente', 'autorizo a '.$doctor->name.' a realizar el tratamiento de extracción simple', 'data:image/png;base64,'], false)
            ->assertDontSee('Revocado por el paciente');

        $component
            ->callAction(TestAction::make('revoke')->table($consent))
            ->assertNotified('Consentimiento revocado')
            ->assertSee('Revocado el 02/11/2026')
            ->assertActionHidden(TestAction::make('revoke')->table($consent));

        $this->get(SignConsentAction::printUrl($consent))->assertSee('Revocado por el paciente el 02/11/2026');

        // Recepción no abre consentimientos.
        $this->actingAs(User::factory()->reception()->create())->get(SignConsentAction::printUrl($consent))->assertForbidden();
    }

    public function test_the_full_history_prints_with_the_odontogram_and_leaves_a_trace(): void
    {
        [$doctor, $patient] = $this->doctorWithPatient();
        $consultation = $patient->consultations()->sole();
        $consultation->update(['chief_complaint' => 'Dolor al masticar en el lado izquierdo.']);
        app(ClinicalFileService::class)->store($patient, UploadedFile::fake()->image('bite.jpg', 400, 300), ClinicalFileType::Xray, $doctor->user, $consultation, 'Aleta de mordida');
        $url = Filament::getPanel('admin')->route('print.history', ['patient' => $patient]);

        $this->actingAs($doctor->user)->get($url)
            ->assertOk()
            ->assertSee([$patient->full_name, 'Dolor al masticar en el lado izquierdo.', 'Aleta de mordida', 'Ley N.º 29733'])
            ->assertSee('class="od-svg', false);

        $audit = AuditLog::where('event', 'printed')->sole();
        $this->assertSame([$patient->getMorphClass(), $patient->id, $doctor->user->id], [$audit->auditable_type, $audit->auditable_id, $audit->user_id]);

        $this->actingAs(User::factory()->reception()->create())->get($url)->assertForbidden();
        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user)->get($url)->assertForbidden();
    }

    public function test_only_the_admin_edits_the_consent_texts(): void
    {
        [$doctor] = $this->doctorWithPatient();

        $this->actingAs(User::factory()->admin()->create())->get(ConsentTemplateResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Consentimiento informado general');

        $this->actingAs($doctor->user);
        $this->assertTrue(ConsentTemplateResource::canViewAny());
        $this->assertFalse(ConsentTemplateResource::canCreate());
    }

    /** @return array{0: Doctor, 1: Patient} Paciente con una atención abierta con el doctor. */
    private function doctorWithPatient(): array
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        app(ConsultationService::class)->startWithoutAppointment($patient, $doctor->user);

        return [$doctor, $patient];
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 20, 80, 280, 40, imagecolorallocate($image, 15, 23, 42));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
