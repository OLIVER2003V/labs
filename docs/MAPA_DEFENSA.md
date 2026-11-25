# Mapa de defensa — dónde está cada requisito

Para cada requisito del enunciado: **qué mostrar en la demo**, **dónde está en el código** y **cómo explicarlo**.

## Arquitectura de 3 capas

| Capa | Tecnología | Carpeta |
|---|---|---|
| 1. Presentación | React 19 + Vite + Tailwind, consume la API con Axios | `resources/js/` (`main.jsx`, `paginas/`, `componentes/`) |
| 2. Lógica de negocio | Laravel 12, API RESTful con JSON | `routes/api.php`, `app/Http/Controllers/`, `app/Services/` |
| 3. Persistencia | PostgreSQL: tablas, CHECK, UNIQUE, FK, triggers, funciones | `database/migrations/`, `app/Support/Triggers.php` |

Flujo: React → `lib/api.js` (Axios + token Bearer) → `routes/api.php` → middleware (`auth:sanctum`, `bitacora`, `permiso:x`) → Controller → Modelo Eloquent → PostgreSQL.

---

## 1. Diseño y navegación
- **Demo**: entrar con Docente y luego con Encargado: el menú lateral cambia.
- **Código**: `componentes/Layout.jsx` (`MenuLateral`), `paginas/Inicio.jsx`.

## 2. RBAC — roles y menú dinámico desde la BD
- **Roles**: el Encargado de Laboratorios es el administrador del sistema (rol de sistema, todos los permisos); roles de negocio: Docente, Auxiliar, Director y Estudiante.
- **Menú desde la BD**: tabla `menus` (columna `permiso_codigo`). `AuthController::perfilCompleto()` devuelve solo los ítems cuyo permiso tiene el rol.
- **Demo**: en *Roles y permisos* (admin), quitar `objetos.ver` al Auxiliar → guardar → entrar como auxiliar: desaparece del menú y la API responde 403.
- **Código**: `database/seeders/SeguridadSeeder.php` (permisos, roles, menú), `paginas/Roles.jsx`.

## 3. Stack obligatorio (API RESTful)
- `GET /api/reservas`, `POST /api/reservas`, `PUT /api/reservas/{id}/aprobar`, `DELETE /api/laboratorios/{id}`…
- Ver todas: `php artisan route:list --path=api`.
- Auth por **token Bearer** (Laravel Sanctum): `AuthController::login` → `createToken`.

## 4. Matriz de acceso y bitácora
- **Matriz**: cada ruta tiene `->middleware('permiso:recurso.accion')` → `app/Http/Middleware/VerificarPermiso.php`.
  El frontend también protege (`main.jsx` → `Protegida`), pero **la seguridad real está en el servidor**.
- **Bitácora**: `app/Http/Middleware/RegistrarBitacora.php` registra cada petición (recurso, IP, método, código HTTP);
  `AuthController::registrar()` registra `LOGIN_OK` / `LOGIN_FALLIDO` (motivo) / `LOGOUT`.
- **Demo**: login con contraseña mala → *Bitácora* → aparece LOGIN_FALLIDO con IP. Resumen: recursos más accedidos, IPs, fallidos.
- **Extra (triggers)**: *Auditoría* muestra INSERT/UPDATE/DELETE con antes/después, incluso hechos por psql.

