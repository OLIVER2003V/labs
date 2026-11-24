#!/usr/bin/env bash
# ======================================================================
#  RESPALDO: datos (JSON) + .env + paquete de código, fuera de la carpeta de la app.
#   bash respaldar.sh              → respaldo con fecha
#   bash respaldar.sh antes_defensa → respaldo con nombre
#
#  Automático cada 15 minutos (durante la defensa), con crontab -e:
#   */15 * * * * bash $HOME/proyecto2_app/deploy/respaldar.sh >> $HOME/respaldos_proyecto2/cron.log 2>&1
# ======================================================================
source "$(dirname "$0")/lib.sh"
mkdir -p "$RESPALDOS"

if [ -n "${1:-}" ]; then artisan respaldo:crear "$1"; else artisan respaldo:crear; fi
cp "$APP_DIR"/storage/app/respaldos/*.json "$RESPALDOS/"
cp "$APP_DIR/.env" "$RESPALDOS/.env"
[ -f "$PAQUETE" ] && [ ! -f "$RESPALDOS/proyecto2_app.tar.gz" ] && cp "$PAQUETE" "$RESPALDOS/"

# Copia adicional con pg_dump si está disponible (formato propio de PostgreSQL)
if command -v pg_dump >/dev/null 2>&1; then
    set -a; source "$APP_DIR/.env"; set +a
    PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -n "${DB_SCHEMA:-public}" -Fc \
        -f "$RESPALDOS/pgdump_$(date +%Y%m%d_%H%M%S).dump" "$DB_DATABASE" && verde "pg_dump del esquema ${DB_SCHEMA:-public} guardado"
fi

# Conserva solo los 40 respaldos más recientes
ls -1t "$RESPALDOS"/respaldo_*.json 2>/dev/null | tail -n +41 | xargs -r rm -f
guardar_scripts
verde "Respaldo guardado en $RESPALDOS"
