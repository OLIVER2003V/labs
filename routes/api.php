<?php

use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\ConceptoPagoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\FechaBloqueadaController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\HorarioController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\LaboratorioController;
use App\Http\Controllers\MateriaController;
use App\Http\Controllers\MensajeController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\ObjetoEncontradoController;
use App\Http\Controllers\PagoController;
use App\Http\Controllers\PagoQrController;
use App\Http\Controllers\PermutaController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SemestreController;
use App\Http\Controllers\TurnoController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API RESTful  (prefijo /api)
|--------------------------------------------------------------------------
| Cada ruta protegida exige:  auth:sanctum (token)  +  bitacora  +  permiso:<recurso.accion>
| Así se implementa la MATRIZ DE ACCESO: el permiso se valida en el servidor,
| no solo ocultando botones en React.
*/

// Registra las 5 rutas REST de un CRUD, cada una con su permiso
if (! function_exists('crud')) {
function crud(string $uri, string $controlador, string $recurso, array $permisos = []): void
{
    $p = $permisos + ['ver' => "$recurso.ver", 'crear' => "$recurso.crear", 'editar' => "$recurso.editar", 'eliminar' => "$recurso.eliminar"];
    Route::get($uri, [$controlador, 'index'])->middleware("permiso:{$p['ver']}");
    Route::get("$uri/{id}", [$controlador, 'show'])->whereNumber('id')->middleware("permiso:{$p['ver']}");
    Route::post($uri, [$controlador, 'store'])->middleware("permiso:{$p['crear']}");
    Route::put("$uri/{id}", [$controlador, 'update'])->whereNumber('id')->middleware("permiso:{$p['editar']}");
    Route::delete("$uri/{id}", [$controlador, 'destroy'])->whereNumber('id')->middleware("permiso:{$p['eliminar']}");
}
}

// ---------- Rutas públicas ----------
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login'); // máx. 5 intentos por minuto (AppServiceProvider)
Route::post('registro', [AuthController::class, 'registro'])->middleware('throttle:registro'); // solicitud de cuenta de estudiante
Route::post('visitas', [GeneralController::class, 'visita'])->middleware('throttle:visitas');
// Aviso de PagoFácil cuando se paga un QR (sin token: lo llama la pasarela)
Route::post('pagos/pagofacil/callback', [PagoQrController::class, 'callback'])->middleware('throttle:60,1');
Route::get('estado', fn () => ['ok' => true, 'hora' => now()->toDateTimeString()]);

