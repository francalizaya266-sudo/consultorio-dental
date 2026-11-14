<?php

namespace Tests\Unit\Support;

use App\Support\SiteArt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteArtTest extends TestCase
{
    public static function treatmentNames(): array
    {
        return [
            'evaluación' => ['Evaluación o primera consulta', 'evaluacion'],
            'limpieza' => ['Limpieza dental', 'limpieza'],
            'blanqueamiento no es una limpieza' => ['Blanqueamiento', 'blanqueamiento'],
            'limpieza con blanqueamiento' => ['Limpieza y blanqueamiento LED', 'blanqueamiento'],
            'brackets' => ['Colocación de brackets', 'brackets'],
            'control de ortodoncia no es la colocación' => ['Control de ortodoncia', 'control'],
            'ajuste de brackets' => ['Ajuste mensual de brackets', 'control'],
            'un control que no es de ortodoncia' => ['Control de implante', 'implante'],
            'resina' => ['Curación con resina', 'resina'],
            'extracción' => ['Extracción simple', 'extraccion'],
            'endodoncia' => ['Endodoncia', 'endodoncia'],
            'implante' => ['Implante', 'implante'],
            'corona' => ['Corona de porcelana', 'corona'],
            'encías' => ['Tratamiento de encías', 'encia'],
            'niños' => ['Odontopediatría', 'ninos'],
            'sin palabra conocida' => ['Otro', 'diente'],
        ];
    }

    #[DataProvider('treatmentNames')]
    public function test_each_treatment_gets_its_own_image_from_its_name(string $name, string $image): void
    {
        $this->assertSame($image, SiteArt::key($name));
    }

    public static function specialties(): array
    {
        return [
            'implantólogo' => ['Cirujano dentista · Implantólogo', 'implante'],
            'ortodoncista' => ['Ortodoncista', 'brackets'],
            'endodoncista' => ['Endodoncista', 'endodoncia'],
            'odontopediatra' => ['Odontopediatra', 'ninos'],
            'cirujano dentista no es una especialidad' => ['Cirujano dentista', null],
            'odontología general' => ['Odontología general', null],
            'sin especialidad' => [null, null],
        ];
    }

    #[DataProvider('specialties')]
    public function test_the_specialty_a_doctor_declares_has_its_image_only_when_it_is_a_specialty(?string $specialty, ?string $image): void
    {
        $this->assertSame($image, SiteArt::specialty($specialty));
    }

    public function test_every_image_a_treatment_can_get_is_in_the_site_in_both_sizes(): void
    {
        $folder = dirname(__DIR__, 3).'/public/img/3d/';

        foreach (['diente', 'evaluacion', 'limpieza', 'blanqueamiento', 'resina', 'extraccion', 'brackets', 'control', 'implante', 'endodoncia', 'corona', 'encia', 'ninos'] as $image) {
            $this->assertFileExists($folder.$image.'.webp');
            $this->assertFileExists($folder.$image.'-192.webp');
        }
    }
}
