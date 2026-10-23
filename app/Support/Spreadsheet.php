<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/**
 * Un Excel (.xlsx) sencillo, hecho a mano para no sumar dependencias: varias hojas con encabezado
 * en negrita y montos, porcentajes y fechas como números de verdad (se pueden sumar y filtrar).
 * Si el hosting no tiene la extensión zip, sale un CSV que Excel también abre.
 */
final class Spreadsheet
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const COUNT = 'count';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    /** Estilos de styles.xml: [normal, negrita] por tipo. */
    private const STYLES = [
        self::TEXT => [0, 4],
        self::MONEY => [1, 5],
        self::PERCENT => [2, 6],
        self::DATE => [3, 3],
        self::COUNT => [8, 7],
    ];

    /** @var list<array{name: string, header: list<string>, types: list<string>, rows: list<list<mixed>>, total: list<mixed>|null}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $header
     * @param  list<string>  $types  Tipo de cada columna (TEXT, MONEY, COUNT, PERCENT, DATE)
     * @param  list<list<mixed>>  $rows
     * @param  list<mixed>|null  $total  Fila final en negrita
     */
    public function sheet(string $name, array $header, array $types, array $rows, ?array $total = null): self
    {
        $this->sheets[] = [
            'name' => mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), 0, 31),
            'header' => $header,
            'types' => $types,
            'rows' => $rows,
            'total' => $total,
        ];

        return $this;
    }

    public static function canWriteXlsx(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function toXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($sheet));
        }

        $zip->close();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** Todas las hojas una debajo de otra, con BOM para que Excel lea bien las tildes. */
    public function toCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($this->sheets as $index => $sheet) {
            if ($index > 0) {
                fputcsv($out, [], escape: '');
            }

            fputcsv($out, [$sheet['name']], escape: '');
            fputcsv($out, $sheet['header'], escape: '');

            foreach ([...$sheet['rows'], ...($sheet['total'] ? [$sheet['total']] : [])] as $row) {
                fputcsv($out, array_map(fn ($value, $i) => $this->csvValue($value, $sheet['types'][$i] ?? self::TEXT), $row, array_keys($row)), escape: '');
            }
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function csvValue(mixed $value, string $type): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof CarbonInterface => $value->format('d/m/Y'),
            $type === self::MONEY => number_format((float) $value, 2, '.', ''),
            $type === self::PERCENT => number_format((float) $value, 1, '.', ''),
            // Que un texto que empieza con "=" no se lea como fórmula.
            is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 => "'".$value,
            default => (string) $value,
        };
    }

    /** @param  array{name: string, header: list<string>, types: list<string>, rows: list<list<mixed>>, total: list<mixed>|null}  $sheet */
    private function worksheet(array $sheet): string
    {
        $rows = [$this->row(1, $sheet['header'], array_fill(0, count($sheet['header']), self::TEXT), bold: true)];

        foreach ($sheet['rows'] as $i => $row) {
            $rows[] = $this->row($i + 2, $row, $sheet['types']);
        }

        if ($sheet['total']) {
            $rows[] = $this->row(count($sheet['rows']) + 2, $sheet['total'], $sheet['types'], bold: true);
        }

        $cols = '';

        foreach ($sheet['header'] as $i => $label) {
            $longest = max(array_map(fn ($row) => mb_strlen($this->display($row[$i] ?? '', $sheet['types'][$i] ?? self::TEXT)), [$sheet['header'], ...$sheet['rows']]));
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, max(10, $longest + 2)).'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  list<mixed>  $values
     * @param  list<string>  $types
     */
    private function row(int $number, array $values, array $types, bool $bold = false): string
    {
        $cells = '';

        foreach (array_values($values) as $i => $value) {
            $type = $types[$i] ?? self::TEXT;
            $ref = $this->column($i).$number;
            $style = self::STYLES[$type][$bold ? 1 : 0];

            $cells .= match (true) {
                $value === null || $value === '' => '',
                $value instanceof CarbonInterface => '<c r="'.$ref.'" s="'.$style.'"><v>'.$this->excelDate($value).'</v></c>',
                $type !== self::TEXT && is_numeric($value) => '<c r="'.$ref.'" s="'.$style.'"><v>'.($type === self::PERCENT ? (float) $value / 100 : (float) $value).'</v></c>',
                default => '<c r="'.$ref.'" t="inlineStr" s="'.self::STYLES[self::TEXT][$bold ? 1 : 0].'"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>',
            };
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    private function display(mixed $value, string $type): string
    {
        return match (true) {
            $value instanceof CarbonInterface => '00/00/0000',
            $type === self::MONEY => 'S/ '.number_format((float) $value, 2),
            default => (string) $value,
        };
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Días desde el 30/12/1899, como cuenta Excel. */
    private function excelDate(CarbonInterface $date): int
    {
        $utc = new DateTimeZone('UTC');

        return (int) (new DateTimeImmutable('1899-12-30', $utc))->diff(new DateTimeImmutable($date->format('Y-m-d'), $utc))->days;
    }

    private function escape(string $value): string
    {
        // Quita los caracteres de control que el XML no admite.
        return htmlspecialchars((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $sheets = '';

        foreach (array_keys($this->sheets) as $index) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.$this->escape($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '';

        foreach (array_keys($this->sheets) as $index) {
            $relationships .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        $relationships .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    }

    private function styles(): string
    {
        $xf = fn (int $numFmt, int $font) => '<xf numFmtId="'.$numFmt.'" fontId="'.$font.'" fillId="0" borderId="0" xfId="0"'
            .($numFmt ? ' applyNumberFormat="1"' : '').($font ? ' applyFont="1"' : '').'/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="&quot;S/ &quot;#,##0.00"/>'
            .'<numFmt numFmtId="165" formatCode="0.0%"/>'
            .'<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/>'
            .'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .$xf(0, 0)      // 0 texto
            .$xf(164, 0)    // 1 soles
            .$xf(165, 0)    // 2 porcentaje
            .$xf(166, 0)    // 3 fecha
            .$xf(0, 1)      // 4 texto en negrita
            .$xf(164, 1)    // 5 soles en negrita
            .$xf(165, 1)    // 6 porcentaje en negrita
            .$xf(3, 1)      // 7 cantidad en negrita
            .$xf(3, 0)      // 8 cantidad
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
