<?php

namespace Tests\Feature\Filament;

use App\Enums\ToothState;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\Doctor;
use App\Models\OdontogramCondition;
use App\Services\Clinical\OdontogramService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OdontogramChartTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_the_tooth_card_names_the_tooth_and_groups_the_faces_of_a_finding(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $this->actingAs($doctor->user);

        $state = Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->call('mark', 36, 'O', 'caries', 'bad', 'CD')
            ->call('mark', 36, 'M', 'caries', 'bad', 'CD')
            ->call('mark', 14, null, 'protesis_fija', 'good', null, 16)
            ->viewData('state');

        $this->assertSame('Primer molar inferior izquierdo', $state['teeth'][36]['name']);
        $this->assertTrue($state['teeth'][36]['posterior']);
        $this->assertFalse($state['teeth'][11]['posterior']);
        $this->assertSame([], $state['teeth'][11]['entries']);

        [$caries] = $state['teeth'][36]['entries'];
        $this->assertCount(2, $caries['ids']);
        $this->assertSame(['Caries (CD · dentina)', 'mesial y oclusal', 'bad', true], [$caries['name'], $caries['where'], $caries['state'], $caries['treatable']]);

        // El puente aparece en cada pieza que cubre.
        foreach ([14, 15, 16] as $tooth) {
            $this->assertSame('de la 14 a la 16', $state['teeth'][$tooth]['entries'][0]['where']);
        }
    }

    public function test_a_finding_on_several_faces_is_treated_or_removed_at_once(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $this->actingAs($doctor->user);

        $chart = Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->call('mark', 36, 'O', 'caries', 'bad', 'CD')
            ->call('mark', 36, 'M', 'caries', 'bad', 'CD');

        // Una sigla por recuadro aunque esté en dos caras.
        $this->assertSame(['CD'], array_column($chart->viewData('teeth')[36]['labels'], 'text'));

        $ids = $consultation->odontogram->entries()->pluck('id')->all();
        $chart->call('markTreated', $ids, 'R');

        $this->assertSame(
            [['restauracion', 'M', ToothState::Good, 'R'], ['restauracion', 'O', ToothState::Good, 'R']],
            $consultation->odontogram->entries()->with('condition')->orderBy('surface')->get()->map(fn ($e) => [$e->condition->code, $e->surface, $e->state, $e->abbreviation])->all(),
        );

        $chart->call('remove', $consultation->odontogram->entries()->pluck('id')->all())
            ->assertSee('Sin hallazgos marcados.');

        $this->assertSame(0, $consultation->odontogram->entries()->count());
    }

    public function test_the_filters_count_teeth_and_today_shows_only_what_changed_since_the_last_visit(): void
    {
        [$doctor, $first] = $this->consultation();
        $this->actingAs($doctor->user);
        $odontograms = app(OdontogramService::class);

        $odontograms->toggle($first->odontogram, $this->condition('caries'), ToothState::Bad, 36, 'O', abbreviation: 'CD');
        $odontograms->toggle($first->odontogram, $this->condition('ausente'), ToothState::Good, 18);

        // En el primer odontograma todo es nuevo: no se resalta nada.
        $summary = Livewire::test(OdontogramChart::class, ['record' => $first, 'editable' => true])->viewData('summary');
        $this->assertSame(['bad' => 1, 'good' => 0, 'missing' => 1, 'today' => 0], $summary);

        // La atención siguiente parte de ese odontograma; hoy se marca la 46.
        $second = Consultation::factory()->for($first->patient)->for($doctor)->create();
        $odontograms->forConsultation($second);
        $odontograms->toggle($second->odontogram, $this->condition('restauracion'), ToothState::Good, 46, 'O', abbreviation: 'R');

        $chart = Livewire::test(OdontogramChart::class, ['record' => $second->fresh(), 'editable' => true]);

        $this->assertSame(['bad' => 1, 'good' => 1, 'missing' => 1, 'today' => 1], $chart->viewData('summary'));
        $this->assertSame([46 => true, 36 => false, 18 => false], collect($chart->viewData('groups'))->mapWithKeys(fn ($g) => [$g['tooth'] => $g['new']])->sortKeysDesc()->all());
        $this->assertTrue($chart->viewData('teeth')[46]['flags']['today']);
        $chart->assertSeeHtml('data-today');
    }

    public function test_what_is_written_is_previewed_on_the_drawing(): void
    {
        [$doctor, $consultation] = $this->consultation();
        $this->actingAs($doctor->user);

        $preview = Livewire::test(OdontogramChart::class, ['record' => $consultation, 'editable' => true])
            ->set('dictation', '36 MO caries, puente 14 a 16')
            ->viewData('preview');

        $this->assertEqualsCanonicalizing([14, 15, 16, 36], $preview);
    }

    /** @return array{0: Doctor, 1: Consultation} */
    private function consultation(): array
    {
        $doctor = Doctor::factory()->create();
        $consultation = Consultation::factory()->for($doctor)->create();
        app(OdontogramService::class)->forConsultation($consultation);

        return [$doctor, $consultation->fresh()];
    }

    private function condition(string $code): OdontogramCondition
    {
        return OdontogramCondition::where('code', $code)->firstOrFail();
    }
}
