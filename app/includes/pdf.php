<?php
declare(strict_types=1);

final class PresenzePdf extends FPDF
{
    public string $docTitle = '';
    public string $docSub = '';

    public static function t(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    /** Tronca il testo con "..." se non entra nella larghezza indicata. */
    public static function fit(FPDF $pdf, string $txt, float $w): string
    {
        if ($pdf->GetStringWidth($txt) <= $w) {
            return $txt;
        }
        while ($txt !== '' && $pdf->GetStringWidth($txt . '...') > $w) {
            $txt = substr($txt, 0, -1);
        }
        return $txt . '...';
    }

    public function Header(): void
    {
        $this->SetFillColor(30, 58, 138);
        $this->Rect(0, 0, $this->GetPageWidth(), 4, 'F');
        $this->SetY(8);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(30, 58, 138);
        $this->Cell(0, 7, self::t($this->docTitle), 0, 1);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(107, 114, 128);
        $this->Cell(0, 5, self::t($this->docSub), 0, 1);
        $this->Ln(3);
        $this->SetTextColor(0);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(140);
        $this->Cell(0, 5, self::t('Generato il ' . date('d/m/Y H:i') . ' · Pagina ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
    }
}

function export_pdf(array $month, array $s, string $nome, string $title, string $fileBase): void
{
    $t = $month['totali'];
    $pdf = new PresenzePdf('L', 'mm', 'A4');
    $pdf->docTitle = $title;
    $pdf->docSub = trim($nome . ($s['azienda'] ? ' – ' . $s['azienda'] : ''));
    $pdf->SetTitle($title, true);
    $pdf->SetAuthor($nome, true);
    $pdf->SetCreator('Presenze', true);
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $cols = [
        ['Data', 18, 'C'], ['Gg', 9, 'C'], ['Tipo', 34, 'L'],
        ['Entrata', 14, 'C'], ['Uscita', 14, 'C'], ['Entrata', 14, 'C'], ['Uscita', 14, 'C'],
        ['Pausa', 12, 'C'], ['Lavorate', 16, 'C'], ['Ordinarie', 16, 'C'], ['Straord.', 15, 'C'],
        ['Str. fest.', 15, 'C'], ['Giustif.', 15, 'C'], ['Mancanti', 15, 'C'], ['Note', 46, 'L'],
    ];
    $rowH = 5.2;

    $header = function () use ($pdf, $cols) {
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetFillColor(30, 58, 138);
        $pdf->SetTextColor(255);
        $pdf->SetDrawColor(209, 213, 219);
        foreach ($cols as [$label, $w]) {
            $pdf->Cell($w, 6, PresenzePdf::t($label), 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0);
    };
    $header();

    $hm = fn(?int $m) => $m === null ? '' : fmt_clock($m);
    $dm = fn(int $m) => $m ? fmt_min($m) : '';
    $pdf->SetFont('Helvetica', '', 7.5);
    foreach ($month['giorni'] as $i => $d) {
        if ($pdf->GetY() + $rowH > $pdf->GetPageHeight() - 14) {
            $pdf->AddPage();
            $header();
            $pdf->SetFont('Helvetica', '', 7.5);
        }
        $c = day_cells($d);
        if ($d['festivo']) {
            $pdf->SetFillColor(254, 242, 242);
        } elseif ($i % 2) {
            $pdf->SetFillColor(248, 250, 252);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        $pausa = ($d['row'] && in_array($d['tipo'], TIPI_LAVORO, true)) ? $dm($d['pausa']) : '';
        $values = [
            $c['data'], $c['giorno'], $c['tipo'], $hm($c['e1']), $hm($c['u1']), $hm($c['e2']), $hm($c['u2']),
            $pausa, $dm($d['lavorate']), $dm($d['ordinarie']), $dm($d['straord']), $dm($d['straord_festivo']),
            $dm($d['giustificate']), $dm($d['mancanti']), $c['note'],
        ];
        foreach ($cols as $k => [$label, $w, $align]) {
            $pdf->SetFont('Helvetica', $k === 8 && $d['lavorate'] ? 'B' : '', 7.5);
            $txt = PresenzePdf::fit($pdf, PresenzePdf::t((string)$values[$k]), $w - 2);
            $pdf->Cell($w, $rowH, $txt, 1, 0, $align, true);
        }
        $pdf->Ln();
    }

    // Riga totali
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->SetFillColor(224, 231, 255);
    $wLabel = array_sum(array_map(fn($c) => $c[1], array_slice($cols, 0, 8)));
    $pdf->Cell($wLabel, 6, 'TOTALE', 1, 0, 'L', true);
    foreach ([$t['lavorate'], $t['ordinarie'], $t['straord'], $t['straord_festivo'], $t['giustificate'], $t['mancanti']] as $k => $v) {
        $pdf->Cell($cols[8 + $k][1], 6, fmt_min($v), 1, 0, 'C', true);
    }
    $pdf->Cell($cols[14][1], 6, '', 1, 1, 'C', true);

    // Riepilogo
    $summary = summary_rows($t, $s);
    $needed = 12 + ceil(count($summary) / 2) * 6 + 25;
    if ($pdf->GetY() + $needed > $pdf->GetPageHeight() - 14) {
        $pdf->AddPage();
    }
    $pdf->Ln(5);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(0, 7, PresenzePdf::t('Riepilogo del mese'), 0, 1);
    $pdf->SetTextColor(0);

    $half = (int)ceil(count($summary) / 2);
    $x0 = $pdf->GetX();
    $y0 = $pdf->GetY();
    foreach ($summary as $i => [$label, $val, $type]) {
        $col = $i < $half ? 0 : 1;
        $row = $i < $half ? $i : $i - $half;
        $pdf->SetXY($x0 + $col * 138, $y0 + $row * 6);
        $value = match ($type) {
            'h' => fmt_min((int)$val),
            's' => fmt_min((int)$val, true),
            'i' => (string)(int)$val,
            'e' => fmt_eur((float)$val),
        };
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(95, 6, PresenzePdf::t($label), 'B', 0, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(35, 6, PresenzePdf::t($value), 'B', 0, 'R', true);
    }
    $pdf->SetXY($x0, $y0 + $half * 6 + 14);

    // Firme
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetDrawColor(120);
    $y = $pdf->GetY();
    $pdf->Line(15, $y + 8, 105, $y + 8);
    $pdf->Line(180, $y + 8, 270, $y + 8);
    $pdf->SetXY(15, $y + 9);
    $pdf->Cell(90, 4, 'Firma dipendente', 0, 0, 'C');
    $pdf->SetXY(180, $y + 9);
    $pdf->Cell(90, 4, 'Firma responsabile', 0, 0, 'C');

    $pdf->Output('D', $fileBase . '.pdf', true);
}
