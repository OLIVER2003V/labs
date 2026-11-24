#!/usr/bin/env bash
# ======================================================================
#  CONTROL DEL SERVIDOR DESDE LA LAPTOP  (tecnoweb no da SSH: archivos por FTP, base de datos directa)
#  Usa .env.servidor (credenciales; NO está en git).
#
#   bash scripts/servidor.sh verificar          diagnóstico de la BD remota + respuesta del sitio
#   bash scripts/servidor.sh respaldar [nombre]  respaldo de TODOS los datos del servidor (queda en la laptop)
#   bash scripts/servidor.sh reparar            reinstala triggers/funciones/permisos/menú/admin en la BD remota
#   bash scripts/servidor.sh datos [archivo]    restaura los datos desde un respaldo (por defecto el último)
#   bash scripts/servidor.sh tablas [archivo]   si borraron TABLAS: recrea el esquema y carga el respaldo
#   bash scripts/servidor.sh archivos           repone por FTP solo los archivos borrados o modificados (rápido)
#   bash scripts/servidor.sh subir              sube la versión nueva completa (dist/proyecto2_app.tar.gz)
#   bash scripts/servidor.sh todo [archivo]     archivos + reparar + datos  ("botón rojo")
#   bash scripts/servidor.sh lista              respaldos disponibles del servidor
# ======================================================================
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env.servidor ] || { echo "✘ Falta .env.servidor (credenciales del servidor)"; exit 1; }

PHP_BIN="${PHP:-}"
for c in "$PHP_BIN" php "$HOME/.config/herd/bin/php85/php.exe" "$HOME/.config/herd/bin/php84/php.exe"; do
    if [ -n "$c" ] && command -v "$c" >/dev/null 2>&1 && "$c" -m 2>/dev/null | grep -qi pdo_pgsql; then PHP_BIN="$c"; break; fi
done
art() { "$PHP_BIN" artisan "$@" --env=servidor; }
URL="$(grep -E '^APP_URL=' .env.servidor | cut -d= -f2- | tr -d '"')"
export FTP_PASSWORD="${FTP_PASSWORD:-$(grep -E '^DB_PASSWORD=' .env.servidor | cut -d= -f2- | tr -d "'\"")}"
PY="$(command -v python || command -v python3)"
ARCHIVO="${2:-ultimo.json}"

probar() { echo "→ Sitio: $(curl -s -o /dev/null -w '%{http_code}' "$URL/login") (login) · API: $(curl -s "$URL/api/estado")"; }

case "${1:-verificar}" in
    verificar) art sistema:verificar || true; probar ;;
    respaldar) art respaldo:crear ${2:-} ;;
    lista)     art respaldo:listar ;;
    reparar)   art sistema:reparar --admin ;;
    datos)     art respaldo:restaurar "$ARCHIVO" --forzar || art respaldo:restaurar "$ARCHIVO" --reconstruir --forzar; art sistema:reparar ;;
    tablas)    art respaldo:restaurar "$ARCHIVO" --reconstruir --forzar; art sistema:reparar ;;
    archivos)  "$PY" scripts/subir_ftp.py --reparar; probar ;;
    subir)     "$PY" scripts/subir_ftp.py --solo-codigo; probar ;;
    todo)      "$PY" scripts/subir_ftp.py --reparar
               art respaldo:restaurar "$ARCHIVO" --forzar || art respaldo:restaurar "$ARCHIVO" --reconstruir --forzar
               art sistema:reparar --admin; probar ;;
    *) sed -n '2,16p' "$0"; exit 1 ;;
esac
