<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];
$s = get_settings($uid);
$today = date('Y-m-d');

// Timbratura rapida: riempie il primo campo libero tra entrata1, uscita1, entrata2, uscita2.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'timbra') {
    csrf_check();
    $now = date('H:i:00');
    $row = get_presenza($uid, $today);
    if (!$row) {
        db()->prepare('INSERT INTO presenze (user_id, data, tipo, entrata1, pausa_min) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, $today, 'lavoro', $now, $s['pausa_default']]);
        flash('Entrata registrata alle ' . substr($now, 0, 5));
    } else {
        $slot = null;
        foreach (['entrata1', 'uscita1', 'entrata2', 'uscita2'] as $f) {
            if (empty($row[$f])) {
                $slot = $f;
                break;
            }
        }
        if ($slot === null) {
            flash('Hai già registrato tutte le timbrature di oggi. Modifica la giornata per correggerle.', 'err');
        } else {
            $sql = "UPDATE presenze SET $slot = ?";
            $params = [$now];
            if (!in_array($row['tipo'], TIPI_LAVORO, true)) {
                $sql .= ', tipo = ?';
                $params[] = 'lavoro';
            }
            if ($slot === 'entrata2') {
                // Con due turni la pausa è l'intervallo tra uscita e rientro.
                $sql .= ', pausa_min = 0';
            }
            $sql .= ' WHERE id = ?';
            $params[] = $row['id'];
            db()->prepare($sql)->execute($params);
            flash((str_starts_with($slot, 'entrata') ? 'Entrata' : 'Uscita') . ' registrata alle ' . substr($now, 0, 5));
        }
    }
    redirect('index.php');
}

$row = get_presenza($uid, $today);
$day = calc_day($today, $row, $s);
$month = calc_month($uid, date('Y-m'), $s);
$t = $month['totali'];

$next = 'entrata1';
if ($row) {
    $next = null;
    foreach (['entrata1', 'uscita1', 'entrata2', 'uscita2'] as $f) {
        if (empty($row[$f])) {
            $next = $f;
            break;
        }
    }
}
$isEntrata = $next !== null && str_starts_with($next, 'entrata');

// Minuti lavorati finora (anche con turno ancora aperto) per il contatore live.
$liveStart = null;
if ($row && $day['aperta']) {
    $open = !empty($row['entrata2']) ? $row['entrata2'] : $row['entrata1'];
    $liveStart = strtotime($today . ' ' . $open);
}
$closedMin = 0;
if ($row) {
    $closedMin = interval_minutes(parse_time($row['entrata1']), parse_time($row['uscita1']))
        + interval_minutes(parse_time($row['entrata2']), parse_time($row['uscita2']));
}

page_header('Oggi', 'oggi');
?>
<section class="card hero">
  <div class="hero-date"><?= e(fmt_date_it($today)) ?></div>
  <div class="hero-clock" id="clock"><?= date('H:i') ?></div>
  <?php if ($day['festivita']): ?><div class="badge badge-festivo"><?= e($day['festivita']) ?></div><?php endif; ?>
  <div class="hero-sub">
    Previste oggi: <strong><?= fmt_min($day['previste']) ?></strong>
    <?php if ($row): ?> · Tipo: <?= badge_tipo($row['tipo']) ?><?php endif; ?>
  </div>

  <form method="post" class="timbra-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="timbra">
    <?php if ($next === null): ?>
      <button class="btn btn-timbra btn-done" disabled>Giornata completata</button>
    <?php else: ?>
      <button class="btn btn-timbra <?= $isEntrata ? 'btn-in' : 'btn-out' ?>">
        <?= $isEntrata ? 'Timbra ENTRATA' : 'Timbra USCITA' ?>
      </button>
    <?php endif; ?>
  </form>

  <?php if ($row && in_array($row['tipo'], TIPI_LAVORO, true)): ?>
  <div class="timbrature">
    <div><span>Entrata</span><strong><?= e(fmt_time_db($row['entrata1']) ?: '--:--') ?></strong></div>
    <div><span>Uscita</span><strong><?= e(fmt_time_db($row['uscita1']) ?: '--:--') ?></strong></div>
    <div><span>Entrata</span><strong><?= e(fmt_time_db($row['entrata2']) ?: '--:--') ?></strong></div>
    <div><span>Uscita</span><strong><?= e(fmt_time_db($row['uscita2']) ?: '--:--') ?></strong></div>
  </div>
  <div class="hero-worked">
    Lavorate: <strong id="live" data-start="<?= $liveStart ?? '' ?>" data-closed="<?= $closedMin ?>" data-pausa="<?= $day['pausa'] ?>"><?= fmt_min($day['lavorate']) ?></strong>
    <?php if ($day['pausa']): ?><span class="muted">(pausa <?= fmt_min($day['pausa']) ?>)</span><?php endif; ?>
    <?php if ($day['straord'] + $day['straord_festivo'] > 0): ?>
      · Straord.: <strong class="pos"><?= fmt_min($day['straord'] + $day['straord_festivo']) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <a class="btn btn-light btn-block" href="giorno.php?d=<?= e($today) ?>">Modifica giornata / assenza</a>
</section>

<h2 class="section-title"><?= e(month_label(date('Y-m'))) ?></h2>
<section class="stats">
  <div class="stat"><span>Lavorate</span><strong><?= fmt_min($t['lavorate']) ?></strong></div>
  <div class="stat"><span>Ordinarie</span><strong><?= fmt_min($t['ordinarie']) ?></strong></div>
  <div class="stat"><span>Straordinari</span><strong class="pos"><?= fmt_min($t['straord_totale']) ?></strong></div>
  <div class="stat"><span>Saldo mese</span><strong class="<?= $t['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= fmt_min($t['saldo'], true) ?></strong></div>
</section>
<a class="btn btn-light btn-block" href="mese.php">Vedi il mese completo</a>
<?php
page_footer('oggi');
