<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Backups;
use App\Filament\Widgets\SystemHealth;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\FilesBackup;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BackupsPanelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('web');
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
    }

    private function downloadFiles(string $batch, int $part = 1): string
    {
        return Filament::getPanel('admin')->route('backups.files.download', ['batch' => $batch, 'part' => $part]);
    }

    /** Guarda un archivo como si se hubiera subido en ese momento. */
    private function stored(string $path, string $at): void
    {
        Storage::disk('local')->put($path, 'archivo');
        touch(Storage::disk('local')->path($path), CarbonImmutable::parse($at, 'America/Lima')->getTimestamp());
    }

    private function download(string $file): string
    {
        return Filament::getPanel('admin')->route('backups.download', ['file' => $file]);
    }

    public function test_the_dentist_admin_creates_lists_and_downloads_copies(): void
    {
        $this->actingAs(User::factory()->admin(isDentist: true)->create());

        Livewire::test(Backups::class)
            ->assertSee('No hay una copia de las últimas horas')
            // Escrito para el consultorio, no para el programador.
            ->assertDontSee(['cron', 'README', 'phpMyAdmin', 'artisan'])
            ->assertSee('Aún no hay copias.')
            ->callAction('create')
            ->assertNotified('Copia creada')
            ->assertSee('Viernes 23 de octubre de 2026')
            ->assertDontSee('No hay una copia de las últimas horas');

        $response = $this->get($this->download('respaldo_2026-10-23_100000.sql.gz'))->assertOk();
        $this->assertSame('attachment; filename=respaldo_2026-10-23_100000.sql.gz', $response->headers->get('Content-Disposition'));

        // Solo archivos de copia, nada más del disco.
        $this->get($this->download('respaldo_2026-10-22_100000.sql.gz'))->assertNotFound();
        $this->get($this->download('..%2F..%2F.env'))->assertNotFound();
    }

    public function test_copies_have_clinical_data_so_only_the_dentist_admin_gets_them(): void
    {
        $name = basename(app(DatabaseBackup::class)->create());
        foreach ([User::factory()->admin(isDentist: false)->create(), User::factory()->reception()->create(), Doctor::factory()->create()->user] as $user) {
            $this->actingAs($user);

            $this->get(Backups::getUrl())->assertForbidden();
            $this->get($this->download($name))->assertForbidden();
            $this->get($this->downloadFiles(FilesBackup::EVERYTHING))->assertForbidden();
        }
    }

    public function test_radiographs_photos_and_signatures_are_downloaded_apart_and_only_the_new_ones(): void
    {
        $this->stored('clinica/7/radiografia.jpg', '2026-10-20 09:00');
        $this->stored('clinica/7/firmas/firma.png', '2026-10-21 09:00');
        $this->actingAs(User::factory()->admin(isDentist: true)->create(['name' => 'Ana Torres']));

        Livewire::test(Backups::class)
            ->assertSee(['Radiografías, fotos, documentos y firmas', 'Archivos nuevos', '2 archivos'])
            ->assertSee($this->downloadFiles(FilesBackup::PENDING), false)
            // Mientras no se haya bajado nada, lo nuevo ya es todo: no hace falta ofrecer "todos".
            ->assertDontSee(['Última descarga', 'Para una computadora nueva']);

        $response = $this->get($this->downloadFiles(FilesBackup::PENDING))->assertOk();
        $this->assertSame('attachment; filename=archivos_2026-10-23.zip', $response->headers->get('Content-Disposition'));

        Livewire::test(Backups::class)
            ->assertSee(['No hay archivos nuevos por descargar.', 'por Ana Torres', 'Para una computadora nueva'])
            ->assertSee([$this->downloadFiles(FilesBackup::LAST), $this->downloadFiles(FilesBackup::EVERYTHING)], false)
            ->assertDontSee($this->downloadFiles(FilesBackup::PENDING), false);

        // Sin nada nuevo no hay qué bajar, y solo existen las tandas que la página ofrece.
        $this->get($this->downloadFiles(FilesBackup::PENDING))->assertNotFound();
        $this->get($this->downloadFiles(FilesBackup::EVERYTHING, 2))->assertNotFound();
        $this->get(Backups::getUrl().'/archivos/otra/1')->assertNotFound();
    }

    public function test_after_a_week_without_downloading_the_page_and_the_home_remind_it(): void
    {
        $admin = User::factory()->admin(isDentist: true)->create(['name' => 'Ana Torres']);
        $this->actingAs($admin);
        $this->stored('respaldos/respaldo_2026-10-15_030000.sql.gz', '2026-10-15 03:00');
        $this->stored('respaldos/respaldo_2026-10-23_030000.sql.gz', '2026-10-23 03:00');
        $this->stored('clinica/7/radiografia.jpg', '2026-10-14 09:00');

        Livewire::test(Backups::class)
            ->assertSee([
                'Toca la descarga de esta semana',
                'Todavía no se ha descargado ninguna copia de los datos y hay radiografías, fotos o firmas sin descargar desde hace 1 semana.',
                'Todavía no se ha descargado ninguna.',
            ]);
        Livewire::test(SystemHealth::class)
            ->assertSee(['Toca descargar la copia de seguridad de esta semana', 'Ver copias']);

        $this->get($this->download('respaldo_2026-10-23_030000.sql.gz'))->assertOk();
        $this->get($this->downloadFiles(FilesBackup::PENDING))->assertOk();

        Livewire::test(Backups::class)
            ->assertDontSee('Toca la descarga de esta semana')
            ->assertSee('Última descarga: hace 0 segundos, el viernes 23 de octubre, por Ana Torres.');
        $this->assertFalse(SystemHealth::canView());

        // El administrador que no es odontólogo se entera, pero no las puede bajar: sin enlace.
        $this->travel(8)->days();
        $this->stored('respaldos/respaldo_2026-10-31_030000.sql.gz', '2026-10-31 03:00');
        $this->actingAs(User::factory()->admin(isDentist: false)->create());

        Livewire::test(SystemHealth::class)
            ->assertSee(['Toca descargar la copia de seguridad de esta semana', 'La última descarga de los datos fue hace 1 semana.'])
            ->assertDontSee('Ver copias');
    }
}
