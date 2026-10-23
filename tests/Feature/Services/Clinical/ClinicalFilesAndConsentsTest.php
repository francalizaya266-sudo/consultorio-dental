<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\ClinicalFileType;
use App\Enums\PatientRelationship;
use App\Exceptions\ClinicalException;
use App\Models\Account;
use App\Models\ConsentTemplate;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Clinical\ClinicalFileService;
use App\Services\Clinical\ConsentService;
use App\Services\Clinical\ConsultationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAgenda;
use Tests\TestCase;

class ClinicalFilesAndConsentsTest extends TestCase
{
    use CreatesAgenda, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->freezeMonday();
        Storage::fake(ClinicalFileService::DISK);
    }

    public function test_images_are_compressed_with_a_thumbnail_and_documents_are_kept_as_they_are(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        $files = app(ClinicalFileService::class);
        $disk = Storage::disk(ClinicalFileService::DISK);

        $xray = $files->store($patient, UploadedFile::fake()->image('panoramica.png', 3000, 1500), ClinicalFileType::Xray, $doctor->user);

        $this->assertSame(['panoramica', 'image/jpeg'], [$xray->title, $xray->mime_type]);
        $this->assertStringStartsWith('clinica/'.$patient->id.'/', $xray->path);
        $this->assertSame([2000, 1000, 'image/jpeg'], $this->imageSize($disk->get($xray->path)));
        $this->assertSame([360, 180, 'image/jpeg'], $this->imageSize($disk->get($files->thumbnailPath($xray))));
        $this->assertSame(strlen($disk->get($xray->path)), $xray->size_bytes);

        $pdf = $files->store($patient, UploadedFile::fake()->createWithContent('informe.pdf', "%PDF-1.4\n%prueba\n"), ClinicalFileType::Document, $doctor->user, title: 'Informe del laboratorio');

        $this->assertSame(['Informe del laboratorio', 'application/pdf'], [$pdf->title, $pdf->mime_type]);
        $this->assertStringEndsWith('.pdf', $pdf->path);
        $this->assertSame("%PDF-1.4\n%prueba\n", $disk->get($pdf->path));
        $this->assertNull($files->thumbnailPath($pdf));

        $this->assertSame($xray->size_bytes + $pdf->size_bytes, $files->usedBytes($patient));
        $this->assertSame('1.5 MB', ClinicalFileService::humanSize(1572864));
    }

    public function test_files_are_only_served_to_who_can_see_the_clinical_record(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        app(ConsultationService::class)->startWithoutAppointment($patient, $doctor->user);
        $file = app(ClinicalFileService::class)->store($patient, UploadedFile::fake()->image('foto.jpg', 1200, 900), ClinicalFileType::Photo, $doctor->user);

        $this->actingAs($doctor->user)->get($file->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs(User::factory()->admin(isDentist: true)->create())->get($file->url(thumbnail: true))->assertOk();

        // Recepción y otros doctores no ven radiografías ni fotos.
        $this->actingAs(User::factory()->reception()->create())->get($file->url())->assertForbidden();
        $this->actingAs($this->doctorOffering(Treatment::factory()->create())->user)->get($file->url())->assertForbidden();

        // Quitado de la historia: ya no se muestra, pero sigue guardado.
        $url = $file->url();
        $file->delete();
        $this->actingAs($doctor->user)->get($url)->assertNotFound();
        Storage::disk(ClinicalFileService::DISK)->assertExists($file->path);
    }

    public function test_a_parent_signs_for_a_child_and_the_signed_text_does_not_change_when_the_template_does(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $account = Account::factory()->create();
        Patient::factory()->for($account)->create(['first_name' => 'María', 'last_name' => 'Quispe Rojas', 'document_number' => '41234567']);
        $mateo = Patient::factory()->dependentOf($account)->create(['first_name' => 'Mateo', 'last_name' => 'Quispe Rojas', 'birth_date' => now()->subYears(8)]);
        $extraction = Treatment::where('slug', 'extraccion-simple')->firstOrFail();
        $consents = app(ConsentService::class);

        $signer = $consents->defaultSigner($mateo);
        $this->assertSame(['María Quispe Rojas', '41234567', PatientRelationship::Parent], [$signer['signer_name'], $signer['signer_document'], $signer['signer_relationship']]);

        $template = $consents->templateFor($extraction);
        $this->assertSame('Consentimiento informado general', $template->name);

        $consent = $consents->sign($mateo, $template, $extraction, $signer, $this->signature(), $doctor->user);

        $this->assertSame('Consentimiento informado general · Extracción simple', $consent->title);
        $this->assertStringContainsString('Paciente: Mateo Quispe Rojas.', $consent->body_snapshot);
        $this->assertStringContainsString('Yo, María Quispe Rojas, identificado(a) con documento N.º 41234567, en mi calidad de padre / madre del paciente, autorizo a '.$doctor->name.' a realizar el tratamiento de extracción simple.', $consent->body_snapshot);
        $this->assertStringContainsString('Juliaca, 2 de noviembre de 2026.', $consent->body_snapshot);
        $this->assertTrue($doctor->is($consent->doctor));
        // Se guarda solo el trazo, sin el espacio vacío del recuadro.
        $this->assertSame([277, 57, 'image/png'], $this->imageSize(Storage::disk(ClinicalFileService::DISK)->get($consent->signature_path)));
        $this->assertStringStartsWith('data:image/png;base64,', $consents->signatureDataUri($consent));

        // Cambiar el texto crea una versión nueva; lo firmado queda igual.
        $snapshot = $consent->body_snapshot;
        $template->update(['body' => 'Texto nuevo para {paciente}.']);
        $this->assertSame(2, $template->fresh()->version);
        $this->assertSame($snapshot, $consent->fresh()->body_snapshot);

        // Una plantilla propia del tratamiento gana a la general.
        $own = ConsentTemplate::create(['name' => 'Consentimiento de extracción', 'treatment_id' => $extraction->id, 'body' => 'Extracción de {paciente}.']);
        $this->assertTrue($own->is($consents->templateFor($extraction)));
        $this->assertSame('Consentimiento informado general', $consents->templateFor(Treatment::factory()->create())->name);
    }

    public function test_a_bad_signature_is_rejected_and_a_revoked_consent_is_kept(): void
    {
        $doctor = $this->doctorOffering(Treatment::factory()->create());
        $patient = Patient::factory()->create();
        $consents = app(ConsentService::class);
        $template = $consents->templateFor(null);

        try {
            $consents->sign($patient, $template, null, $consents->defaultSigner($patient), 'data:image/png;base64,bm8gZXMgdW5hIGltYWdlbg==', $doctor->user);
            $this->fail('Se aceptó una firma que no es imagen.');
        } catch (ClinicalException) {
            $this->assertSame([], Storage::disk(ClinicalFileService::DISK)->allFiles());
        }

        $consent = $consents->sign($patient, $template, null, $consents->defaultSigner($patient), $this->signature(), $doctor->user);
        $this->assertStringContainsString('en mi calidad de paciente', $consent->body_snapshot);
        $this->assertStringContainsString('el tratamiento indicado', $consent->body_snapshot);

        $this->travel(2)->days();
        $consents->revoke($consent);
        $revokedAt = $consent->fresh()->revoked_at;
        $this->travel(1)->days();
        $consents->revoke($consent->fresh());

        $this->assertFalse($consent->fresh()->isActive());
        $this->assertTrue($revokedAt->equalTo($consent->fresh()->revoked_at));
        $this->assertSame(1, $patient->consents()->count());
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function imageSize(string $contents): array
    {
        $size = getimagesizefromstring($contents);

        return [$size[0], $size[1], $size['mime']];
    }

    /** Una firma como la que envía el navegador: un PNG con un trazo. */
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