## 5. Temas y accesibilidad
- 3 temas (`ninos`, `jovenes`, `adultos`) × modo día/noche/**auto** (07:00–19:00) + alto contraste + tamaño de letra 80–160 %.
- **Código**: `resources/css/app.css` (variables CSS por `data-tema` / `data-modo` / `data-contraste`),
  `contexto/TemaContext.jsx`, `componentes/PanelAccesibilidad.jsx` (ícono ♿ en el encabezado).
- Se guarda en `localStorage` y en `usuarios.preferencias` (BD) → `PUT /api/perfil/preferencias`.
- Accesibilidad: foco visible, `aria-*`, "Saltar al contenido", navegación por teclado en la búsqueda.

## 6. Validación estricta (cliente y servidor, en español)
- **Cliente**: `lib/validar.js` (requerido, longitudes, patrones, fechas, Luhn de tarjeta); los campos se describen en `paginas/Modulos.jsx`.
- **Servidor**: `reglas()` de cada controller + `lang/es/validation.php` (mensajes y nombres de campos en español).
- **Errores de BD traducidos**: `bootstrap/app.php` (duplicado 23505, FK 23503, CHECK 23514, choque del trigger).
- **Demo**: crear usuario con correo repetido / teléfono 123 / contraseña corta.

## 7. Contador de visitas (footer)
- `componentes/Footer.jsx` → `POST /api/visitas` → función PostgreSQL `registrar_visita()`
  (`INSERT … ON CONFLICT DO UPDATE contador = contador + 1`, atómica). Tabla `visitas_pagina`.

## 8. Dashboard
- `DashboardController` + `paginas/Dashboard.jsx` (Recharts). Negocio: ocupación por laboratorio, reservas por estado,
  ingresos por día, incidencias, asistencia. Accesos: peticiones/logins/fallidos por día, recursos y páginas más visitadas.
  Los colores de los gráficos cambian con el tema.

## 9. Búsqueda global
- Barra en el encabezado (`Layout.jsx` → `BuscadorGlobal`): predictiva con retardo de 300 ms, flechas + Enter.
- `GET /api/buscar?q=` (`GeneralController::buscar`): solo busca en lo que el rol puede ver.

## 10. Pagos electrónicos (Stripe Checkout) y multas (CU9)
- **Stripe Checkout** (modo prueba): el usuario presiona Pagar, va a la página segura de Stripe, paga con tarjeta y vuelve con el comprobante.
  La tarjeta nunca pasa por nuestro servidor. Detalle y configuración del webhook: [STRIPE.md](STRIPE.md).
- Se confirma de dos formas (lo que llegue primero): consultando a Stripe al volver, y por **webhook con firma HMAC verificada**.
  `PagoStripeController::confirmar` usa `lockForUpdate()`: nunca se confirma dos veces.
- Se paga con Stripe: **cuotas** de planes de pago, **multas** (CU9) y **pagos únicos** (conceptos del catálogo).
- **Multas (CU9)**: atraso y falta del auxiliar (CU7) y reserva no utilizada (CU4). Con multas vencidas no se puede reservar ni pedir permutas.
- Trigger `tg_cuota_cierra_plan`: al pagar la última cuota, el plan pasa a PAGADO.
- **Demo**: Auxiliar → Multas → Pagar → tarjeta `4242 4242 4242 4242` → vuelve "Pago aprobado" y la multa queda PAGADA.

## Seguridad adicional (para preguntas)
- **Escalada de privilegios bloqueada**: solo quien tiene `roles.editar` (el Encargado) puede crear, editar o borrar cuentas de Encargado, y nadie puede eliminarse ni desactivarse a sí mismo (`UsuarioController::protegerSistema`).
- **Listas con datos personales** (`/api/opciones/usuarios`, etc.) exigen permiso (`GeneralController::opciones`).
- **Cabeceras HTTP**: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` (`app/Http/Middleware/CabecerasSeguridad.php`).
- **Fuerza bruta**: máximo 5 intentos de login por minuto por correo + IP (`AppServiceProvider`).
- **Borrado lógico de usuarios**: se pueden ver y restaurar en *Usuarios → Registros: Usuarios eliminados*.

## Extras útiles en la demo
- **Exportar** (botón en cada tabla): descarga un CSV para Excel con *todos* los registros del filtro actual.
- **Disponibilidad → Solicitar reserva**: abre el formulario ya completado con el laboratorio, la fecha y el horario.
- **Pruebas automáticas**: `php artisan test` (21 pruebas en verde) demuestra seguridad, triggers y reglas de negocio.

## Reglas de negocio del dominio (para preguntas)
- **Choque**: dos franjas chocan si `inicioA < finB` y `inicioB < finA` (`app/Services/Disponibilidad.php`).
- Disponibilidad = horario base del semestre activo + reservas APROBADAS + fechas bloqueadas + estado del laboratorio.
- Ante un choque, la API responde 422 con **laboratorios libres sugeridos**.
- Al aprobar se vuelve a verificar, y además el trigger `tg_reserva_sin_choque` lo impide en la BD.
- Solo un semestre activo: índice único parcial `ux_semestre_activo`.
- Asistencia: entrada desde 15 min antes; puntual hasta 10 min después del inicio; si no, atraso.

## Preguntas típicas
- **¿Por qué Sanctum con tokens y no sesiones?** La API es stateless (REST) y funciona igual desde cualquier cliente.
- **¿Qué pasa si oculto el botón pero llamo a la API con Postman?** El middleware `permiso:` responde 403 y lo registra como DENEGADO.
- **¿Cómo evitan la inyección SQL?** Eloquent / Query Builder con parámetros enlazados (PDO prepared statements).
- **¿Dónde se ve la arquitectura de 3 capas?** Ver la tabla del inicio.
