<?php
declare(strict_types=1);

/**
 * Logica di calcolo ore: ordinarie, straordinarie (feriali e festive),
 * ore giustificate (ferie/permessi/malattia) e riepilogo mensile.
 * Tutti i valori sono in minuti.
 */

const TIPI = [
    'lavoro' => 'Lavoro',
    'smart' => 'Smart working',
    'trasferta' => 'Trasferta',
    'ferie' => 'Ferie',
    'permesso' => 'Permesso',
    'malattia' => 'Malattia',
    'festivo' => 'Festività',
    'riposo' => 'Riposo',
];

// Tipi di giornata in cui si lavora (contano le timbrature).
const TIPI_LAVORO = ['lavoro', 'smart', 'trasferta'];
// Tipi di assenza che coprono l'intero orario previsto.
const TIPI_GIUSTIFICATI = ['ferie', 'permesso', 'malattia'];

const GIORNI = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];
const GIORNI_BREVI = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Gio', 5 => 'Ven', 6 => 'Sab', 7 => 'Dom'];
const MESI = [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

/** "HH:MM" (o "HH:MM:SS") -> minuti, null se vuoto/non valido. */
function parse_time(?string $t): ?int
{
    if ($t === null || $t === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) {
        return null;
    }
    $h = (int)$m[1];
    $i = (int)$m[2];
    if ($h > 23 || $i > 59) {
        return null;
    }
    return $h * 60 + $i;
}

/** Durata "H:MM" -> minuti (accetta anche ore > 23). */
function parse_duration(?string $t): int
{
    $t = trim((string)$t);
    if ($t === '') {
        return 0;
    }
    if (preg_match('/^(\d{1,3}):(\d{2})$/', $t, $m)) {
        return (int)$m[1] * 60 + min(59, (int)$m[2]);
    }
    if (is_numeric(str_replace(',', '.', $t))) {
        return (int)round((float)str_replace(',', '.', $t) * 60);
    }
    return 0;
}

/** Minuti -> "H:MM" (con segno se $signed). */
function fmt_min(int $min, bool $signed = false): string
{
    $sign = $min < 0 ? '-' : ($signed && $min > 0 ? '+' : '');
    $min = abs($min);
    return $sign . intdiv($min, 60) . ':' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT);
}

