<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];
$s = get_settings($uid);

$date = (string)($_GET['d'] ?? date('Y-m-d'));
if (!valid_date($date)) {
    $date = date('Y-m-d');
}
$backUrl = $date === date('Y-m-d') && ($_GET['from'] ?? '') !== 'mese'
    ? 'index.php'
    : 'mese.php?m=' . substr($date, 0, 7);
$errors = [];
$row = get_presenza($uid, $date);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM presenze WHERE user_id = ? AND data = ?')->execute([$uid, $date]);
        flash('Giornata del ' . date('d/m/Y', strtotime($date)) . ' cancellata.');
        redirect($backUrl);
    }

    $tipo = (string)($_POST['tipo'] ?? 'lavoro');
    if (!isset(TIPI[$tipo])) {
        $errors[] = 'Tipo di giornata non valido.';
    }
    $times = [];
    foreach (['entrata1', 'uscita1', 'entrata2', 'uscita2'] as $f) {
        $v = trim((string)($_POST[$f] ?? ''));
        if ($v !== '' && parse_time($v) === null) {
            $errors[] = 'Orario non valido: ' . $v;
        }
        $times[$f] = $v === '' ? null : fmt_clock(parse_time($v)) . ':00';
    }
    $isWork = in_array($tipo, TIPI_LAVORO, true);
    if ($isWork) {
        if ($times['uscita1'] && !$times['entrata1']) {
            $errors[] = 'Manca l\'orario di entrata del primo turno.';
        }
        if ($times['uscita2'] && !$times['entrata2']) {
            $errors[] = 'Manca l\'orario di entrata del secondo turno.';
        }
        if ($times['entrata2'] && !$times['uscita1']) {
            $errors[] = 'Per il secondo turno serve prima l\'uscita del primo.';
        }
    } else {
        $times = array_fill_keys(array_keys($times), null);
    }
    $pausa = $isWork ? max(0, min(600, (int)($_POST['pausa_min'] ?? 0))) : 0;
    $permesso = $isWork ? max(0, min(1440, parse_duration($_POST['permesso'] ?? ''))) : 0;
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);

    if (!$errors) {
        db()->prepare(
            'INSERT INTO presenze (user_id, data, tipo, entrata1, uscita1, entrata2, uscita2, pausa_min, permesso_min, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), entrata1 = VALUES(entrata1), uscita1 = VALUES(uscita1),
               entrata2 = VALUES(entrata2), uscita2 = VALUES(uscita2), pausa_min = VALUES(pausa_min),
               permesso_min = VALUES(permesso_min), note = VALUES(note)'
        )->execute([$uid, $date, $tipo, $times['entrata1'], $times['uscita1'], $times['entrata2'], $times['uscita2'], $pausa, $permesso, $note]);
        flash('Giornata salvata.');
        redirect($backUrl);
    }
    // In caso di errore ripropone i valori inseriti.
    $row = array_merge($row ?? [], $times, ['tipo' => $tipo, 'pausa_min' => $pausa, 'permesso_min' => $permesso, 'note' => $note]);
}

$calc = calc_day($date, get_presenza($uid, $date), $s);
$form = $row ?? [
    'tipo' => $calc['festivita'] ? 'festivo' : ($calc['previste'] > 0 ? 'lavoro' : 'riposo'),
    'entrata1' => null, 'uscita1' => null, 'entrata2' => null, 'uscita2' => null,
    'pausa_min' => $s['pausa_default'], 'permesso_min' => 0, 'note' => '',
];

page_header(date('d/m/Y', strtotime($date)), 'mese', $backUrl);
?>
<section class="card">
  <div class="day-head">
    <strong><?= e(fmt_date_it($date)) ?></strong>
    <?php if ($calc['festivita']): ?><span class="badge badge-festivo"><?= e($calc['festivita']) ?></span><?php endif; ?>
    <span class="muted">Previste: <?= fmt_min($calc['previste']) ?></span>
  </div>

  <?php if ($errors): ?>
    <div class="flash flash-err"><?= implode('<br>', array_map('e', $errors)) ?></div>
  <?php endif; ?>

  <form method="post" class="form" id="day-form">
    <?= csrf_field() ?>
    <label>Tipo giornata
      <select name="tipo" id="tipo">
        <?php foreach (TIPI as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $form['tipo'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="work-fields" data-work="<?= e(implode(',', TIPI_LAVORO)) ?>">
      <fieldset>
        <legend>1° turno</legend>
        <div class="grid2">
          <label>Entrata <span class="time-wrap"><input type="time" name="entrata1" value="<?= e(fmt_time_db($form['entrata1'])) ?>"><button type="button" class="now" data-now>Ora</button></span></label>
          <label>Uscita <span class="time-wrap"><input type="time" name="uscita1" value="<?= e(fmt_time_db($form['uscita1'])) ?>"><button type="button" class="now" data-now>Ora</button></span></label>
        </div>
      </fieldset>
      <fieldset>
        <legend>2° turno <span class="muted">(facoltativo)</span></legend>
        <div class="grid2">
          <label>Entrata <span class="time-wrap"><input type="time" name="entrata2" value="<?= e(fmt_time_db($form['entrata2'])) ?>"><button type="button" class="now" data-now>Ora</button></span></label>
          <label>Uscita <span class="time-wrap"><input type="time" name="uscita2" value="<?= e(fmt_time_db($form['uscita2'])) ?>"><button type="button" class="now" data-now>Ora</button></span></label>
        </div>
      </fieldset>
      <div class="grid2">
        <label>Pausa (minuti)
          <input type="number" name="pausa_min" min="0" max="600" step="5" inputmode="numeric" value="<?= (int)$form['pausa_min'] ?>">
        </label>
        <label>Permesso a ore
          <input type="text" name="permesso" inputmode="decimal" placeholder="es. 2 o 1:30" value="<?= $form['permesso_min'] ? e(fmt_min((int)$form['permesso_min'])) : '' ?>">
        </label>
      </div>
      <p class="hint">La pausa si sottrae alle ore lavorate. Se usi due turni, di solito la pausa è 0 (è già l'intervallo tra i turni).</p>
    </div>

    <label>Note
      <textarea name="note" rows="2" maxlength="500" placeholder="Es. cliente, commessa, motivo straordinario…"><?= e($form['note']) ?></textarea>
    </label>

    <button class="btn btn-primary btn-block">Salva</button>
  </form>

  <?php if (get_presenza($uid, $date)): ?>
    <form method="post" onsubmit="return confirm('Cancellare questa giornata?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-danger btn-block">Cancella giornata</button>
    </form>
  <?php endif; ?>
</section>

<?php if (get_presenza($uid, $date)): ?>
<section class="stats">
  <div class="stat"><span>Lavorate</span><strong><?= fmt_min($calc['lavorate']) ?></strong></div>
  <div class="stat"><span>Ordinarie</span><strong><?= fmt_min($calc['ordinarie']) ?></strong></div>
  <div class="stat"><span>Straordinario</span><strong class="pos"><?= fmt_min($calc['straord'] + $calc['straord_festivo']) ?></strong></div>
  <div class="stat"><span>Mancanti</span><strong class="<?= $calc['mancanti'] ? 'neg' : '' ?>"><?= fmt_min($calc['mancanti']) ?></strong></div>
</section>
<?php endif; ?>
<?php
page_footer('mese');
