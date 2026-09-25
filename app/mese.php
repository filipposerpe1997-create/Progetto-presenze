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
$first = new DateTimeImmutable($ym . '-01');
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');

$month = calc_month($uid, $ym, $s);
$t = $month['totali'];
$today = date('Y-m-d');

page_header('Mese', 'mese');
?>
<div class="month-nav">
  <a class="btn btn-icon" href="mese.php?m=<?= e($prev) ?>" aria-label="Mese precedente"><?= icon('left') ?></a>
  <form method="get" class="month-pick">
    <input type="month" name="m" value="<?= e($ym) ?>" onchange="this.form.submit()" aria-label="Scegli mese">
    <strong><?= e(month_label($ym)) ?></strong>
  </form>
  <a class="btn btn-icon" href="mese.php?m=<?= e($next) ?>" aria-label="Mese successivo"><?= icon('right') ?></a>
</div>

<section class="stats">
  <div class="stat"><span>Ore previste</span><strong><?= fmt_min($t['previste']) ?></strong></div>
  <div class="stat"><span>Ore lavorate</span><strong><?= fmt_min($t['lavorate']) ?></strong></div>
  <div class="stat"><span>Ordinarie</span><strong><?= fmt_min($t['ordinarie']) ?></strong></div>
  <div class="stat"><span>Straord. feriali</span><strong class="pos"><?= fmt_min($t['straord']) ?></strong></div>
  <div class="stat"><span>Straord. festivi</span><strong class="pos"><?= fmt_min($t['straord_festivo']) ?></strong></div>
  <div class="stat"><span>Giustificate</span><strong><?= fmt_min($t['giustificate']) ?></strong></div>
  <div class="stat"><span>Ore mancanti</span><strong class="<?= $t['mancanti'] ? 'neg' : '' ?>"><?= fmt_min($t['mancanti']) ?></strong></div>
  <div class="stat"><span>Saldo</span><strong class="<?= $t['saldo'] < 0 ? 'neg' : 'pos' ?>"><?= fmt_min($t['saldo'], true) ?></strong></div>
</section>

<section class="card chips">
  <span>Giorni lavorati <strong><?= $t['giorni_lavorati'] ?></strong></span>
  <span>Ferie <strong><?= $t['giorni_ferie'] ?></strong></span>
  <span>Permessi <strong><?= $t['giorni_permesso'] ?></strong><?= $t['ore_permesso'] ? ' + ' . fmt_min($t['ore_permesso']) . 'h' : '' ?></span>
  <span>Malattia <strong><?= $t['giorni_malattia'] ?></strong></span>
  <span>Smart <strong><?= $t['giorni_smart'] ?></strong></span>
  <span>Trasferta <strong><?= $t['giorni_trasferta'] ?></strong></span>
</section>

<?php if ($s['paga_oraria'] > 0): ?>
<section class="card money">
  <h3>Stima lorda del mese</h3>
  <div class="row"><span>Ordinario + giustificate</span><strong><?= fmt_eur($t['importo_ordinario']) ?></strong></div>
  <div class="row"><span>Straordinario feriale (+<?= e((string)(float)$s['magg_straord']) ?>%)</span><strong><?= fmt_eur($t['importo_straord']) ?></strong></div>
  <div class="row"><span>Straordinario festivo (+<?= e((string)(float)$s['magg_festivo']) ?>%)</span><strong><?= fmt_eur($t['importo_festivo']) ?></strong></div>
  <div class="row total"><span>Totale stimato</span><strong><?= fmt_eur($t['importo_totale']) ?></strong></div>
</section>
<?php endif; ?>

<div class="export">
  <a class="btn btn-excel" href="export.php?f=xlsx&amp;m=<?= e($ym) ?>"><?= icon('xlsx') ?> Excel</a>
  <a class="btn btn-pdf" href="export.php?f=pdf&amp;m=<?= e($ym) ?>"><?= icon('pdf') ?> PDF</a>
</div>

<section class="days">
<?php foreach ($month['giorni'] as $d):
    $row = $d['row'];
    $cls = ['day'];
    if ($d['festivo']) $cls[] = 'is-festivo';
    if ($d['data'] === $today) $cls[] = 'is-today';
    if ($d['mancanti'] > 0) $cls[] = 'is-missing';
    $dt = new DateTimeImmutable($d['data']);
?>
  <a class="<?= implode(' ', $cls) ?>" href="giorno.php?from=mese&amp;d=<?= e($d['data']) ?>">
    <div class="day-date">
      <span class="day-num"><?= $dt->format('j') ?></span>
      <span class="day-dow"><?= GIORNI_BREVI[$d['dow']] ?></span>
    </div>
    <div class="day-body">
      <div class="day-top">
        <?= badge_tipo($d['tipo']) ?>
        <?php if ($d['festivita'] && $d['tipo'] !== 'festivo'): ?><span class="badge badge-festivo"><?= e($d['festivita']) ?></span><?php endif; ?>
        <?php if ($d['aperta']): ?><span class="badge badge-open">In corso</span><?php endif; ?>
      </div>
      <?php if ($row && in_array($row['tipo'], TIPI_LAVORO, true)): ?>
        <div class="day-times">
          <?= e(fmt_time_db($row['entrata1']) ?: '--:--') ?>–<?= e(fmt_time_db($row['uscita1']) ?: '--:--') ?>
          <?php if ($row['entrata2'] || $row['uscita2']): ?>
            · <?= e(fmt_time_db($row['entrata2']) ?: '--:--') ?>–<?= e(fmt_time_db($row['uscita2']) ?: '--:--') ?>
          <?php endif; ?>
          <?php if ($d['pausa']): ?><span class="muted"> · pausa <?= fmt_min($d['pausa']) ?></span><?php endif; ?>
        </div>
      <?php elseif (!$row && $d['previste'] > 0): ?>
        <div class="day-times muted"><?= $d['mancanti'] ? 'Non compilato' : 'Previste ' . fmt_min($d['previste']) ?></div>
      <?php endif; ?>
      <?php if ($row && $row['note'] !== ''): ?><div class="day-note"><?= e($row['note']) ?></div><?php endif; ?>
    </div>
    <div class="day-hours">
      <?php if ($d['lavorate'] > 0): ?><strong><?= fmt_min($d['lavorate']) ?></strong><?php endif; ?>
      <?php if ($d['straord'] + $d['straord_festivo'] > 0): ?><span class="pos">+<?= fmt_min($d['straord'] + $d['straord_festivo']) ?></span><?php endif; ?>
      <?php if ($d['mancanti'] > 0): ?><span class="neg">-<?= fmt_min($d['mancanti']) ?></span><?php endif; ?>
      <?php if ($d['giustificate'] > 0 && $d['lavorate'] === 0): ?><span class="muted"><?= fmt_min($d['giustificate']) ?></span><?php endif; ?>
    </div>
  </a>
<?php endforeach; ?>
</section>
<?php
page_footer('mese');
