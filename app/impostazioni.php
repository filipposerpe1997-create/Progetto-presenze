<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];
$s = get_settings($uid);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'settings') {
        $new = $s;
        for ($i = 1; $i <= 7; $i++) {
            $new['ore_' . $i] = max(0, min(1440, parse_duration($_POST['ore_' . $i] ?? '')));
        }
        $new['pausa_default'] = max(0, min(600, (int)($_POST['pausa_default'] ?? 0)));
        $new['soglia_straord'] = max(0, min(600, (int)($_POST['soglia_straord'] ?? 0)));
        $new['arrotondamento'] = in_array((int)($_POST['arrotondamento'] ?? 0), [0, 5, 10, 15, 30, 60], true) ? (int)$_POST['arrotondamento'] : 0;
        $new['paga_oraria'] = max(0, min(9999, (float)str_replace(',', '.', (string)($_POST['paga_oraria'] ?? '0'))));
        $new['magg_straord'] = max(0, min(500, (float)str_replace(',', '.', (string)($_POST['magg_straord'] ?? '0'))));
        $new['magg_festivo'] = max(0, min(500, (float)str_replace(',', '.', (string)($_POST['magg_festivo'] ?? '0'))));
        $patrono = trim((string)($_POST['patrono'] ?? ''));
        if ($patrono !== '' && !(preg_match('/^\d{2}-\d{2}$/', $patrono) && valid_date('2024-' . $patrono))) {
            $errors[] = 'Data del patrono non valida: usa il formato MM-GG (es. 06-24).';
        }
        $new['patrono'] = $patrono;
        $new['azienda'] = mb_substr(trim((string)($_POST['azienda'] ?? '')), 0, 150);
        $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 100);

        if (!$errors) {
            save_settings($uid, $new);
            db()->prepare('UPDATE users SET nome = ? WHERE id = ?')->execute([$nome, $uid]);
            flash('Impostazioni salvate.');
            redirect('impostazioni.php');
        }
        $s = $new;
    } elseif ($action === 'password') {
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $hash = (string)$st->fetchColumn();
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $hash)) {
            $errors[] = 'La password attuale non è corretta.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'La nuova password deve avere almeno 8 caratteri.';
        } elseif ($new !== ($_POST['new2'] ?? '')) {
            $errors[] = 'Le nuove password non coincidono.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            flash('Password aggiornata.');
            redirect('impostazioni.php');
        }
    }
}

page_header('Impostazioni', 'impostazioni');
?>
<?php if ($errors): ?><div class="flash flash-err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings">

  <section class="card">
    <h3>Dati personali</h3>
    <label>Nome e cognome <input name="nome" value="<?= e($user['nome']) ?>" autocomplete="name"></label>
    <label>Azienda <span class="muted">(compare nei report)</span><input name="azienda" value="<?= e($s['azienda']) ?>"></label>
  </section>

  <section class="card">
    <h3>Orario contrattuale</h3>
    <p class="hint">Ore ordinarie previste per ogni giorno (es. 8 o 7:30). Metti 0 nei giorni di riposo. Quello che lavori in più diventa straordinario.</p>
    <div class="week">
      <?php for ($i = 1; $i <= 7; $i++): ?>
        <label><?= e(GIORNI[$i]) ?>
          <input name="ore_<?= $i ?>" inputmode="decimal" value="<?= e(fmt_min($s['ore_' . $i])) ?>">
        </label>
      <?php endfor; ?>
    </div>
    <div class="grid2">
      <label>Pausa predefinita (min) <input type="number" name="pausa_default" min="0" max="600" step="5" value="<?= (int)$s['pausa_default'] ?>"></label>
      <label>Patrono (MM-GG) <input name="patrono" placeholder="es. 06-24" value="<?= e($s['patrono']) ?>"></label>
    </div>
  </section>

  <section class="card">
    <h3>Straordinari</h3>
    <div class="grid2">
      <label>Soglia minima (min)
        <input type="number" name="soglia_straord" min="0" max="600" step="5" value="<?= (int)$s['soglia_straord'] ?>">
      </label>
      <label>Arrotonda per difetto a
        <select name="arrotondamento">
          <?php foreach ([0 => 'Nessun arrotond.', 5 => '5 minuti', 10 => '10 minuti', 15 => '15 minuti', 30 => '30 minuti', 60 => '1 ora'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= (int)$s['arrotondamento'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="hint">Straordinari sotto la soglia minima non vengono conteggiati. Domeniche e festività sono conteggiate come straordinario festivo.</p>
  </section>

  <section class="card">
    <h3>Retribuzione <span class="muted">(facoltativo)</span></h3>
    <p class="hint">Se imposti la paga oraria lorda, vedrai una stima degli importi del mese.</p>
    <div class="grid3">
      <label>Paga oraria € <input name="paga_oraria" inputmode="decimal" value="<?= e(number_format((float)$s['paga_oraria'], 2, ',', '')) ?>"></label>
      <label>Magg. straord. % <input name="magg_straord" inputmode="decimal" value="<?= e((string)(float)$s['magg_straord']) ?>"></label>
      <label>Magg. festivo % <input name="magg_festivo" inputmode="decimal" value="<?= e((string)(float)$s['magg_festivo']) ?>"></label>
    </div>
  </section>

  <button class="btn btn-primary btn-block">Salva impostazioni</button>
</form>

<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="password">
  <section class="card">
    <h3>Cambia password</h3>
    <label>Password attuale <input type="password" name="old" required autocomplete="current-password"></label>
    <label>Nuova password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
    <label>Ripeti nuova password <input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-light btn-block">Aggiorna password</button>
  </section>
</form>

<section class="card install-help">
  <h3>Aggiungi alla schermata Home</h3>
  <p><strong>iPhone (Safari):</strong> tocca <em>Condividi</em> → <em>Aggiungi alla schermata Home</em>.</p>
  <p><strong>Android (Chrome):</strong> menu ⋮ → <em>Installa app</em> / <em>Aggiungi a schermata Home</em>.</p>
  <button type="button" class="btn btn-light btn-block" id="install-btn" hidden>Installa l'app</button>
</section>

<form method="post" action="logout.php">
  <?= csrf_field() ?>
  <button class="btn btn-danger btn-block">Esci</button>
</form>
<p class="muted center">Presenze v<?= e(APP_VERSION) ?></p>
<?php
page_footer('impostazioni');
