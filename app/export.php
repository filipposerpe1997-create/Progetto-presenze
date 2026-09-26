<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];
$s = get_settings($uid);

$ym = (string)($_GET['m'] ?? date('Y-m'));
if (!valid_month($ym)) {
    $ym = date('Y-m');
}
$format = (string)($_GET['f'] ?? 'xlsx');
$month = calc_month($uid, $ym, $s);
$t = $month['totali'];
$nome = $user['nome'] ?: $user['username'];
$title = 'Presenze ' . month_label($ym);
$fileBase = 'presenze_' . $ym . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nome);

/** Righe di riepilogo comuni a Excel e PDF: [etichetta, valore in minuti|int|float, tipo]. */
function summary_rows(array $t, array $s): array
{
    $rows = [
        ['Ore previste nel mese', $t['previste'], 'h'],
        ['Ore lavorate', $t['lavorate'], 'h'],
        ['Ore ordinarie', $t['ordinarie'], 'h'],
        ['Straordinario feriale', $t['straord'], 'h'],
        ['Straordinario festivo', $t['straord_festivo'], 'h'],
        ['Totale straordinari', $t['straord_totale'], 'h'],
        ['Ore giustificate (ferie/permessi/malattia)', $t['giustificate'], 'h'],
        ['Ore mancanti', $t['mancanti'], 'h'],
        ['Saldo ore', $t['saldo'], 's'],
        ['Giorni lavorati', $t['giorni_lavorati'], 'i'],
        ['Giorni di ferie', $t['giorni_ferie'], 'i'],
        ['Giorni di permesso', $t['giorni_permesso'], 'i'],
        ['Giorni di malattia', $t['giorni_malattia'], 'i'],
        ['Giorni smart working', $t['giorni_smart'], 'i'],
        ['Giorni di trasferta', $t['giorni_trasferta'], 'i'],
    ];
    if ($s['paga_oraria'] > 0) {
        $rows[] = ['Importo ordinario (stima lorda)', $t['importo_ordinario'], 'e'];
        $rows[] = ['Importo straord. feriale (+' . (float)$s['magg_straord'] . '%)', $t['importo_straord'], 'e'];
        $rows[] = ['Importo straord. festivo (+' . (float)$s['magg_festivo'] . '%)', $t['importo_festivo'], 'e'];
        $rows[] = ['Totale stimato lordo', $t['importo_totale'], 'e'];
    }
    return $rows;
}

function day_cells(array $d): array
{
    $row = $d['row'];
    $work = $row && in_array($row['tipo'], TIPI_LAVORO, true);
    return [
        'data' => date('d/m/Y', strtotime($d['data'])),
        'giorno' => GIORNI_BREVI[$d['dow']],
        'tipo' => $d['tipo'] ? (TIPI[$d['tipo']] ?? $d['tipo']) . ($d['festivita'] && $d['tipo'] !== 'festivo' ? ' (' . $d['festivita'] . ')' : '')
            : ($d['festivita'] ?? ''),
        'e1' => $work ? parse_time($row['entrata1']) : null,
        'u1' => $work ? parse_time($row['uscita1']) : null,
        'e2' => $work ? parse_time($row['entrata2']) : null,
        'u2' => $work ? parse_time($row['uscita2']) : null,
        'note' => $row['note'] ?? '',
    ];
}

if ($format === 'pdf') {
    require __DIR__ . '/lib/fpdf/fpdf.php';
    require __DIR__ . '/includes/pdf.php';
    export_pdf($month, $s, $nome, $title, $fileBase);
} else {
    require __DIR__ . '/includes/xlsx.php';
    export_xlsx($month, $s, $nome, $title, $fileBase);
}
exit;

function export_xlsx(array $month, array $s, string $nome, string $title, string $fileBase): void
{
    $t = $month['totali'];
    $rows = [];
    $rows[] = [[$title, XlsxWriter::S_TITLE]];
    $rows[] = [[trim($nome . ($s['azienda'] ? ' – ' . $s['azienda'] : '')), XlsxWriter::S_MUTED]];
    $rows[] = [];
    $headers = ['Data', 'Giorno', 'Tipo', 'Entrata', 'Uscita', 'Entrata', 'Uscita', 'Pausa', 'Lavorate', 'Ordinarie', 'Straord.', 'Straord. festivo', 'Giustificate', 'Mancanti', 'Note'];
    $rows[] = array_map(fn($h) => [$h, XlsxWriter::S_HEADER], $headers);
    $headerRow = count($rows);

    $h = fn(?int $m, int $style = XlsxWriter::S_HOURS) => $m === null ? ['', XlsxWriter::S_TEXT] : XlsxWriter::hours($m, $style);
    $z = fn(int $m) => $m ? XlsxWriter::hours($m) : ['', XlsxWriter::S_TEXT];
    foreach ($month['giorni'] as $d) {
        $c = day_cells($d);
        $txt = $d['festivo'] ? XlsxWriter::S_FESTIVO : XlsxWriter::S_TEXT;
        $rows[] = [
            [$c['data'], $txt], [$c['giorno'], $txt], [$c['tipo'], $txt],
            $h($c['e1']), $h($c['u1']), $h($c['e2']), $h($c['u2']),
            $z($d['pausa'] && $d['row'] && in_array($d['tipo'], TIPI_LAVORO, true) ? $d['pausa'] : 0),
            $d['lavorate'] ? XlsxWriter::hours($d['lavorate'], XlsxWriter::S_HOURS_BOLD_POS) : ['', XlsxWriter::S_TEXT],
            $z($d['ordinarie']), $z($d['straord']), $z($d['straord_festivo']), $z($d['giustificate']), $z($d['mancanti']),
            [$c['note'], XlsxWriter::S_TEXT],
        ];
    }
    $rows[] = [
        ['TOTALE', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL],
        ['', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL], ['', XlsxWriter::S_TOTAL_LABEL],
        XlsxWriter::hours($t['lavorate'], XlsxWriter::S_TOTAL_HOURS),
        XlsxWriter::hours($t['ordinarie'], XlsxWriter::S_TOTAL_HOURS),
        XlsxWriter::hours($t['straord'], XlsxWriter::S_TOTAL_HOURS),
        XlsxWriter::hours($t['straord_festivo'], XlsxWriter::S_TOTAL_HOURS),
        XlsxWriter::hours($t['giustificate'], XlsxWriter::S_TOTAL_HOURS),
        XlsxWriter::hours($t['mancanti'], XlsxWriter::S_TOTAL_HOURS),
        ['', XlsxWriter::S_TOTAL_LABEL],
    ];

    $rows[] = [];
    $rows[] = [['Riepilogo del mese', XlsxWriter::S_TITLE]];
    $merges = ['A1:O1', 'A2:O2'];
    foreach (summary_rows($t, $s) as [$label, $val, $type]) {
        $cell = match ($type) {
            'h' => XlsxWriter::hours((int)$val),
            's' => [fmt_min((int)$val, true), XlsxWriter::S_HOURS],
            'i' => [(int)$val, XlsxWriter::S_INT],
            'e' => [(float)$val, XlsxWriter::S_EUR],
        };
        $rows[] = [[$label, XlsxWriter::S_TEXT], null, null, $cell];
        $r = count($rows);
        $merges[] = "A$r:C$r";
    }

    $widths = [11, 7, 22, 8, 8, 8, 8, 8, 9, 9, 9, 10, 10, 9, 40];
    $w = new XlsxWriter();
    $w->addSheet(month_label($month['mese']), $rows, $widths, $merges, $headerRow);
    $w->output($fileBase . '.xlsx');
}