// ---------- Rutas protegidas ----------
Route::middleware(['auth:sanctum', 'bitacora'])->group(function () {
    // Sesión y perfil (cualquier usuario autenticado)
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('perfil', [AuthController::class, 'actualizarPerfil']);
    Route::put('perfil/password', [AuthController::class, 'cambiarPassword']);
    Route::put('perfil/preferencias', [AuthController::class, 'guardarPreferencias']);
    Route::get('buscar', [GeneralController::class, 'buscar']);
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::put('notificaciones/leer-todas', [NotificacionController::class, 'leerTodas']);
    Route::put('notificaciones/{id}/leer', [NotificacionController::class, 'leer'])->whereNumber('id');
    Route::get('opciones/{tipo}', [GeneralController::class, 'opciones']);

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permiso:dashboard.ver');

    // Administración
    crud('usuarios', UsuarioController::class, 'usuarios');
    Route::put('usuarios/{id}/aprobar', [UsuarioController::class, 'aprobar'])->whereNumber('id')->middleware('permiso:usuarios.editar');
    Route::put('usuarios/{id}/rechazar', [UsuarioController::class, 'rechazar'])->whereNumber('id')->middleware('permiso:usuarios.editar');
    Route::put('usuarios/{id}/restaurar', [UsuarioController::class, 'restaurar'])->whereNumber('id')->middleware('permiso:usuarios.eliminar');
    Route::get('roles', [RolController::class, 'matriz'])->middleware('permiso:roles.ver');
    Route::post('roles', [RolController::class, 'store'])->middleware('permiso:roles.editar');
    Route::put('roles/{id}', [RolController::class, 'update'])->middleware('permiso:roles.editar');
    Route::delete('roles/{id}', [RolController::class, 'destroy'])->middleware('permiso:roles.editar');
    Route::put('roles/{id}/permisos', [RolController::class, 'guardarPermisos'])->middleware('permiso:roles.editar');
    Route::get('bitacora', [BitacoraController::class, 'index'])->middleware('permiso:bitacora.ver');
    Route::get('bitacora/resumen', [BitacoraController::class, 'resumen'])->middleware('permiso:bitacora.ver');
    Route::get('auditoria', [BitacoraController::class, 'auditoria'])->middleware('permiso:auditoria.ver');
    Route::post('auditoria/{id}/restaurar', [BitacoraController::class, 'restaurar'])->middleware('permiso:auditoria.restaurar');

    // Configuración semestral
    crud('laboratorios', LaboratorioController::class, 'laboratorios');
    crud('semestres', SemestreController::class, 'semestres');
    Route::put('semestres/{id}/activar', [SemestreController::class, 'activar'])->middleware('permiso:semestres.editar');
    Route::put('semestres/{id}/cerrar', [SemestreController::class, 'cerrar'])->middleware('permiso:semestres.editar');
    crud('fechas-bloqueadas', FechaBloqueadaController::class, 'semestres');
    crud('materias', MateriaController::class, 'horarios', ['ver' => 'horarios.ver']);

    // Horarios, disponibilidad y reservas
    Route::post('horarios/importar', [HorarioController::class, 'importar'])->middleware('permiso:horarios.crear');
    Route::get('horarios/plantilla', [HorarioController::class, 'plantilla'])->middleware('permiso:horarios.crear');
    crud('horarios', HorarioController::class, 'horarios');
    Route::get('disponibilidad', [ReservaController::class, 'disponibilidad'])->middleware('permiso:disponibilidad.ver');
    Route::get('disponibilidad/agenda', [ReservaController::class, 'agenda'])->middleware('permiso:disponibilidad.ver');
    Route::get('reservas', [ReservaController::class, 'index'])->middleware('permiso:reservas.ver');
    Route::get('reservas/{id}', [ReservaController::class, 'show'])->whereNumber('id')->middleware('permiso:reservas.ver');
    Route::post('reservas', [ReservaController::class, 'store'])->middleware('permiso:reservas.crear');
    Route::put('reservas/{id}/aprobar', [ReservaController::class, 'aprobar'])->middleware('permiso:reservas.aprobar');
    Route::put('reservas/{id}/rechazar', [ReservaController::class, 'rechazar'])->middleware('permiso:reservas.aprobar');
    Route::put('reservas/{id}/cancelar', [ReservaController::class, 'cancelar'])->middleware('permiso:reservas.cancelar');

    // Inventario, incidencias, objetos encontrados
    crud('equipos', EquipoController::class, 'equipos');
    Route::get('incidencias', [IncidenciaController::class, 'index'])->middleware('permiso:incidencias.ver');
    Route::post('incidencias', [IncidenciaController::class, 'store'])->middleware('permiso:incidencias.crear');
    Route::put('incidencias/{id}', [IncidenciaController::class, 'update'])->middleware('permiso:incidencias.gestionar');
    crud('objetos', ObjetoEncontradoController::class, 'objetos', ['editar' => 'objetos.crear', 'eliminar' => 'objetos.crear']);
    Route::put('objetos/{id}/entregar', [ObjetoEncontradoController::class, 'entregar'])->middleware('permiso:objetos.entregar');

    // Auxiliares: turnos y asistencia
    crud('turnos', TurnoController::class, 'turnos', ['editar' => 'turnos.crear', 'eliminar' => 'turnos.crear']);
    // CU5 - Permutas de turno (solicitar → el reemplazante acepta → el Encargado aprueba) y chat interno
    Route::get('permutas', [PermutaController::class, 'index'])->middleware('permiso:permutas.ver');
    Route::get('permutas/{id}', [PermutaController::class, 'show'])->whereNumber('id')->middleware('permiso:permutas.ver');
    Route::post('permutas', [PermutaController::class, 'store'])->middleware('permiso:permutas.solicitar');
    Route::put('permutas/{id}/responder', [PermutaController::class, 'responder'])->middleware('permiso:permutas.solicitar');
    Route::put('permutas/{id}/aprobar', [PermutaController::class, 'aprobar'])->middleware('permiso:permutas.aprobar');
    Route::put('permutas/{id}/rechazar', [PermutaController::class, 'rechazar'])->middleware('permiso:permutas.aprobar');
    Route::put('permutas/{id}/cancelar', [PermutaController::class, 'cancelar'])->middleware('permiso:permutas.ver');
    Route::middleware('permiso:mensajes.usar')->group(function () {
        Route::get('mensajes', [MensajeController::class, 'conversaciones']);
        Route::get('mensajes/contactos', [MensajeController::class, 'contactos']);
        Route::get('mensajes/{usuario}', [MensajeController::class, 'conversacion'])->whereNumber('usuario');
        Route::post('mensajes', [MensajeController::class, 'enviar'])->middleware('throttle:30,1'); // máx. 30 mensajes por minuto
    });

    Route::get('asistencias', [AsistenciaController::class, 'index'])->middleware('permiso:asistencia.ver');
    Route::post('asistencias/entrada', [AsistenciaController::class, 'marcarEntrada'])->middleware('permiso:asistencia.marcar');
    Route::post('asistencias/salida', [AsistenciaController::class, 'marcarSalida'])->middleware('permiso:asistencia.marcar');
    Route::get('asistencias/hoy', [AsistenciaController::class, 'hoy'])->middleware('permiso:asistencia.marcar');
    Route::get('asistencias/permisos', [AsistenciaController::class, 'permisos'])->middleware('permiso:asistencia.ver');
    Route::post('asistencias/permiso', [AsistenciaController::class, 'registrarPermiso'])->middleware('permiso:asistencia.gestionar');
    Route::delete('asistencias/permisos/{id}', [AsistenciaController::class, 'eliminarPermiso'])->whereNumber('id')->middleware('permiso:asistencia.gestionar');
    Route::post('asistencias/faltas', [AsistenciaController::class, 'marcarFaltas'])->middleware('permiso:asistencia.gestionar');

    // Pagos
    Route::get('pagos/metodos', [PagoController::class, 'metodos'])->middleware('permiso:pagos.ver');
    Route::post('pagos/metodos', [PagoController::class, 'registrarMetodo'])->middleware('permiso:pagos.pagar');
    Route::put('pagos/metodos/{id}/predeterminado', [PagoController::class, 'predeterminado'])->middleware('permiso:pagos.pagar');
    Route::delete('pagos/metodos/{id}', [PagoController::class, 'eliminarMetodo'])->middleware('permiso:pagos.pagar');
    Route::get('pagos/planes', [PagoController::class, 'planes'])->middleware('permiso:pagos.ver');
    Route::post('pagos/planes', [PagoController::class, 'crearPlan'])->middleware('permiso:planes.gestionar');
    Route::put('pagos/planes/{id}/anular', [PagoController::class, 'anularPlan'])->middleware('permiso:planes.gestionar');
    Route::post('pagos/cuotas/{id}/pagar', [PagoController::class, 'pagarCuota'])->middleware('permiso:pagos.pagar');
    Route::post('pagos/unico', [PagoController::class, 'pagoUnico'])->middleware('permiso:pagos.pagar');
    Route::get('pagos/transacciones', [PagoController::class, 'transacciones'])->middleware('permiso:pagos.ver');
    Route::post('pagos/qr', [PagoQrController::class, 'iniciar'])->middleware('permiso:pagos.pagar');
    Route::get('pagos/transacciones/{id}/estado', [PagoQrController::class, 'estado'])->whereNumber('id')->middleware('permiso:pagos.ver');
    Route::post('pagos/simulador/{id}/pagar', [PagoQrController::class, 'simularPago'])->whereNumber('id')->middleware('permiso:pagos.pagar');
    crud('conceptos', ConceptoPagoController::class, 'conceptos', ['ver' => 'pagos.ver']);
});

// Cualquier otra ruta /api/... inexistente
Route::fallback(fn () => response()->json(['message' => 'Recurso no encontrado.'], 404));
