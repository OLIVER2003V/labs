# Manual de recuperación — Defensa en vivo

> Objetivo: si durante la defensa alguien borra, duplica o modifica algo en el servidor,
> **diagnosticar en 10 segundos y restaurar en menos de 1 minuto.**

## ⭐ Servidor tecnoweb.org.bo (sin SSH) — usar ESTO

En tecnoweb no hay acceso SSH: los archivos se manejan por **FTP** y la base de datos se controla
**desde la laptop** (`php artisan … --env=servidor`, que usa `.env.servidor`). Todo con un solo script,
desde la carpeta del proyecto en Git Bash:

| Síntoma / sabotaje | Comando | Tiempo aprox. |
|---|---|---|
| Diagnóstico (siempre primero) | `bash scripts/servidor.sh verificar` | 10 s |
| Borraron o modificaron **archivos** (index.php, .htaccess, build/, .env, controladores…) | `bash scripts/servidor.sh archivos` | 1–3 min |
| Borraron un **trigger/función**, cambiaron **permisos/menú**, tocaron al **admin** | `bash scripts/servidor.sh reparar` | 15 s |
| Borraron, **duplicaron** o modificaron **registros** | `bash scripts/servidor.sh datos` | 30 s |
| Hicieron `DROP TABLE` | `bash scripts/servidor.sh tablas` | 2 min |
| No sé qué pasó | `bash scripts/servidor.sh todo` | 3–5 min |

Antes de la defensa: `bash scripts/servidor.sh respaldar antes_defensa` (el respaldo queda **en la laptop**,
en `storage/app/respaldos/servidor/`, a salvo de lo que pase en el servidor). Para volver a él:
`bash scripts/servidor.sh datos antes_defensa.json`.

Estructura en el servidor: `/home/grupo08sa/proyecto2/` (público: index.php, .htaccess, build/) y
`/home/grupo08sa/proyecto2/_app/` (código + .env, bloqueado por `.htaccess`). Cada `subir` guarda la versión
anterior como `proyecto2_respaldo_<fecha>`: si todo falla, renombrarla por FTP a `proyecto2` la vuelve a publicar.

---

## Servidor con SSH (alternativa genérica)

## 0. Antes de la defensa (checklist)

- [ ] `bash ~/proyecto2_app/deploy/respaldar.sh antes_defensa` → crea `antes_defensa.json` + copia `.env`, scripts y paquete en `~/respaldos_proyecto2/`
- [ ] Verificar que existen: `~/proyecto2_app.tar.gz`, `~/respaldos_proyecto2/proyecto2_app.tar.gz`, `~/respaldos_proyecto2/.env`, `~/respaldos_proyecto2/deploy/`
- [ ] `php artisan sistema:verificar` → todo en verde
- [ ] (Opcional) respaldo automático cada 15 min: `crontab -e` y pegar
      `*/15 * * * * bash $HOME/proyecto2_app/deploy/respaldar.sh >> $HOME/respaldos_proyecto2/cron.log 2>&1`
- [ ] Tener abierta una terminal SSH ya conectada al servidor
- [ ] Tener `dist/proyecto2_app.tar.gz` en la laptop (por si también borran el paquete del servidor)

## 1. Diagnóstico (siempre primero)

```bash
cd ~/proyecto2_app && php artisan sistema:verificar
```

Muestra ✔ / ✘ para: `.env`, APP_KEY, frontend compilado, permisos de `storage/`, conexión a la BD,
las 25 tablas, las 5 funciones, los 21 triggers, permisos del Administrador, menú, usuario admin y último respaldo.
**Cada ✘ trae en amarillo el comando que lo arregla.**

Si la web muestra error 500: `tail -50 ~/proyecto2_app/storage/logs/laravel-$(date +%F).log`

## 2. ¿Qué pasó? → ¿Qué ejecuto?

Todos los comandos se ejecutan con `bash ~/proyecto2_app/deploy/restaurar.sh <acción>`.
Si borraron la carpeta de la app, use la copia: `bash ~/respaldos_proyecto2/deploy/restaurar.sh todo`

| Síntoma / sabotaje | Acción | Tiempo |
|---|---|---|
| Borraron o modificaron **archivos** (index.php, .htaccess, build/, vendor/, .env, controladores…) | `codigo` | ~15 s |
| Borraron un **trigger o función**, cambiaron **permisos/menú**, desactivaron o borraron al **admin** | `reparar` | ~5 s |
| Borraron, **duplicaron** o modificaron **registros** | `datos` | ~10 s |
| Hicieron `DROP TABLE` o rompieron la estructura | `tablas` | ~15 s |
| No sé qué pasó / varias cosas a la vez | `todo` | ~30 s |
| Quiero volver a los datos de demostración desde cero | `semilla` | ~20 s |

Volver a un respaldo concreto: `bash restaurar.sh datos antes_defensa.json` (ver lista: `php artisan respaldo:listar`).

## 3. Defensas que ya tiene el sistema (para explicar)

- **Duplicados**: restricciones `UNIQUE` (código, correo, sigla…) → la API responde *“Ya existe un registro con esos datos”*.
- **Reservas que chocan**: el trigger `tg_reserva_sin_choque` rechaza aunque se inserte por SQL directo.
- **Borrados con dependencias**: las llaves foráneas impiden borrar, p. ej., una cuota que ya tiene transacción.
- **Auditoría**: los triggers `tg_auditoria_*` guardan el antes/después de cada INSERT/UPDATE/DELETE,
  **incluidos los hechos por psql/pgAdmin** (aparecen como *“Directo en BD”* en rojo en la pantalla Auditoría).
  Desde esa pantalla, un registro eliminado se puede **restaurar con un clic** (usuario Administrador).
- **Usuarios**: borrado lógico (`deleted_at`); el admin se reactiva con `restaurar.sh reparar`.

## 4. Si no hay paquete en el servidor

Desde la laptop (Git Bash), en la carpeta del proyecto:

```bash
bash scripts/empaquetar.sh
scp dist/proyecto2_app.tar.gz USUARIO@www.tecnoweb.org.bo:~/
ssh USUARIO@www.tecnoweb.org.bo "bash ~/respaldos_proyecto2/deploy/restaurar.sh todo"
```

Si también borraron `~/respaldos_proyecto2`, el código está en GitHub y los datos de demostración se regeneran:
extraer el paquete en `~/proyecto2_app`, copiar `deploy/env.produccion.ejemplo` como `.env`,
completar la contraseña y ejecutar `bash deploy/instalar.sh`.

## 5. Comandos artisan útiles

| Comando | Qué hace |
|---|---|
| `php artisan sistema:verificar` | Diagnóstico completo |
| `php artisan sistema:reparar` | Reinstala triggers/funciones, permisos base, menú, admin activo, limpia cachés |
| `php artisan sistema:reparar --admin` | Además, contraseña del admin = `Ficct2026` |
| `php artisan sistema:reparar --permisos` | Además, restaura la matriz de permisos original de todos los roles |
| `php artisan respaldo:crear [nombre]` | Respaldo JSON de todas las tablas |
| `php artisan respaldo:listar` | Lista respaldos |
| `php artisan respaldo:restaurar [archivo] [--reconstruir]` | Restaura (con `--reconstruir` recrea las tablas primero) |
| `php artisan migrate:status` | Estado de las migraciones |
| `php artisan route:list --path=api` | Todas las rutas de la API con su middleware de permiso |
