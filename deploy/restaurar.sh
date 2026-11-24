#!/usr/bin/env bash
# ======================================================================
#  RESTAURACIÓN DE EMERGENCIA  (ver docs/RECUPERACION.md)
#
#   bash restaurar.sh verificar   → solo diagnostica (no cambia nada)
#   bash restaurar.sh codigo      → borraron/modificaron archivos: vuelve a poner el código y la carpeta pública
#   bash restaurar.sh reparar     → borraron triggers, funciones, permisos, menú o desactivaron al admin
#   bash restaurar.sh datos       → borraron/duplicaron/modificaron datos: vuelve al último respaldo
#   bash restaurar.sh tablas      → borraron TABLAS: recrea el esquema y carga el último respaldo
#   bash restaurar.sh todo        → código + reparar + datos (la opción "botón rojo")
#   bash restaurar.sh semilla     → empezar de cero con los datos de demostración
#
#  Opcional: un archivo de respaldo específico como 2º parámetro, ej:
#   bash restaurar.sh datos respaldo_20261127_090000.json
# ======================================================================
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/lib.sh"
ACCION="${1:-verificar}"
ARCHIVO="${2:-ultimo.json}"

# Si el respaldo pedido no está en la app (p. ej. borraron storage/), se busca en la carpeta de respaldos
traer_respaldo() {
    mkdir -p "$APP_DIR/storage/app/respaldos"
    if [ ! -f "$APP_DIR/storage/app/respaldos/$ARCHIVO" ] && [ -f "$RESPALDOS/$ARCHIVO" ]; then
        cp "$RESPALDOS/$ARCHIVO" "$APP_DIR/storage/app/respaldos/$ARCHIVO"
        verde "Respaldo $ARCHIVO recuperado desde $RESPALDOS"
    fi
}

codigo() {
    extraer_codigo
    asegurar_env
    permisos
    publicar
}

case "$ACCION" in
    verificar)
        artisan sistema:verificar ;;
    codigo)
        codigo
        artisan sistema:reparar ;;
    reparar)
        asegurar_env; permisos
        artisan sistema:reparar --admin ;;
    datos)
        traer_respaldo
        artisan respaldo:restaurar "$ARCHIVO" --forzar || artisan respaldo:restaurar "$ARCHIVO" --reconstruir --forzar
        artisan sistema:reparar ;;
    tablas)
        traer_respaldo
        artisan respaldo:restaurar "$ARCHIVO" --reconstruir --forzar
        artisan sistema:reparar ;;
    todo)
        codigo
        traer_respaldo
        artisan respaldo:restaurar "$ARCHIVO" --forzar || artisan respaldo:restaurar "$ARCHIVO" --reconstruir --forzar
        artisan sistema:reparar --admin ;;
    semilla)
        asegurar_env
        artisan migrate:fresh --seed --force
        artisan sistema:reparar ;;
    *)
        rojo "Acción desconocida: $ACCION"; sed -n '2,16p' "$0"; exit 1 ;;
esac

if [ "$ACCION" != "verificar" ]; then
    artisan sistema:verificar || true
    probar_web
fi
