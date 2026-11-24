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
use App\Http\Controllers\ObjetoEncontradoController;
use App\Http\Controllers\PagoController;
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
Route::post('visitas', [GeneralController::class, 'visita'])->middleware('throttle:visitas');
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
    Route::get('opciones/{tipo}', [GeneralController::class, 'opciones']);

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permiso:dashboard.ver');

    // Administración
    crud('usuarios', UsuarioController::class, 'usuarios');
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
    Route::get('asistencias', [AsistenciaController::class, 'index'])->middleware('permiso:asistencia.ver');
    Route::post('asistencias/entrada', [AsistenciaController::class, 'marcarEntrada'])->middleware('permiso:asistencia.marcar');
    Route::post('asistencias/salida', [AsistenciaController::class, 'marcarSalida'])->middleware('permiso:asistencia.marcar');
    Route::post('asistencias/permiso', [AsistenciaController::class, 'registrarPermiso'])->middleware('permiso:asistencia.gestionar');
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
    crud('conceptos', ConceptoPagoController::class, 'conceptos', ['ver' => 'pagos.ver']);
});

// Cualquier otra ruta /api/... inexistente
Route::fallback(fn () => response()->json(['message' => 'Recurso no encontrado.'], 404));
