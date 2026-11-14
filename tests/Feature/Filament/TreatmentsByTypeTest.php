<?php

namespace Tests\Feature\Filament;

use App\Enums\TreatmentKind;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Con el catálogo crecido, la lista de tratamientos se junta por tipo o se deja solo uno. */
class TreatmentsByTypeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Treatment $retainer;

    private Treatment $prophylaxis;

    private Treatment $brackets;

    private Treatment $deepCleaning;

    private Treatment $veneers;

    private Treatment $sealants;

    private Treatment $evaluation;

    protected function setUp(): void
    {
        parent::setUp();

        // En el orden en que el consultorio los puso en su lista.
        $this->retainer = Treatment::factory()->create(['name' => 'Retenedor fijo', 'sort_order' => 1]);
        $this->prophylaxis = Treatment::factory()->create(['name' => 'Profilaxis', 'sort_order' => 2]);
        $this->brackets = Treatment::factory()->create(['name' => 'Brackets metálicos', 'sort_order' => 3]);
        $this->deepCleaning = Treatment::factory()->create(['name' => 'Limpieza profunda', 'sort_order' => 4]);
        $this->veneers = Treatment::factory()->create(['name' => 'Carillas de porcelana', 'sort_order' => 5]);
        // Por el nombre sería "Otro": manda el tipo elegido en su ficha.
        $this->sealants = Treatment::factory()->create(['name' => 'Sellantes', 'kind' => TreatmentKind::Cleaning, 'sort_order' => 6]);
        $this->evaluation = Treatment::factory()->evaluation()->create(['sort_order' => 7]);

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_list_is_grouped_by_type_and_keeps_its_order_inside_each_one(): void
    {
        Livewire::test(ManageTreatments::class)
            // Sin agrupar, como siempre: en el orden del consultorio.
            ->assertCanSeeTableRecords([$this->retainer, $this->prophylaxis, $this->brackets, $this->deepCleaning, $this->veneers, $this->sealants, $this->evaluation], inOrder: true)
            ->set('tableGrouping', 'kind')
            // Los tipos por su nombre y "Otro" al final; dentro de cada uno, el orden de siempre.
            ->assertCanSeeTableRecords([$this->evaluation, $this->prophylaxis, $this->deepCleaning, $this->sealants, $this->retainer, $this->brackets, $this->veneers], inOrder: true)
            // Cada grupo lleva su título encima.
            ->assertSeeHtmlInOrder(['Limpieza', 'Profilaxis', 'Sellantes', 'Ortodoncia', 'Retenedor fijo', 'Brackets metálicos', 'Otro', 'Carillas de porcelana']);
    }

    public function test_the_list_can_show_a_single_type(): void
    {
        Livewire::test(ManageTreatments::class)
            // Solo se ofrecen los tipos que tiene algún tratamiento.
            ->assertTableFilterExists('kind', fn (SelectFilter $filter) => $filter->getOptions() === [
                'evaluation' => 'Evaluación',
                'cleaning' => 'Limpieza',
                'orthodontics' => 'Ortodoncia',
                'other' => 'Otro',
            ])
            ->filterTable('kind', 'cleaning')
            ->assertCanSeeTableRecords([$this->prophylaxis, $this->deepCleaning, $this->sealants], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->retainer, $this->brackets, $this->veneers, $this->evaluation])
            ->filterTable('kind', 'orthodontics')
            ->assertCanSeeTableRecords([$this->retainer, $this->brackets], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->prophylaxis, $this->sealants])
            // Un tipo que nadie tiene no muestra nada, aunque alguien arme el pedido a mano.
            ->filterTable('kind', 'implant')
            ->assertCountTableRecords(0)
            ->removeTableFilter('kind')
            ->assertCountTableRecords(7);
    }

    public function test_a_doctor_groups_and_filters_the_list_too(): void
    {
        $this->actingAs(Doctor::factory()->create()->user);

        Livewire::test(ManageTreatments::class)
            ->set('tableGrouping', 'kind')
            ->filterTable('kind', 'orthodontics')
            ->assertCanSeeTableRecords([$this->retainer, $this->brackets], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->veneers]);
    }
}
