<?php

namespace Tests\Feature\Services\Backup;

use App\Models\Charge;
use App\Models\Patient;
use App\Services\Backup\DatabaseBackup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-23 03:00', 'America/Lima'));
    }

    public function test_a_backup_brings_back_everything_as_it_was(): void
    {
        // Textos con comillas, punto y coma y saltos de línea: lo que más fácil rompe una copia en SQL.
        $patient = Patient::factory()->create([
            'first_name' => "María D'Angelo",
            'admin_notes' => "Prefiere la tarde;\nllamar antes. -- No escribir por SMS\n'ok'",
        ]);
        Charge::factory()->for($patient)->count(150)->create(['amount' => 80.5]);

        $name = app(DatabaseBackup::class)->create();

        $this->assertSame('respaldos/respaldo_2026-10-23_030000.sql.gz', $name);
        Storage::disk('local')->assertExists($name);
        $this->assertStringContainsString('INSERT INTO', gzdecode(Storage::disk('local')->get($name)));

        // Se pierde todo…
        DB::table('charges')->delete();
        DB::table('patients')->update(['first_name' => 'Otro', 'admin_notes' => null]);

        app(DatabaseBackup::class)->restore($name);

        // …y vuelve igual.
        $restored = Patient::query()->sole();
        $this->assertSame(["María D'Angelo", "Prefiere la tarde;\nllamar antes. -- No escribir por SMS\n'ok'"], [$restored->first_name, $restored->admin_notes]);
        $this->assertSame(150, Charge::query()->count());
        $this->assertSame('80.50', Charge::query()->first()->amount);
    }

    public function test_only_the_last_copies_are_kept(): void
    {
        config(['consultorio.backups.keep' => 3]);
        $backup = app(DatabaseBackup::class);

        foreach (range(1, 5) as $day) {
            $this->travelTo(CarbonImmutable::parse("2026-10-{$day} 03:00", 'America/Lima'));
            $backup->create();
        }

        $this->assertSame(
            ['respaldo_2026-10-05_030000.sql.gz', 'respaldo_2026-10-04_030000.sql.gz', 'respaldo_2026-10-03_030000.sql.gz'],
            array_column($backup->list(), 'file'),
        );
        $this->assertSame('2026-10-05 03:00', $backup->list()[0]['created_at']->format('Y-m-d H:i'));
    }

    public function test_the_commands_create_and_restore_the_latest_copy(): void
    {
        $patient = Patient::factory()->create(['first_name' => 'Rosa']);

        $this->artisan('respaldo:crear')->assertSuccessful();
        $patient->update(['first_name' => 'Cambiado']);

        $this->artisan('respaldo:restaurar')
            ->expectsConfirmation('Se reemplazará TODA la base de datos por la copia respaldo_2026-10-23_030000.sql.gz. Lo registrado después de esa copia se pierde. ¿Seguir?', 'no')
            ->assertFailed();
        $this->assertSame('Cambiado', $patient->fresh()->first_name);

        $this->artisan('respaldo:restaurar', ['archivo' => 'respaldo_2026-10-23_030000.sql.gz', '--force' => true])->assertSuccessful();
        $this->assertSame('Rosa', $patient->fresh()->first_name);

        $this->artisan('respaldo:restaurar', ['archivo' => '../../.env', '--force' => true])->assertFailed();
    }
}
