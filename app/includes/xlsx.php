<?php
declare(strict_types=1);

/**
 * Writer XLSX minimale (Office Open XML) senza dipendenze esterne.
 * Supporta più fogli, stili predefiniti, larghezze colonne, celle unite
 * e valori orari (minuti) formattati come [h]:mm.
 */
final class XlsxWriter
{
    // Indici degli stili definiti in styles().
    public const S_DEFAULT = 0;
    public const S_TITLE = 1;
    public const S_HEADER = 2;
    public const S_TEXT = 3;
    public const S_HOURS = 4;
    public const S_TOTAL_LABEL = 5;
    public const S_TOTAL_HOURS = 6;
    public const S_MUTED = 7;
    public const S_HOURS_BOLD_POS = 8;
    public const S_EUR = 9;
    public const S_TOTAL_EUR = 10;
    public const S_FESTIVO = 11;
    public const S_INT = 12;

    private array $sheets = [];

    /** @param array<int, array<int, array{0:mixed,1?:int,2?:string}|null>> $rows */
    public function addSheet(string $name, array $rows, array $colWidths = [], array $merges = [], ?int $freezeRow = null): void
    {
        $this->sheets[] = compact('name', 'rows', 'colWidths', 'merges', 'freezeRow');
    }

    public static function hours(int $minutes, int $style = self::S_HOURS): array
    {
        return [$minutes / 1440, $style, 'n'];
    }

    public function output(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, max-age=0');
        readfile($tmp);
        unlink($tmp);
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file Excel');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($n));
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($n));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet));
        }
        $zip->close();
    }

    private static function x(string $s): string
    {
        // Rimuove caratteri di controllo non ammessi in XML.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private function contentTypes(int $n): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $x . '</Types>';
    }

    private function workbook(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $s['name']), 0, 31);
            $x .= '<sheet name="' . self::x($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $x . '</sheets></workbook>';
    }

    private function workbookRels(int $n): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $x .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $x . '</Relationships>';
    }

    private function styles(): string
    {
        // numFmt 164 = [h]:mm (ore oltre le 24), 165 = euro.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="[h]:mm"/><numFmt numFmtId="165" formatCode="&quot;€&quot; #,##0.00"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><sz val="9"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF2F2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                   // 0 default
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                     // 1 titolo
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 2 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                   // 3 testo
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 4 ore
            . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'      // 5 totale etichetta
            . '<xf numFmtId="164" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 6 totale ore
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                     // 7 muted
            . '<xf numFmtId="164" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 8 ore grassetto
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'           // 9 euro
            . '<xf numFmtId="165" fontId="3" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 10 totale euro
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'                    // 11 festivo
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 12 intero
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheetXml(array $sheet): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        if ($sheet['freezeRow']) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $sheet['freezeRow'] . '" topLeftCell="A' . ($sheet['freezeRow'] + 1)
                . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($sheet['colWidths']) {
            $x .= '<cols>';
            foreach ($sheet['colWidths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $cells) {
            $rn = $r + 1;
            $x .= '<row r="' . $rn . '">';
            foreach ((array)$cells as $c => $cell) {
                if ($cell === null) {
                    continue;
                }
                if (!is_array($cell)) {
                    $cell = [$cell];
                }
                $value = $cell[0];
                $style = $cell[1] ?? self::S_DEFAULT;
                $ref = self::col($c) . $rn;
                $s = $style ? ' s="' . $style . '"' : '';
                if ($value === null || $value === '') {
                    $x .= '<c r="' . $ref . '"' . $s . '/>';
                } elseif (is_int($value) || is_float($value)) {
                    $x .= '<c r="' . $ref . '"' . $s . '><v>' . rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string)$value) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sheet['merges']) {
            $x .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        return $x . '</worksheet>';
    }
}