/** Minuti -> "HH:MM" per input type=time. */
function fmt_clock(?int $min): string
{
    if ($min === null) {
        return '';
    }
    return str_pad((string)intdiv($min, 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT);
}

function fmt_time_db(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

function fmt_eur(float $v): string
{
    return '€ ' . number_format($v, 2, ',', '.');
}

function fmt_date_it(string $date): string
{
    $d = new DateTimeImmutable($date);
    return GIORNI[(int)$d->format('N')] . ' ' . $d->format('j') . ' ' . MESI[(int)$d->format('n')] . ' ' . $d->format('Y');
}

function month_label(string $ym): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    return MESI[$m] . ' ' . $y;
}

/** Festività nazionali italiane (+ patrono opzionale "MM-DD"). */
function holidays(int $year, string $patrono = ''): array
{
    static $cache = [];
    $key = $year . '|' . $patrono;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $h = [
        "$year-01-01" => 'Capodanno',
        "$year-01-06" => 'Epifania',
        "$year-04-25" => 'Festa della Liberazione',
        "$year-05-01" => 'Festa dei Lavoratori',
        "$year-06-02" => 'Festa della Repubblica',
        "$year-08-15" => 'Ferragosto',
        "$year-11-01" => 'Ognissanti',
        "$year-12-08" => 'Immacolata Concezione',
        "$year-12-25" => 'Natale',
        "$year-12-26" => 'Santo Stefano',
    ];
    if ($year >= 2026) {
        // San Francesco d'Assisi, festività nazionale dal 2026 (L. 151/2025).
        $h["$year-10-04"] = "San Francesco d'Assisi";
    }
    $easter = easter(new DateTimeImmutable("$year-01-01"));
    $h[$easter->format('Y-m-d')] = 'Pasqua';
    $h[$easter->modify('+1 day')->format('Y-m-d')] = "Lunedì dell'Angelo";
    if (preg_match('/^\d{2}-\d{2}$/', $patrono) && valid_date("$year-$patrono")) {
        $h["$year-$patrono"] = $h["$year-$patrono"] ?? 'Santo Patrono';
    }
    return $cache[$key] = $h;
}

/** Data di Pasqua (algoritmo di Meeus/Jones/Butcher). */
function easter(DateTimeImmutable $d): DateTimeImmutable
{
    $y = (int)$d->format('Y');
    $a = $y % 19;
    $b = intdiv($y, 100);
    $c = $y % 100;
    $dd = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $dd - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
}

/** Minuti lavorati tra entrata e uscita (gestisce il passaggio della mezzanotte). */
function interval_minutes(?int $in, ?int $out): int
{
    if ($in === null || $out === null) {
        return 0;
    }
    $diff = $out - $in;
    if ($diff < 0) {
        $diff += 1440;
    }
    return $diff;
}

/**
 * Calcolo di una singola giornata.
 * $row: record della tabella presenze (o null se non inserito).
 */
function calc_day(string $date, ?array $row, array $s): array
{
    $d = new DateTimeImmutable($date);
    $dow = (int)$d->format('N');
    $hol = holidays((int)$d->format('Y'), $s['patrono'])[$date] ?? null;
    $festivo = $hol !== null || $dow === 7;

    $tipo = $row['tipo'] ?? null;
    if ($tipo === null) {
        $tipo = $hol !== null ? 'festivo' : null;
    }

    // Ore previste: da contratto per quel giorno della settimana, zero se festivo.
    $previste = ($hol !== null || $tipo === 'festivo') ? 0 : (int)$s['ore_' . $dow];

    $r = [
        'data' => $date,
        'dow' => $dow,
        'festivo' => $festivo,
        'festivita' => $hol,
        'tipo' => $tipo,
        'row' => $row,
        'previste' => $previste,
        'lavorate' => 0,
        'ordinarie' => 0,
        'straord' => 0,
        'straord_festivo' => 0,
        'giustificate' => 0,
        'mancanti' => 0,
        'pausa' => (int)($row['pausa_min'] ?? 0),
        'permesso_ore' => (int)($row['permesso_min'] ?? 0),
        'aperta' => false,
    ];

    if ($row === null) {
        // Giornata non compilata: se era lavorativa ed è già passata, risultano ore mancanti.
        if ($previste > 0 && $date < date('Y-m-d')) {
            $r['mancanti'] = $previste;
        }
        return $r;
    }

    if (in_array($tipo, TIPI_LAVORO, true)) {
        $e1 = parse_time($row['entrata1'] ?? null);
        $u1 = parse_time($row['uscita1'] ?? null);
        $e2 = parse_time($row['entrata2'] ?? null);
        $u2 = parse_time($row['uscita2'] ?? null);
        $r['aperta'] = ($e1 !== null && $u1 === null) || ($e2 !== null && $u2 === null);

        $lordo = interval_minutes($e1, $u1) + interval_minutes($e2, $u2);
        $lavorate = max(0, $lordo - $r['pausa']);
        $r['lavorate'] = $lavorate;

        $permesso = min($r['permesso_ore'], $previste);
        $r['giustificate'] = $permesso;
        $dovute = max(0, $previste - $permesso);

        if ($festivo) {
            // Domenica o festività: tutto il lavorato è straordinario festivo.
            $extra = $lavorate;
            $r['ordinarie'] = 0;
        } else {
            $r['ordinarie'] = min($lavorate, $dovute);
            $extra = $lavorate - $r['ordinarie'];
        }

        if ($extra > 0 && $s['soglia_straord'] > 0 && $extra < $s['soglia_straord']) {
            $extra = 0;
        }
        if ($extra > 0 && $s['arrotondamento'] > 0) {
            $extra = intdiv($extra, (int)$s['arrotondamento']) * (int)$s['arrotondamento'];
        }
        if ($festivo) {
            $r['straord_festivo'] = $extra;
        } else {
            $r['straord'] = $extra;
        }

        if (!$r['aperta']) {
            $r['mancanti'] = max(0, $dovute - $r['ordinarie']);
        }
    } elseif (in_array($tipo, TIPI_GIUSTIFICATI, true)) {
        $r['giustificate'] = $previste;
    }

    return $r;
}

/** Riepilogo di un mese "YYYY-MM". */
function calc_month(int $uid, string $ym, array $s): array
{
    $first = new DateTimeImmutable($ym . '-01');
    $last = $first->modify('last day of this month');

    $st = db()->prepare('SELECT * FROM presenze WHERE user_id = ? AND data BETWEEN ? AND ? ORDER BY data');
    $st->execute([$uid, $first->format('Y-m-d'), $last->format('Y-m-d')]);
    $rows = [];
    foreach ($st as $row) {
        $rows[$row['data']] = $row;
    }

    $t = [
        'previste' => 0, 'lavorate' => 0, 'ordinarie' => 0, 'straord' => 0, 'straord_festivo' => 0,
        'giustificate' => 0, 'mancanti' => 0, 'previste_ad_oggi' => 0,
        'giorni_lavorati' => 0, 'giorni_ferie' => 0, 'giorni_permesso' => 0, 'giorni_malattia' => 0,
        'giorni_smart' => 0, 'giorni_trasferta' => 0, 'ore_permesso' => 0,
    ];
    $days = [];
    for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        $c = calc_day($date, $rows[$date] ?? null, $s);
        $days[] = $c;
        if ($date <= date('Y-m-d')) {
            $t['previste_ad_oggi'] += $c['previste'];
        }
        foreach (['previste', 'lavorate', 'ordinarie', 'straord', 'straord_festivo', 'giustificate', 'mancanti'] as $k) {
            $t[$k] += $c[$k];
        }
        if (in_array($c['tipo'], TIPI_LAVORO, true) && $c['lavorate'] > 0) {
            $t['giorni_lavorati']++;
        }
        if (in_array($c['tipo'], TIPI_LAVORO, true)) {
            $t['ore_permesso'] += $c['giustificate'];
        }
        match ($c['tipo']) {
            'ferie' => $t['giorni_ferie']++,
            'permesso' => $t['giorni_permesso']++,
            'malattia' => $t['giorni_malattia']++,
            'smart' => $t['giorni_smart']++,
            'trasferta' => $t['giorni_trasferta']++,
            default => null,
        };
    }
    $t['straord_totale'] = $t['straord'] + $t['straord_festivo'];
    // Saldo: (lavorate + giustificate) - previste fino ad oggi (per il mese in corso).
    $t['saldo'] = $t['lavorate'] + $t['giustificate'] - $t['previste_ad_oggi'];

    // Stima economica lorda (solo se è impostata la paga oraria).
    $paga = (float)$s['paga_oraria'];
    $t['importo_ordinario'] = round(($t['ordinarie'] + $t['giustificate']) / 60 * $paga, 2);
    $t['importo_straord'] = round($t['straord'] / 60 * $paga * (1 + $s['magg_straord'] / 100), 2);
    $t['importo_festivo'] = round($t['straord_festivo'] / 60 * $paga * (1 + $s['magg_festivo'] / 100), 2);
    $t['importo_totale'] = $t['importo_ordinario'] + $t['importo_straord'] + $t['importo_festivo'];

    return ['mese' => $ym, 'giorni' => $days, 'totali' => $t];
}
