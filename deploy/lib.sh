#!/usr/bin/env bash
# Funciones compartidas por instalar.sh, restaurar.sh y respaldar.sh
set -euo pipefail
DIR_SCRIPTS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "$DIR_SCRIPTS/config.sh"

verde() { printf '\033[32m✔ %s\033[0m\n' "$*"; }
amarillo() { printf '\033[33m→ %s\033[0m\n' "$*"; }
rojo() { printf '\033[31m✘ %s\033[0m\n' "$*" >&2; }
artisan() { (cd "$APP_DIR" && "$PHP" artisan "$@"); }

# Extrae el paquete sobre APP_DIR (no toca .env ni storage/app/respaldos, que no vienen en el paquete)
extraer_codigo() {
    local paquete="$PAQUETE"
    [ -f "$paquete" ] || paquete="$RESPALDOS/proyecto2_app.tar.gz"
    [ -f "$paquete" ] || { rojo "No encuentro el paquete ($PAQUETE ni $RESPALDOS/proyecto2_app.tar.gz)"; exit 1; }
    mkdir -p "$APP_DIR"
    tar -xzf "$paquete" -C "$APP_DIR"
    verde "Código extraído en $APP_DIR (desde $paquete)"
    # Copia de seguridad del paquete en la carpeta de respaldos
    mkdir -p "$RESPALDOS"
    [ "$paquete" -ef "$RESPALDOS/proyecto2_app.tar.gz" ] || cp "$paquete" "$RESPALDOS/proyecto2_app.tar.gz"
}

# Recupera el .env desde la copia de seguridad si fue borrado
asegurar_env() {
    if [ ! -f "$APP_DIR/.env" ]; then
        if [ -f "$RESPALDOS/.env" ]; then
            cp "$RESPALDOS/.env" "$APP_DIR/.env"
            verde ".env restaurado desde $RESPALDOS/.env"
        else
            cp "$APP_DIR/deploy/env.produccion.ejemplo" "$APP_DIR/.env"
            rojo "No había .env. Se copió la plantilla: edite $APP_DIR/.env (DB_PASSWORD) y vuelva a ejecutar."
            exit 1
        fi
    fi
    mkdir -p "$RESPALDOS"
    cp "$APP_DIR/.env" "$RESPALDOS/.env"
}

# Permisos de escritura para el servidor web
permisos() {
    mkdir -p "$APP_DIR"/storage/framework/{cache/data,sessions,views} "$APP_DIR"/storage/logs "$APP_DIR"/storage/app/respaldos "$APP_DIR"/bootstrap/cache
    chmod -R 777 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    verde "Permisos de storage/ y bootstrap/cache/"
}

# Copia la parte pública (build, .htaccess, favicon) a la carpeta web y genera su index.php
publicar() {
    mkdir -p "$WEB_DIR"
    rm -rf "$WEB_DIR/build"
    cp -R "$APP_DIR/public/." "$WEB_DIR/"
    sed "s#__APP_DIR__#$APP_DIR#" "$APP_DIR/deploy/index.produccion.php" > "$WEB_DIR/index.php"
    # Si el código quedó dentro de la carpeta web, se bloquea el acceso HTTP a esa carpeta
    case "$APP_DIR" in
        "$WEB_DIR"/*) printf 'Require all denied\nDeny from all\n' > "$APP_DIR/.htaccess"; amarillo "APP_DIR está dentro de la web: se protegió con .htaccess" ;;
    esac
    verde "Carpeta pública actualizada: $WEB_DIR"
}

# Comprueba que el sitio responde
probar_web() {
    local url
    url="$(grep -E '^APP_URL=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
    if command -v curl >/dev/null 2>&1 && [ -n "$url" ]; then
        local codigo
        codigo="$(curl -sk -o /dev/null -w '%{http_code}' "$url/api/estado" || true)"
        [ "$codigo" = "200" ] && verde "El sitio responde: $url (HTTP 200)" || amarillo "El sitio respondió HTTP $codigo en $url/api/estado — revise $APP_DIR/storage/logs"
    fi
}

# Copia los scripts de despliegue fuera de la app (por si borran toda la carpeta de la aplicación)
guardar_scripts() {
    mkdir -p "$RESPALDOS"
    rm -rf "$RESPALDOS/deploy"
    cp -R "$APP_DIR/deploy" "$RESPALDOS/deploy"
}
