#!/usr/bin/env bash
# Installa/aggiorna l'app Presenze sulla VPS (Ubuntu/Debian).
# Idempotente: si può eseguire a ogni deploy.
#   - installa Apache, PHP e MariaDB se mancano
#   - crea database e utente MySQL al primo avvio (password casuale) e scrive config.php
#   - copia i file dell'app in /var/www/html/presenze (config e sessioni restano intatti)
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/app"
TARGET="${TARGET:-/var/www/html/presenze}"
DB_NAME="${DB_NAME:-presenze}"
DB_USER="${DB_USER:-presenze}"

SUDO=""
if [ "$(id -u)" -ne 0 ]; then SUDO="sudo -n"; fi

log() { echo "==> $*"; }

[ -d "$SRC_DIR" ] || { echo "Cartella app non trovata: $SRC_DIR" >&2; exit 1; }

# 1) Pacchetti
export DEBIAN_FRONTEND=noninteractive
need=()
command -v php >/dev/null 2>&1 || need+=(php)
command -v rsync >/dev/null 2>&1 || need+=(rsync)
command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1 || need+=(mariadb-server)
for ext in pdo_mysql mbstring zip; do
  if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
    case $ext in
      pdo_mysql) need+=(php-mysql) ;;
      *) need+=("php-$ext") ;;
    esac
  fi
done
WEB="apache"
if ! command -v apache2 >/dev/null 2>&1; then
  if systemctl is-active --quiet nginx 2>/dev/null; then
    WEB="nginx"
    command -v php-fpm >/dev/null 2>&1 || ls /usr/sbin/php-fpm* >/dev/null 2>&1 || need+=(php-fpm)
  else
    need+=(apache2 libapache2-mod-php)
  fi
elif ! ls /etc/apache2/mods-available/php*.load >/dev/null 2>&1; then
  need+=(libapache2-mod-php)
fi
if [ ${#need[@]} -gt 0 ]; then
  log "Installo pacchetti: ${need[*]}"
  $SUDO apt-get update -q
  $SUDO apt-get install -y -q "${need[@]}"
fi

# 2) Database e config.php (solo la prima volta)
MYSQL="$SUDO mysql"
command -v mysql >/dev/null 2>&1 || MYSQL="$SUDO mariadb"
$SUDO systemctl enable --now mariadb >/dev/null 2>&1 || $SUDO systemctl enable --now mysql >/dev/null 2>&1 || true

$SUDO mkdir -p "$TARGET"
if [ ! -f "$TARGET/config.php" ]; then
  log "Creo database '$DB_NAME' e utente '$DB_USER'"
  DB_PASS="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
  $MYSQL <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  $MYSQL "$DB_NAME" < "$SRC_DIR/includes/schema.sql"
  $SUDO tee "$TARGET/config.php" >/dev/null <<PHP
<?php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'pass' => '$DB_PASS',
    ],
];
PHP
else
  log "config.php già presente: aggiorno solo lo schema se serve"
  CFG_NAME=$($SUDO php -r "\$c=require '$TARGET/config.php'; echo \$c['db']['name'];")
  $MYSQL "$CFG_NAME" < "$SRC_DIR/includes/schema.sql" || true
fi

# 3) File dell'app
log "Copio i file in $TARGET"
$SUDO rsync -a --delete \
  --exclude 'config.php' \
  --exclude 'data/sessions/*' \
  "$SRC_DIR/" "$TARGET/"
$SUDO mkdir -p "$TARGET/data/sessions"
$SUDO chown -R www-data:www-data "$TARGET"
$SUDO find "$TARGET" -type d -exec chmod 755 {} +
$SUDO find "$TARGET" -type f -exec chmod 644 {} +
$SUDO chmod 640 "$TARGET/config.php"
$SUDO chmod 700 "$TARGET/data/sessions"

# 4) Web server
if [ "$WEB" = "apache" ]; then
  CONF=/etc/apache2/conf-available/presenze.conf
  if [ ! -f "$CONF" ]; then
    log "Configuro Apache (AllowOverride per .htaccess)"
    $SUDO tee "$CONF" >/dev/null <<APACHE
<Directory $TARGET>
    AllowOverride All
    Require all granted
</Directory>
APACHE
    $SUDO a2enconf presenze >/dev/null
  fi
  $SUDO a2enmod headers >/dev/null 2>&1 || true
  $SUDO systemctl enable --now apache2 >/dev/null 2>&1 || true
  $SUDO systemctl reload apache2 || $SUDO systemctl restart apache2
else
  log "ATTENZIONE: rilevato nginx. Assicurati che PHP-FPM sia configurato per $TARGET"
  log "e che l'accesso a includes/, lib/, data/ e config.php sia negato."
fi

log "Fatto. Apri http://<IP-o-dominio>/presenze/ (al primo accesso crei l'account)."
