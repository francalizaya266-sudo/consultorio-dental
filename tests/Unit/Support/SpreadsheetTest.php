<?php

namespace Tests\Unit\Support;

use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class SpreadsheetTest extends TestCase
{
    private function book(): Spreadsheet
    {
        return (new Spreadsheet)->sheet(
            'Pagos: octubre/2026',
            ['Fecha', 'Paciente', 'Monto', 'Parte'],
            [Spreadsheet::DATE, Spreadsheet::TEXT, Spreadsheet::MONEY, Spreadsheet::PERCENT],
            [
                [CarbonImmutable::parse('2026-10-02'), 'María José Ñahui', 1250.5, 62.5],
                [CarbonImmutable::parse('2026-10-05'), '=HYPERLINK("http://x")', 750, 37.5],
            ],
            ['Total', null, 2000.5, 100],
        );
    }

    public function test_numbers_and_dates_are_real_cells_and_text_is_escaped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, $this->book()->toXlsx());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $this->assertStringContainsString('name="Pagos  octubre 2026"', $zip->getFromName('xl/workbook.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        // 2 de octubre de 2026 como fecha de Excel, el monto como número y el porcentaje como fracción.
        $this->assertStringContainsString('<c r="A2" s="3"><v>46297</v></c>', $sheet);
        $this->assertStringContainsString('<c r="C2" s="1"><v>1250.5</v></c>', $sheet);
        $this->assertStringContainsString('<c r="D2" s="2"><v>0.625</v></c>', $sheet);
        // Un texto que parece fórmula se guarda como texto.
        $this->assertStringContainsString('<t xml:space="preserve">=HYPERLINK(&quot;http://x&quot;)</t>', $sheet);
        $this->assertStringContainsString('María José Ñahui', $sheet);
        // La fila de total va en negrita.
        $this->assertStringContainsString('<c r="C4" s="5"><v>2000.5</v></c>', $sheet);

        $zip->close();
        unlink($path);
    }

    public function test_the_csv_keeps_accents_and_disarms_formulas(): void
    {
        $csv = $this->book()->toCsv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('02/10/2026,"María José Ñahui",1250.50,62.5', $csv);
        $this->assertStringContainsString("05/10/2026,\"'=HYPERLINK(\"\"http://x\"\")\",750.00,37.5", $csv);
        $this->assertStringContainsString('Total,,2000.50,100.0', $csv);
    }
}
