<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Backups;
use App\Models\Doctor;
use App\Models\User;
use App\Services\Backup\DatabaseBackup;
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
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00', 'America/Lima'));
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
        }
    }
}
