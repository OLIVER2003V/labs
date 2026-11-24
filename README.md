# Sistema de Gestión de Laboratorios FICCT — Proyecto 2 (Web)

**INF513 Tecnología Web · Grupo 08 SA · UAGRM – FICCT**

Sistema web en arquitectura de 3 capas para gestionar los laboratorios de computación de la FICCT:
reservas con detección de choques, horario base, disponibilidad, inventario, incidencias, objetos encontrados,
turnos y asistencia de auxiliares, y cobros con pasarela de pagos simulada.

| Capa | Tecnología |
|---|---|
| Presentación | React 19 (SPA) + Vite + Tailwind CSS 4 + Axios + Recharts |
| Negocio | Laravel 12 — API RESTful + Sanctum (tokens) + middleware RBAC |
| Datos | PostgreSQL — restricciones, triggers y funciones PL/pgSQL |

## Requisitos cubiertos

1. Navegación con menú dinámico según los permisos del rol
2. RBAC: 4 roles de negocio (Encargado, Docente, Auxiliar, Director) + Administrador; menú guardado en la BD
3. API RESTful en Laravel consumida por React
4. Matriz de acceso (middleware por ruta) y bitácora (logins exitosos/fallidos, IP, recursos más accedidos) + auditoría por triggers
5. Temas Niños / Jóvenes / Adultos, modo día/noche/automático, tamaño de letra y alto contraste
6. Validación en el cliente y en el servidor, con mensajes en español
7. Contador de visitas por página en el pie de página (función PostgreSQL)
8. Panel de estadísticas del negocio y de accesos
9. Búsqueda global predictiva en el encabezado
10. Pagos: métodos de pago, pagos únicos y planes de pago en cuotas

Detalle de cada requisito y dónde está en el código: [docs/MAPA_DEFENSA.md](docs/MAPA_DEFENSA.md)

## Ejecutar en local

Forma rápida: en **PowerShell** `.\iniciar.ps1` · en **Git Bash** `bash scripts/local.sh` → http://127.0.0.1:8000
(En PowerShell no use `php artisan serve`: en Windows falla con *Unable to start curl module*.)
(`--reiniciar` recarga los datos de demostración, `--pruebas` ejecuta las pruebas).

Requisitos: PHP 8.2+ con `pdo_pgsql`, Composer, Node 20+, PostgreSQL.

```bash
composer install
npm install
cp .env.example .env         # configurar DB_* (pgsql) y APP_LOCALE=es
php artisan key:generate
php artisan migrate --seed   # estructura + triggers + datos de demostración
npm run build                # o "npm run dev" mientras se programa
php artisan serve            # http://127.0.0.1:8000
```

**Datos**: si la base tiene el esquema `sislab` (proyecto 1), `php artisan migrate:fresh --seed` importa sus datos
reales (`database/seeders/ImportarSislabSeeder.php`); si no, carga datos de demostración (`ORIGEN_DATOS=demo`).
Todos los usuarios entran con su correo y la contraseña inicial **Ficct2026**; administrador: `admin@ficct.edu.bo`.
Con `APP_DEMO_LOGIN=true` el login muestra un botón con una cuenta real por rol.

Pasarela simulada: tarjeta `4111 1111 1111 1111` (aprobada) · `4000 0000 0000 0002` (rechazada).

## Pruebas automáticas

21 pruebas (PHPUnit) sobre una base PostgreSQL de prueba `db_grupo08sa_test` (créela una vez con `CREATE DATABASE db_grupo08sa_test;`):

```bash
php artisan test
```

- `tests/Feature/SeguridadTest.php`: login, bitácora, matriz de acceso, menú por rol, protección del rol Administrador, validación en español, contador de visitas.
- `tests/Feature/NegocioTest.php`: choques de horario, flujo de reservas, triggers (reservas, incidencias, auditoría), pagos y respaldo/restauración.

## Servidor y recuperación

- Despliegue en tecnoweb: [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md)
- **Manual de recuperación para la defensa**: [docs/RECUPERACION.md](docs/RECUPERACION.md)

```bash
php artisan sistema:verificar      # diagnóstico
bash deploy/restaurar.sh todo      # restauración completa (código + triggers + datos)
```

## Estructura

```
app/Http/Controllers/   API (CrudController base + un controlador por módulo)
app/Http/Middleware/    VerificarPermiso (matriz de acceso), RegistrarBitacora
app/Services/           Disponibilidad (choques), PasarelaPago (simulada)
app/Support/            Triggers (SQL), Respaldo (JSON)
database/migrations/    Tablas, restricciones y triggers
database/seeders/       Roles, permisos, menú y datos de demostración
resources/js/           React: main.jsx, contexto/, componentes/, paginas/, lib/
routes/api.php          Rutas REST con su permiso
routes/console.php      Comandos sistema:* y respaldo:*
deploy/                 Scripts del servidor (instalar, respaldar, restaurar)
scripts/empaquetar.sh   Genera el paquete del servidor y el entregable
```
