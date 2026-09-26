# Progetto-presenze

Web app in PHP + MySQL per gestire le proprie presenze. Calcola le ore ordinarie e gli straordinari e fa il riepilogo di fine mese, con export in Excel e PDF.
Pensata per il telefono: si installa sulla schermata Home come un'app.

## Funzioni

- **Timbratura rapida**: un pulsante grande *Timbra ENTRATA / USCITA* con l'ora corrente, fino a due turni al giorno. Le ore lavorate si aggiornano in tempo reale.
- **Tipi di giornata**: lavoro, smart working, trasferta, ferie, permesso, malattia, festività, riposo, più i permessi a ore.
- **Calcolo automatico**:
  - ore ordinarie in base all'orario contrattuale di ogni giorno della settimana
  - straordinario feriale (oltre l'orario previsto)
  - straordinario festivo (domeniche, festività nazionali italiane con Pasqua e Pasquetta calcolate, santo patrono)
  - ore giustificate, ore mancanti e saldo del mese
  - soglia minima e arrotondamento degli straordinari
  - turni a cavallo della mezzanotte
- **Riepilogo mensile**: ore previste e lavorate, ordinarie, straordinari, giorni di ferie, malattia e permesso. Se imposti la paga oraria, mostra anche una stima lorda con le maggiorazioni.
- **Export** Excel (.xlsx) e PDF del mese, con il dettaglio giornaliero, i totali, il riepilogo e lo spazio per le firme.
- **App sul telefono** (PWA): icona sulla Home, schermo intero, tema chiaro/scuro, pagina offline.
- **Sicurezza**: password con hash, protezione CSRF, query parametrizzate, sessioni di 30 giorni. Le cartelle interne non sono accessibili dal web.

## Struttura

```
app/                  file pubblicati in /var/www/html/presenze
  index.php           Oggi (timbratura)
  mese.php            riepilogo mensile + export
  giorno.php          modifica di una giornata
  impostazioni.php    orario, straordinari, retribuzione, password
  export.php          Excel / PDF
  install.php         installazione guidata
  includes/           logica (calcoli, layout, xlsx, pdf, schema SQL)
  lib/fpdf/           libreria FPDF
deploy/server-setup.sh  installazione/aggiornamento sulla VPS
.github/workflows/deploy.yml  deploy automatico
```

## Deploy automatico sulla VPS

A ogni push su `main` che modifica l'app, il workflow **Deploy VPS**:
1. carica i file sulla VPS via SSH;
2. al primo avvio installa Apache, PHP e MariaDB se mancano, crea il database `presenze` con una password casuale e scrive `config.php`;
3. copia l'app in `/var/www/html/presenze`, lasciando intatti `config.php` e le sessioni.

Secret da configurare in *Settings → Secrets and variables → Actions*:

| Secret | Valore |
| --- | --- |
| `VPS_HOST` | indirizzo della VPS |
| `VPS_USER` | utente SSH: root, oppure un utente con `sudo` senza password |
| `VPS_SSH_KEY` | chiave privata SSH autorizzata sulla VPS |
| `VPS_PORT` | facoltativo, default `22` |

Si può avviare anche a mano da *Actions → Deploy VPS → Run workflow*.

### Installazione manuale (senza GitHub Actions)

```bash
git clone https://github.com/filipposerpe1997-create/Progetto-presenze.git
sudo bash Progetto-presenze/deploy/server-setup.sh
```

## Primo accesso

1. Apri `http://<IP-della-VPS>/presenze/`.
2. Crea il tuo account (nome, username, password).
3. In **Impostazioni** imposta l'orario contrattuale, il patrono e, se vuoi, la paga oraria.

## Installare l'app sul telefono

- **iPhone (Safari)**: *Condividi* → *Aggiungi alla schermata Home*.
- **Android (Chrome)**: menu ⋮ → *Installa app* / *Aggiungi a schermata Home*.

Per le funzioni PWA complete (service worker, installazione nativa su Android) serve **HTTPS**, quindi un dominio che punti alla VPS. Con Apache:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d presenze.tuodominio.it
```

## Requisiti

PHP 8.1+ con `pdo_mysql`, `mbstring` e `zip`, MySQL 5.7+ o MariaDB 10.3+, Apache con `.htaccess` abilitati. Lo script di setup installa tutto da solo.
