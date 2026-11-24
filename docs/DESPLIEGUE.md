# Despliegue en el servidor tecnoweb.org.bo

URL final: `https://www.tecnoweb.org.bo/inf513/grupo08sa/proyecto2`

## Arquitectura de carpetas en el servidor

```
~/proyecto2_app/                 ← código Laravel completo (vendor incluido). NO es accesible por web.
~/proyecto2_app.tar.gz           ← paquete original (para restaurar)
~/respaldos_proyecto2/           ← .env, respaldos JSON, pg_dump, copia del paquete y de deploy/
<carpeta web>/inf513/grupo08sa/proyecto2/   ← solo lo público: index.php, .htaccess, build/, favicon
```

El `index.php` de la carpeta web apunta a `~/proyecto2_app` (plantilla: `deploy/index.produccion.php`).
Así nadie puede descargar el `.env` ni el código por HTTP.

## Paso 1 — En la PC (Git Bash), generar el paquete

```bash
git add -A && git commit -m "..."      # el paquete solo incluye lo que está en git
bash scripts/empaquetar.sh             # crea dist/proyecto2_app.tar.gz y el entregable .tar.gz
scp dist/proyecto2_app.tar.gz USUARIO@www.tecnoweb.org.bo:~/
```

> Si `composer` no está en el PATH: `COMPOSER_BIN="php /ruta/composer.phar" bash scripts/empaquetar.sh`

## Paso 2 — En el servidor (SSH), una sola vez

```bash
php -v                                  # debe ser 8.2 o superior y tener pdo_pgsql (php -m | grep pgsql)
mkdir -p ~/proyecto2_app && tar -xzf ~/proyecto2_app.tar.gz -C ~/proyecto2_app
nano ~/proyecto2_app/deploy/config.sh   # AJUSTAR WEB_DIR = carpeta pública real del grupo
```

Crear el esquema propio (para no tocar las tablas del proyecto 1 vía mail):

```bash
psql -h db.tecnoweb.org.bo -U grupo08sa -d db_grupo08sa -c "CREATE SCHEMA IF NOT EXISTS proyecto2;"
```

### Datos reales del proyecto 1

`instalar.sh` carga los datos con `ORIGEN_DATOS=auto`: si existe el esquema `sislab` (proyecto 1 vía mail) en la
misma base, **importa sus datos reales** (usuarios, laboratorios, materias, horario, reservas, equipos, incidencias,
objetos encontrados, turnos, asistencia y bitácora) conservando los ID. `sislab` solo se lee, nunca se modifica.
Los usuarios importados entran con su correo y la contraseña `PASSWORD_INICIAL` (por defecto `Ficct2026`);
además se crea `admin@ficct.edu.bo` (rol Administrador, nuevo en el proyecto 2).

> Las credenciales de la base **no se guardan en git**: solo en el `.env` del servidor.

Instalar:

```bash
bash ~/proyecto2_app/deploy/instalar.sh
#  1ª vez: copia la plantilla .env y se detiene → editar DB_PASSWORD:
nano ~/proyecto2_app/.env
bash ~/proyecto2_app/deploy/instalar.sh     # migra, carga datos demo, repara, respalda y verifica
```

## Paso 3 — Probar

- `https://www.tecnoweb.org.bo/inf513/grupo08sa/proyecto2` → pantalla de login
- `.../proyecto2/api/estado` → `{"ok":true,...}`
- Usuarios: `admin@ficct.edu.bo`, `encargado@…`, `docente@…`, `auxiliar@…`, `director@…` — contraseña `Ficct2026`

## Actualizar a una nueva versión

```bash
# PC
bash scripts/empaquetar.sh && scp dist/proyecto2_app.tar.gz USUARIO@www.tecnoweb.org.bo:~/
# Servidor
bash ~/proyecto2_app/deploy/respaldar.sh && bash ~/proyecto2_app/deploy/restaurar.sh codigo
cd ~/proyecto2_app && php artisan migrate --force
```

## Problemas comunes

| Síntoma | Causa probable | Solución |
|---|---|---|
| 404 en todas las rutas menos el login | Apache no lee `.htaccess` (AllowOverride) | Pedir AllowOverride All, o usar URLs con `index.php/` |
| 500 en blanco | permisos o `.env` | `php artisan sistema:verificar` y `tail storage/logs/*.log` |
| Login responde 401 siempre | Apache descarta el header Authorization | Ya contemplado en `public/.htaccess` (regla HTTP_AUTHORIZATION) |
| CSS/JS no cargan (404 en /build) | no se copió `build/` a la carpeta web | `bash deploy/restaurar.sh codigo` |
| `could not find driver` | falta `pdo_pgsql` en PHP | usar el PHP del servidor que tenga pgsql (`PHP=/ruta/php` en config.sh) |
| `permission denied for schema` | el esquema no existe o no es del usuario | `CREATE SCHEMA proyecto2;` con el usuario del grupo |
| Mixed content (http/https) | proxy HTTPS | ya contemplado: `trustProxies('*')` en `bootstrap/app.php` |
