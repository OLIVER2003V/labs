#!/usr/bin/env bash
# ======================================================================
#  PRIMERA INSTALACIÓN EN EL SERVIDOR (o reinstalación completa)
#  Uso:  bash instalar.sh            (instala y carga datos de demostración)
#        bash instalar.sh --sin-datos (instala solo la estructura y la seguridad)
# ======================================================================
source "$(dirname "$0")/lib.sh"

# Si el script se ejecuta desde fuera del código, primero se extrae el paquete
[ -f "$APP_DIR/artisan" ] || extraer_codigo
asegurar_env
permisos

if ! grep -qE '^APP_KEY=.+' "$APP_DIR/.env"; then
    artisan key:generate --force
    cp "$APP_DIR/.env" "$RESPALDOS/.env"
fi

publicar

amarillo "Creando tablas, funciones y triggers…"
artisan migrate --force
if [ "${1:-}" != "--sin-datos" ] && [ "$(artisan tinker --execute='echo \DB::table("usuarios")->count();' 2>/dev/null | tail -1)" = "0" ]; then
    artisan db:seed --force
    verde "Datos de demostración cargados (contraseña de todos: Ficct2026)"
fi
artisan sistema:reparar
artisan respaldo:crear inicial
cp "$APP_DIR"/storage/app/respaldos/*.json "$RESPALDOS/" 2>/dev/null || true
artisan sistema:verificar || true
probar_web
guardar_scripts
verde "Instalación terminada. Scripts de emergencia copiados en $RESPALDOS/deploy"
