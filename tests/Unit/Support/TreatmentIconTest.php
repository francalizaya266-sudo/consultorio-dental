<?php

namespace Tests\Unit\Support;

use App\Support\TreatmentIcon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TreatmentIconTest extends TestCase
{
    public static function treatmentNames(): array
    {
        return [
            'limpieza' => ['Limpieza dental', 'sparkle'],
            'blanqueamiento' => ['Blanqueamiento LED', 'sparkle'],
            'ortodoncia' => ['Ortodoncia con brackets', 'braces'],
            'control de ortodoncia antes que control' => ['Control de ortodoncia', 'braces'],
            'implante' => ['Implante dental', 'implant'],
            'endodoncia' => ['Endodoncia (tratamiento de conducto)', 'root'],
            'extracción con tilde' => ['Extracción simple', 'extraction'],
            'encías' => ['Tratamiento de encías', 'gum'],
            'niños' => ['Atención de niños', 'child'],
            'odontopediatría' => ['Odontopediatría', 'child'],
            'resina' => ['Curación con resina', 'filling'],
            'evaluación' => ['Evaluación y diagnóstico', 'clipboard'],
            'urgencia no es encía' => ['Atención de urgencia', 'tooth'],
            'sin palabra conocida' => ['Otro', 'tooth'],
        ];
    }

    #[DataProvider('treatmentNames')]
    public function test_the_icon_is_chosen_from_the_treatment_name(string $name, string $icon): void
    {
        $this->assertSame($icon, TreatmentIcon::key($name));
    }

    public function test_the_svg_takes_the_text_color_and_is_hidden_from_screen_readers(): void
    {
        $svg = TreatmentIcon::svg('implant')->toHtml();

        $this->assertStringStartsWith('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertSame(TreatmentIcon::svg('tooth')->toHtml(), TreatmentIcon::svg('no-existe')->toHtml());
    }
}
