<?php

namespace Tests\Feature\Services\Backup;

use App\Models\User;
use App\Services\Backup\FilesBackup;
use App\Services\Backup\OffsiteCopies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class FilesBackupTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('web');
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
    }

    /** Guarda un archivo como si se hubiera subido en ese momento. */
    private function stored(string $disk, string $path, string $at, int $bytes = 10): void
    {
        Storage::disk($disk)->put($path, str_repeat('x', $bytes));
        touch(Storage::disk($disk)->path($path), CarbonImmutable::parse($at, 'America/Lima')->getTimestamp());
    }

    /** @return list<string> Lo que trae el .zip, sin la nota de cómo recuperarlo. */
    private function contents(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $index) => $zip->getNameIndex($index))->reject('LEEME.txt')->sort()->values()->all();
        $zip->close();

        return $names;
    }

    public function test_each_download_brings_only_what_is_new_since_the_last_one(): void
    {
        $this->stored('local', 'clinica/7/radiografia.jpg', '2026-10-20 09:00');
        $this->stored('local', 'clinica/7/firmas/firma.png', '2026-10-21 09:00');
        $this->stored('web', 'doctores/ana.jpg', '2026-10-22 09:00');
        // Las copias de los datos y lo demás del disco no son archivos de pacientes.
        $this->stored('local', 'respaldos/respaldo_2026-10-23_030000.sql.gz', '2026-10-23 03:00');
        $backup = app(FilesBackup::class);
        $ana = User::factory()->admin(isDentist: true)->create(['name' => 'Ana Torres']);

        $this->assertSame([3, 30, 1], [$backup->pending()['files'], $backup->pending()['bytes'], count($backup->pending()['parts'])]);
        $this->assertNull($backup->last());

        $zip = $backup->download(FilesBackup::PENDING, 1, $ana);

        $this->assertSame('archivos_2026-10-23.zip', $zip['name']);
        $this->assertSame(['clinica/7/firmas/firma.png', 'clinica/7/radiografia.jpg', 'web/doctores/ana.jpg'], $this->contents($zip['path']));
        $this->assertSame([0, []], [$backup->pending()['files'], $backup->pending()['parts']]);
        $this->assertSame([3, 'Ana Torres', '2026-10-23 10:00'], [$backup->last()['files'], $backup->last()['by'], $backup->last()['at']->format('Y-m-d H:i')]);

        // Una semana después solo sale la radiografía nueva.
        $this->stored('local', 'clinica/9/panoramica.jpg', '2026-10-27 16:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Lima'));

        $this->assertSame(1, $backup->pending()['files']);
        $this->assertSame(['clinica/9/panoramica.jpg'], $this->contents($backup->download(FilesBackup::PENDING, 1, $ana)['path']));

        // La tanda anterior se puede volver a bajar, y "todos" trae todo desde el principio.
        $this->assertSame(['clinica/9/panoramica.jpg'], $this->contents($backup->download(FilesBackup::LAST, 1, $ana)['path']));
        $everything = $backup->download(FilesBackup::EVERYTHING, 1, $ana);
        $this->assertSame('archivos_todos_2026-10-30.zip', $everything['name']);
        $this->assertCount(4, $this->contents($everything['path']));
        // Bajar de nuevo no cambia qué se considera ya bajado.
        $this->assertSame(0, $backup->pending()['files']);
    }

    public function test_a_heavy_download_comes_in_parts_and_is_done_when_all_of_them_are(): void
    {
        config(['consultorio.backups.files_part_mb' => 1]);
        $this->stored('local', 'clinica/1/a.jpg', '2026-10-20 09:00', 600 * 1024);
        $this->stored('local', 'clinica/1/b.jpg', '2026-10-20 10:00', 600 * 1024);
        $this->stored('local', 'clinica/1/c.jpg', '2026-10-20 11:00', 300 * 1024);
        $backup = app(FilesBackup::class);

        $this->assertSame([[1, 1, false], [2, 2, false]], array_map(fn (array $part) => [$part['number'], $part['files'], $part['done']], $backup->pending()['parts']));

        $second = $backup->download(FilesBackup::PENDING, 2, null);
        $this->assertSame('archivos_2026-10-23_parte-2-de-2.zip', $second['name']);
        $this->assertSame(['clinica/1/b.jpg', 'clinica/1/c.jpg'], $this->contents($second['path']));

        // Falta la primera: la tanda sigue abierta y lo que llegue después espera a la siguiente.
        $this->stored('local', 'clinica/1/d.jpg', '2026-10-23 09:30');
        $this->assertNull($backup->last());
        $this->assertSame([[1, false], [2, true]], array_map(fn (array $part) => [$part['number'], $part['done']], $backup->pending()['parts']));

        $this->assertSame(['clinica/1/a.jpg'], $this->contents($backup->download(FilesBackup::PENDING, 1, null)['path']));

        $this->assertSame(3, $backup->last()['files']);
        $this->assertSame(1, $backup->pending()['files']);
    }

    public function test_a_part_that_does_not_exist_is_not_a_download(): void
    {
        $backup = app(FilesBackup::class);

        foreach ([[FilesBackup::PENDING, 1], [FilesBackup::LAST, 1], [FilesBackup::EVERYTHING, 2], ['otra', 1]] as [$which, $part]) {
            try {
                $backup->download($which, $part, null);
                $this->fail("Se armó una descarga de {$which} sin archivos.");
            } catch (InvalidArgumentException) {
                $this->assertNull($backup->last());
            }
        }
    }

    public function test_the_reminder_comes_after_a_week_without_taking_the_copies_out(): void
    {
        $copies = app(OffsiteCopies::class);
        $ana = User::factory()->admin(isDentist: true)->create();

        // Recién instalado, sin nada que bajar todavía.
        $this->assertNull($copies->due());

        // La primera copia de los datos tiene 8 días y nunca se bajó; una radiografía lleva 9 días esperando.
        $this->stored('local', 'respaldos/respaldo_2026-10-15_030000.sql.gz', '2026-10-15 03:00');
        $this->stored('local', 'clinica/7/radiografia.jpg', '2026-10-14 09:00');
        Cache::flush();

        $this->assertSame('Todavía no se ha descargado ninguna copia de los datos y hay radiografías, fotos o firmas sin descargar desde hace 1 semana', $copies->due());

        $copies->recordDatabaseDownload($ana);
        $this->assertSame('Hay radiografías, fotos o firmas sin descargar desde hace 1 semana', $copies->due());

        app(FilesBackup::class)->download(FilesBackup::PENDING, 1, $ana);
        $this->assertNull($copies->due());

        // Lo nuevo de esta semana todavía no apura; pasada la semana, sí.
        $this->stored('local', 'clinica/7/control.jpg', '2026-10-23 09:00');
        $this->travel(6)->days();
        Cache::flush();
        $this->assertNull($copies->due());

        $this->travel(2)->days();
        Cache::flush();
        $this->assertSame('La última descarga de los datos fue hace 1 semana y hay radiografías, fotos o firmas sin descargar desde hace 1 semana', $copies->due());
    }
}
