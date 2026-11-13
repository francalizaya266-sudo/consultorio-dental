<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Backups;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Widgets\SystemHealth;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\FilesBackup;
use App\Services\Backup\OffsiteCopies;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
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
        $this->stored('clinica/7/radiografia.jpg', '2026-10-20 09:00');

        foreach ([User::factory()->reception()->create(), Doctor::factory()->create()->user] as $user) {
            $this->actingAs($user);

            $this->get(Backups::getUrl())->assertForbidden();
            $this->get($this->download($name))->assertForbidden();
            $this->get($this->downloadFiles(FilesBackup::EVERYTHING))->assertForbidden();
        }

        // El administrador que no atiende pacientes abre la pantalla, pero no se lleva las historias.
        $this->actingAs(User::factory()->admin(isDentist: false)->create());

        $this->get(Backups::getUrl())->assertOk();
        $this->get($this->download($name))->assertForbidden();
        $this->get($this->downloadFiles(FilesBackup::EVERYTHING))->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('event', OffsiteCopies::DOWNLOADED)->count());
    }

    public function test_any_admin_sees_if_the_copies_are_up_to_date_and_whom_to_ask_for_the_download(): void
    {
        User::factory()->admin(isDentist: true)->create(['name' => 'Ana Torres']);
        User::factory()->admin(isDentist: true)->create(['name' => 'Luis Paz', 'is_active' => false]);
        $this->stored('respaldos/respaldo_2026-10-22_030000.sql.gz', '2026-10-22 03:00');
        $this->stored('clinica/7/radiografia.jpg', '2026-10-20 09:00');
        $this->actingAs(User::factory()->admin(isDentist: false)->create());

        Livewire::test(Backups::class)
            ->assertSee(['Tú las ves, pero no las descargas', 'Hoy puede descargarlas: Ana Torres.', 'Jueves 22 de octubre de 2026', 'Archivos nuevos', '1 archivo'])
            ->assertDontSee('Luis Paz')
            // Sin botones ni enlaces de descarga.
            ->assertDontSee([$this->download('respaldo_2026-10-22_030000.sql.gz'), $this->downloadFiles(FilesBackup::PENDING)], false)
            ->callAction('create')
            ->assertNotified('Copia creada')
            ->assertSee('Viernes 23 de octubre de 2026');

        // La dentista que administra sí tiene los botones, y no la nota.
        $this->actingAs(User::query()->where('name', 'Ana Torres')->sole());

        Livewire::test(Backups::class)
            ->assertDontSee('Tú las ves, pero no las descargas')
            ->assertSee([$this->download('respaldo_2026-10-22_030000.sql.gz'), $this->downloadFiles(FilesBackup::PENDING)], false);
    }

    public function test_when_no_admin_is_a_dentist_the_page_says_how_to_let_someone_download(): void
    {
        $this->actingAs(User::factory()->admin(isDentist: false)->create());

        Livewire::test(Backups::class)
            ->assertSee('Hoy nadie puede descargarlas. En Usuarios, marca "Es odontólogo" en el administrador que atiende pacientes.', false);
    }

    public function test_old_copies_are_deleted_but_the_latest_always_stays(): void
    {
        $this->stored('respaldos/respaldo_2026-10-21_030000.sql.gz', '2026-10-21 03:00');
        $this->stored('respaldos/respaldo_2026-10-22_030000.sql.gz', '2026-10-22 03:00');
        $this->stored('respaldos/respaldo_2026-10-23_030000.sql.gz', '2026-10-23 03:00');
        $this->stored('clinica/7/radiografia.jpg', '2026-10-20 09:00');
        $this->actingAs($admin = User::factory()->admin(isDentist: false)->create(['name' => 'Carla Vega']));

        Livewire::test(Backups::class)
            ->mountAction(TestAction::make('delete')->arguments(['file' => 'respaldo_2026-10-21_030000.sql.gz']))
            ->assertMountedActionModalSee(['Borrar la copia del miércoles 21 de octubre', 'no se puede recuperar'])
            ->callMountedAction()
            ->assertNotified('Copia borrada')
            ->assertDontSee('Miércoles 21 de octubre de 2026')
            ->assertSee(['Jueves 22 de octubre de 2026', 'Viernes 23 de octubre de 2026']);

        Storage::disk('local')->assertMissing('respaldos/respaldo_2026-10-21_030000.sql.gz');

        // Queda quién la borró, en el historial de cambios.
        $deleted = AuditLog::query()->where('event', OffsiteCopies::DELETED)->sole();
        $this->assertSame([$admin->id, ['file' => 'respaldo_2026-10-21_030000.sql.gz']], [$deleted->user_id, $deleted->new_values]);

        // Ni la más reciente, ni otra cosa del disco aunque alguien arme el pedido a mano.
        foreach (['respaldo_2026-10-23_030000.sql.gz', '../clinica/7/radiografia.jpg', ''] as $file) {
            Livewire::test(Backups::class)
                ->callAction(TestAction::make('delete')->arguments(['file' => $file]))
                ->assertNotified('Esa copia no se puede borrar');
        }

        Storage::disk('local')->assertExists(['respaldos/respaldo_2026-10-23_030000.sql.gz', 'respaldos/respaldo_2026-10-22_030000.sql.gz', 'clinica/7/radiografia.jpg']);
        $this->assertSame(1, AuditLog::query()->where('event', OffsiteCopies::DELETED)->count());
    }

    public function test_who_downloaded_each_copy_shows_on_the_page_and_stays_in_the_change_history(): void
    {
        $this->stored('respaldos/respaldo_2026-10-22_030000.sql.gz', '2026-10-22 03:00');
        $this->stored('respaldos/respaldo_2026-10-23_030000.sql.gz', '2026-10-23 03:00');
        $this->stored('clinica/7/radiografia.jpg', '2026-10-20 09:00');
        $this->actingAs($ana = User::factory()->admin(isDentist: true)->create(['name' => 'Ana Torres']));

        Livewire::test(Backups::class)->assertSee('Sin descargar')->assertDontSee('La descargó');

        $this->get($this->download('respaldo_2026-10-22_030000.sql.gz'))->assertOk();
        $this->travel(2)->hours();
        $this->get($this->downloadFiles(FilesBackup::PENDING))->assertOk();

        // En la pantalla, cada copia dice quién la bajó; la otra sigue sin descargar.
        Livewire::test(Backups::class)->assertSeeInOrder(['Viernes 23 de octubre de 2026', 'Sin descargar', 'Jueves 22 de octubre de 2026', 'La descargó Ana Torres hace 2 horas']);

        $data = AuditLog::query()->where('event', OffsiteCopies::DOWNLOADED)->where('new_values->file', 'respaldo_2026-10-22_030000.sql.gz')->sole();
        $files = AuditLog::query()->where('event', OffsiteCopies::DOWNLOADED)->where('new_values->file', 'archivos_2026-10-23.zip')->sole();
        $user = AuditLog::query()->where('auditable_type', User::class)->where('event', 'created')->where('auditable_id', $ana->id)->sole();

        Livewire::test(ManageAuditLogs::class)
            ->assertTableColumnStateSet('section', 'Copias de seguridad', $data)
            ->assertTableColumnStateSet('subject', 'Copia de los datos del 22/10/2026 03:00', $data)
            ->assertTableColumnStateSet('summary', 'Se descargó', $data)
            ->assertTableColumnStateSet('user.name', 'Ana Torres', $data)
            ->assertTableColumnStateSet('subject', 'Radiografías, fotos, documentos y firmas (archivos_2026-10-23.zip)', $files)
            // Se anotan junto a un ajuste, pero no son un cambio de Ajustes.
            ->filterTable('section', 'Copias de seguridad')
            ->assertCanSeeTableRecords([$data, $files])
            ->assertCanNotSeeTableRecords([$user])
            ->filterTable('section', 'Ajustes y página web')
            ->assertCanNotSeeTableRecords([$data, $files]);
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

        // El administrador que no es odontólogo se entera y llega a la pantalla, que le dice a quién pedírsela.
        $this->travel(8)->days();
        $this->stored('respaldos/respaldo_2026-10-31_030000.sql.gz', '2026-10-31 03:00');
        $this->actingAs(User::factory()->admin(isDentist: false)->create());

        Livewire::test(SystemHealth::class)
            ->assertSee(['Toca descargar la copia de seguridad de esta semana', 'La última descarga de los datos fue hace 1 semana.', 'Ver copias'])
            ->assertSee(Backups::getUrl(), false);
        Livewire::test(Backups::class)
            ->assertSee('La última descarga de los datos fue hace 1 semana. Avísale a Ana Torres para que descargue lo que falta y lo guarde fuera del sistema.')
            ->assertDontSee('Descarga aquí abajo lo que falta');
    }
}
