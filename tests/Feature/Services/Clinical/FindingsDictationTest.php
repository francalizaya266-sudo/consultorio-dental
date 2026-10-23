<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\Dentition;
use App\Models\OdontogramCondition;
use App\Services\Clinical\FindingsDictation;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FindingsDictationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_short_writing_marks_surfaces_with_their_abbreviation(): void
    {
        $this->assertSame(
            [
                [36, 'O', null, 'caries', 'bad', 'CD'],
                [46, 'M', null, 'restauracion', 'good', 'R'],
                [46, 'O', null, 'restauracion', 'good', 'R'],
                [18, null, null, 'ausente', 'good', null],
                [14, null, 16, 'protesis_fija', 'good', null],
                [26, 'O', null, 'restauracion', 'good', 'IV'],
            ],
            $this->marks('36 O CD, 46 MO resina. 18 ausente; puente de 14 a 16, 26 o iv'),
        );
    }

    public function test_dictated_words_are_understood_like_the_doctor_says_them(): void
    {
        $this->assertSame(
            [
                [36, 'O', null, 'caries', 'bad', 'CD'],
                [36, 'D', null, 'caries', 'bad', 'CD'],
                [11, 'I', null, 'caries', 'bad', 'CE'],
                [47, null, null, 'corona', 'bad', 'CMC'],
                [25, null, null, 'tratamiento_pulpar', 'good', 'TC'],
                [38, null, null, 'extraccion_indicada', 'bad', null],
            ],
            $this->marks('treinta y seis ocluso distal caries de dentina, pieza once oclusal caries del esmalte, cuarenta y siete corona metal cerámica en mal estado, dos cinco endodoncia, 38 extracción'),
        );
    }

    public function test_a_finding_without_its_own_name_repeats_the_previous_one(): void
    {
        $this->assertSame(
            [[36, 'O', null, 'caries', 'bad', 'CD'], [37, 'O', null, 'caries', 'bad', 'CD'], [45, 'L', null, 'caries', 'bad', 'CD']],
            $this->marks('caries de dentina en la 36 oclusal y 37 oclusal, 45 palatina'),
        );
    }

    public function test_what_is_not_understood_says_why_and_marks_nothing(): void
    {
        $results = app(FindingsDictation::class)->parse('36 caries, 55 ausente, 46 algo raro, puente 14, oclusal caries', Dentition::Permanent, OdontogramCondition::all());

        $this->assertSame(
            [
                'Falta la cara: O, M, D, V, P o L (o "oclusal", "mesial"…).',
                'La pieza 55 no está en este odontograma. Cambia la dentición si hace falta.',
                'No reconocí el hallazgo.',
                'Di desde qué pieza hasta cuál, por ejemplo "14 a 16".',
                'Falta el número de la pieza.',
            ],
            array_column($results, 'error'),
        );
        $this->assertSame([], array_merge(...array_column($results, 'marks')));
        $this->assertSame('36 caries', $results[0]['text']);
    }

    public function test_the_summary_reads_back_what_will_be_marked(): void
    {
        $results = app(FindingsDictation::class)->parse('36 MO CD', Dentition::Permanent, OdontogramCondition::all());

        $this->assertSame('36 · mesial y oclusal — Caries (CD · dentina), por tratar', $results[0]['summary']);
    }

    /** @return list<array{0: int, 1: string|null, 2: int|null, 3: string, 4: string, 5: string|null}> */
    private function marks(string $text): array
    {
        $results = app(FindingsDictation::class)->parse($text, Dentition::Permanent, OdontogramCondition::all());

        $this->assertSame([], array_values(array_filter(array_column($results, 'error'))), 'No se esperaban errores.');

        return array_map(
            fn (array $mark) => [$mark['tooth'], $mark['surface'], $mark['toTooth'], $mark['code'], $mark['state'], $mark['abbreviation']],
            array_merge(...array_column($results, 'marks')),
        );
    }
}
