# Progetto-presenze

## Setup VPS

Il workflow `.github/workflows/vps-setup.yml` crea la cartella `/var/www/html/presenze` sulla VPS via SSH.

Secret da configurare in *Settings → Secrets and variables → Actions*:

- `VPS_HOST` – indirizzo della VPS
- `VPS_USER` – utente SSH (root, oppure un utente con `sudo` senza password)
- `VPS_SSH_KEY` – chiave privata SSH autorizzata sulla VPS
- `VPS_PORT` – opzionale, default `22`

Si avvia da *Actions → VPS setup → Run workflow*, oppure automaticamente al merge su `main`.
