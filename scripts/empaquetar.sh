#!/usr/bin/env bash
# ======================================================================
#  EMPAQUETAR (se ejecuta en la PC local, con Git Bash)
#  Genera en dist/:
#   1) proyecto2_app.tar.gz              → listo para el servidor (incluye vendor/ y public/build/)
#   2) 2026-2_INF513-P2_grupo08sa.tar.gz → entregable de la materia (código fuente + docs)
#
#  Uso:  bash scripts/empaquetar.sh
#  Requisitos: commit hecho (se empaqueta lo que está en git), node, php y composer.
# ======================================================================
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ"
PHP="${PHP:-php}"
# Composer: el del sistema o, si no hay, el de Laravel Herd (no usar la variable COMPOSER: composer la lee como ruta de composer.json)
if [ -z "${COMPOSER_BIN:-}" ]; then
    if command -v composer >/dev/null 2>&1; then COMPOSER_BIN=composer
    elif [ -f "$HOME/.config/herd/bin/composer.phar" ]; then COMPOSER_BIN="$PHP $HOME/.config/herd/bin/composer.phar"
    else echo "✘ No encontré composer. Indíquelo con COMPOSER_BIN=..." >&2; exit 1; fi
fi
ENTREGABLE="${ENTREGABLE:-2026-2_INF513-P2_grupo08sa}"

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "⚠ Hay cambios sin commit: el paquete solo incluye lo que está en git. Haga commit primero." >&2
    exit 1
fi

echo "→ Compilando frontend (React + Vite)…"
npm run build >/dev/null

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
git archive HEAD | tar -x -C "$STAGE"
cp -R public/build "$STAGE/public/"

echo "→ Instalando dependencias de producción (composer --no-dev)…"
(cd "$STAGE" && $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --no-progress -q)
rm -rf "$STAGE/tests" "$STAGE/node_modules"

mkdir -p dist
tar -czf dist/proyecto2_app.tar.gz -C "$STAGE" .
echo "✔ dist/proyecto2_app.tar.gz ($(du -h dist/proyecto2_app.tar.gz | cut -f1))"

# Entregable: código fuente (sin vendor ni node_modules) + documentación
ENT="$(mktemp -d)"
mkdir -p "$ENT/$ENTREGABLE/codigo_fuente"
git archive HEAD | tar -x -C "$ENT/$ENTREGABLE/codigo_fuente"
cp -R docs "$ENT/$ENTREGABLE/documentacion"
tar -czf "dist/$ENTREGABLE.tar.gz" -C "$ENT" "$ENTREGABLE"
rm -rf "$ENT"
echo "✔ dist/$ENTREGABLE.tar.gz ($(du -h "dist/$ENTREGABLE.tar.gz" | cut -f1))"
echo
echo "Subir al servidor:  scp dist/proyecto2_app.tar.gz USUARIO@www.tecnoweb.org.bo:~/"
