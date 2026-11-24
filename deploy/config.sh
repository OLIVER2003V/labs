#!/usr/bin/env bash
# ======================================================================
#  CONFIGURACIÓN DEL SERVIDOR  —  AJUSTAR ESTAS RUTAS UNA SOLA VEZ
# ======================================================================
# Carpeta pública que sirve https://www.tecnoweb.org.bo/inf513/grupo08sa/proyecto2
# (verifíquela en el servidor; suele ser algo como /var/www/html/inf513/grupo08sa/proyecto2)
WEB_DIR="${WEB_DIR:-/var/www/html/inf513/grupo08sa/proyecto2}"

# Código de Laravel (FUERA de la carpeta web). Si no tiene permiso fuera de la web,
# use "$WEB_DIR/_app" y el script la protegerá con .htaccess.
APP_DIR="${APP_DIR:-$HOME/proyecto2_app}"

# Paquete con el código listo (vendor + build incluidos) y carpeta de respaldos
PAQUETE="${PAQUETE:-$HOME/proyecto2_app.tar.gz}"
RESPALDOS="${RESPALDOS:-$HOME/respaldos_proyecto2}"

PHP="${PHP:-php}"
