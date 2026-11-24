#!/usr/bin/env bash
# ======================================================================
#  Levantar el sistema en la PC (Git Bash):   bash scripts/local.sh
#  Opciones:  bash scripts/local.sh --reiniciar   (borra y recarga los datos de demostración)
#             bash scripts/local.sh --pruebas     (ejecuta las pruebas automáticas)
#  Abre: http://127.0.0.1:8000   (usuarios: admin|encargado|docente|auxiliar|director @ficct.edu.bo / Ficct2026)
# ======================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

# Busca un PHP que tenga el driver de PostgreSQL (pdo_pgsql)
PHP_BIN="${PHP:-}"
for c in "$PHP_BIN" php "$HOME/.config/herd/bin/php85/php.exe" "$HOME/.config/herd/bin/php84/php.exe" "$HOME/.config/herd/bin/php83/php.exe"; do
    if [ -n "$c" ] && command -v "$c" >/dev/null 2>&1 && "$c" -m 2>/dev/null | grep -qi pdo_pgsql; then PHP_BIN="$c"; break; fi
done
"$PHP_BIN" -m 2>/dev/null | grep -qi pdo_pgsql || { echo "✘ No encontré un PHP con pdo_pgsql. Indíquelo: PHP=/ruta/php bash scripts/local.sh"; exit 1; }
echo "→ Usando $("$PHP_BIN" -r 'echo PHP_BINARY." (PHP ".PHP_VERSION.")";')"

[ -f .env ] || { cp .env.example .env; "$PHP_BIN" artisan key:generate; echo "→ Se creó .env: revise DB_PASSWORD"; }
[ -d vendor ] || { echo "✘ Falta vendor/: ejecute composer install"; exit 1; }
[ -f public/build/manifest.json ] || { echo "→ Compilando frontend…"; npm install && npm run build; }

case "${1:-}" in
    --reiniciar) "$PHP_BIN" artisan migrate:fresh --seed --force ;;
    --pruebas) exec "$PHP_BIN" artisan test ;;
    *) "$PHP_BIN" artisan migrate --force ;;
esac

"$PHP_BIN" artisan sistema:verificar || true
echo "→ Sistema en http://127.0.0.1:8000  (Ctrl+C para detener)"
exec "$PHP_BIN" artisan serve --host=127.0.0.1 --port=8000
