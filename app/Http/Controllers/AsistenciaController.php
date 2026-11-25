<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\PermisoAuxiliar;
use App\Models\User;
use App\Services\ControlAsistencia;
use App\Services\Multas;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU7 - Asistencia de auxiliares. Las reglas están en App\Services\ControlAsistencia:
 *   entrada desde 15 min antes · PUNTUAL hasta 10 min después · ATRASO de 11 a 30 min · pasados 30 min = FALTA
 *   (o PERMISO si el Encargado registró un permiso para ese día).
 * Las faltas se registran solas: cada 5 min (php artisan schedule:run) y, además, cada vez que alguien abre Asistencia
 * (así funcionan aunque el hosting no tenga tareas programadas).
 */
class AsistenciaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'estado' => ['nullable', Rule::in(['PUNTUAL', 'ATRASO', 'FALTA', 'PERMISO'])],
        ]);
        ControlAsistencia::registrarFaltas(Carbon::today()); // faltas "perezosas" de hoy

        $q = Asistencia::with(['auxiliar', 'turno.laboratorio'])->orderByDesc('fecha')->orderByDesc('id');
        if (! $request->user()->tienePermiso('asistencia.gestionar')) {
            $q->where('auxiliar_id', $request->user()->id);
        } elseif ($request->filled('auxiliar_id')) {
            $q->where('auxiliar_id', $request->integer('auxiliar_id'));
        }
        $q->when($request->filled('desde'), fn ($x) => $x->whereDate('fecha', '>=', $request->desde))
          ->when($request->filled('hasta'), fn ($x) => $x->whereDate('fecha', '<=', $request->hasta))
          ->when($request->filled('estado'), fn ($x) => $x->where('estado', $request->estado));

        return response()->json($q->paginate($this->porPagina($request, 10)));
    }

    /** GET /api/asistencias/hoy → mis turnos de hoy, con su ventana de marcado y lo que ya registré */
    public function hoy(Request $request): JsonResponse
    {
        $ahora = now();
        ControlAsistencia::registrarFaltas(Carbon::today());
        $turnos = ControlAsistencia::turnosDe($request->user()->id, Carbon::today());
        $registros = Asistencia::whereIn('turno_id', $turnos->pluck('id'))->whereDate('fecha', $ahora->toDateString())->get()->keyBy('turno_id');

        return response()->json($turnos->map(function ($t) use ($ahora, $registros) {
            $v = ControlAsistencia::ventana($t, Carbon::today());
            $a = $registros[$t->id] ?? null;

            return [
                'turno_id' => $t->id, 'laboratorio' => $t->laboratorio?->codigo ?? 'Módulo', 'es_permuta' => (bool) $t->es_permuta,
                'hora_inicio' => substr($t->hora_inicio, 0, 5), 'hora_fin' => substr($t->hora_fin, 0, 5),
                'desde' => $v['desde']->format('H:i'), 'puntual_hasta' => $v['puntual']->format('H:i'), 'limite' => $v['limite']->format('H:i'),
                'asistencia' => $a ? ['estado' => $a->estado, 'entrada' => substr((string) $a->hora_entrada, 0, 5), 'salida' => substr((string) $a->hora_salida, 0, 5)] : null,
                'puede_entrar' => ! $a && $ahora->between($v['desde'], $v['limite']),
                'puede_salir' => $a && $a->hora_entrada && ! $a->hora_salida,
            ];
        })->values());
    }

    public function marcarEntrada(Request $request): JsonResponse
    {
        $ahora = now();
        $turnos = ControlAsistencia::turnosDe($request->user()->id, Carbon::today());
        abort_if($turnos->isEmpty(), 422, 'No tiene turnos asignados para hoy.');

        // El turno en curso (o el próximo, si faltan hasta 15 min para que empiece)
        $turno = $turnos->first(function ($t) use ($ahora) {
            $v = ControlAsistencia::ventana($t, Carbon::today());

            return $ahora->gte($v['desde']) && $ahora->lt($v['fin']);
        });
        if (! $turno) {
            $proximo = $turnos->first(fn ($t) => ControlAsistencia::ventana($t, Carbon::today())['desde']->gt($ahora));
            abort(422, $proximo
                ? 'Todavía no puede marcar: su turno empieza a las '.substr($proximo->hora_inicio, 0, 5).' y puede marcar desde las '
                    .ControlAsistencia::ventana($proximo, Carbon::today())['desde']->format('H:i').'.'
                : 'No tiene un turno en curso a esta hora ('.$ahora->format('H:i').').');
        }

        $ya = Asistencia::where('turno_id', $turno->id)->whereDate('fecha', $ahora->toDateString())->first();
        abort_if($ya?->hora_entrada, 422, 'Ya registró su entrada para este turno a las '.substr((string) $ya?->hora_entrada, 0, 5).'.');
        abort_if($ya, 422, "Este turno ya figura como {$ya?->estado} hoy.");

        $v = ControlAsistencia::ventana($turno, Carbon::today());
        abort_if($ahora->gt($v['limite']), 422, 'Pasaron más de '.ControlAsistencia::LIMITE_MIN.' minutos del inicio de su turno ('
            .substr($turno->hora_inicio, 0, 5).'): se registra como FALTA. Si tuvo un motivo, pida al Encargado que registre un permiso.');
        $estado = $ahora->lte($v['puntual']) ? 'PUNTUAL' : 'ATRASO';

        $asistencia = Asistencia::create([
            'turno_id' => $turno->id, 'auxiliar_id' => $request->user()->id, 'fecha' => $ahora->toDateString(),
            'hora_entrada' => $ahora->format('H:i:s'), 'estado' => $estado,
            'observacion' => $turno->es_permuta ? 'Cubre por permuta' : null,
        ]);

        $multa = Multas::porAsistencia($asistencia); // CU9: el atraso genera multa

        return response()->json([
            'message' => "Entrada registrada a las {$ahora->format('H:i')} ($estado)."
                .($multa ? ' Se registró una multa de Bs '.number_format((float) $multa->monto, 2).' por atraso.' : ''),
            'data' => $asistencia,
            'multa' => $multa,
        ], 201);
    }

    public function marcarSalida(Request $request): JsonResponse
    {
        $ahora = now();
        $asistencia = Asistencia::with('turno')->where('auxiliar_id', $request->user()->id)
            ->whereDate('fecha', $ahora->toDateString())
            ->whereNotNull('hora_entrada')->whereNull('hora_salida')
            ->latest('id')->first();
        abort_unless($asistencia, 422, 'No tiene una entrada abierta hoy. Registre primero su entrada.');

        // Regla MARSAL: se avisa si sale antes de que termine el turno
        $fin = Carbon::parse($ahora->toDateString().' '.$asistencia->turno->hora_fin);
        $antes = $ahora->lt($fin) ? (int) $ahora->diffInMinutes($fin) : 0;
        $asistencia->update(['hora_salida' => $ahora->format('H:i:s')]
            + ($antes ? ['observacion' => trim(($asistencia->observacion ? $asistencia->observacion.'. ' : '')."Salida anticipada ($antes min antes)")] : []));

        return response()->json([
            'message' => "Salida registrada a las {$ahora->format('H:i')}."
                .($antes ? " Atención: salió $antes min antes del fin de su turno (".$fin->format('H:i').').' : ''),
            'anticipada' => $antes > 0,
            'data' => $asistencia,
        ]);
    }

    /** GET /api/asistencias/permisos → permisos registrados (el auxiliar ve solo los suyos) */
    public function permisos(Request $request): JsonResponse
    {
        $q = PermisoAuxiliar::with(['auxiliar', 'registrador'])->orderByDesc('fecha')->orderByDesc('id');
        if (! $request->user()->tienePermiso('asistencia.gestionar')) {
            $q->where('auxiliar_id', $request->user()->id);
        } elseif ($request->filled('auxiliar_id')) {
            $q->where('auxiliar_id', $request->integer('auxiliar_id'));
        }

        return response()->json($q->paginate($this->porPagina($request, 10)));
    }

    /** POST /api/asistencias/permiso → el Encargado registra un permiso para un auxiliar y un día (regla INSPMS) */
    public function registrarPermiso(Request $request): JsonResponse
    {
        $d = $request->validate([
            'auxiliar_id' => ['required', 'integer', 'exists:usuarios,id'],
            'fecha' => ['required', 'date', 'after_or_equal:'.now()->subDays(60)->toDateString()],
            'motivo' => ['required', 'string', 'min:5', 'max:200'],
        ], ['fecha.after_or_equal' => 'Solo se pueden justificar fechas de los últimos 60 días.']);

        $aux = User::with('rol')->findOrFail($d['auxiliar_id']);
        abort_unless($aux->rol?->codigo === 'AUX', 422, 'El permiso solo se registra para auxiliares.');
        $ya = PermisoAuxiliar::where('auxiliar_id', $aux->id)->whereDate('fecha', $d['fecha'])->first();
        abort_if($ya, 422, "{$aux->nombre_completo} ya tiene un permiso el ".Carbon::parse($d['fecha'])->format('d/m/Y').": «{$ya?->motivo}».");

        $permiso = PermisoAuxiliar::create($d + ['registrado_por' => $request->user()->id]);
        $justificadas = ControlAsistencia::justificar($permiso);
        Notificador::enviar($aux->id, 'Se registró un permiso a su nombre',
            'Para el '.$permiso->fecha->format('d/m/Y').": {$permiso->motivo}. Sus turnos de ese día quedan justificados.", '/asistencia');

        return response()->json([
            'message' => 'Permiso registrado.'.($justificadas ? " $justificadas falta(s) de ese día pasaron a PERMISO." : ''),
            'data' => $permiso->load(['auxiliar', 'registrador']),
        ], 201);
    }

    /** DELETE /api/asistencias/permisos/{id} → solo permisos de fechas futuras (los pasados ya justificaron asistencia) */
    public function eliminarPermiso(int $id): JsonResponse
    {
        $p = PermisoAuxiliar::findOrFail($id);
        abort_if($p->fecha->format('Y-m-d') <= now()->toDateString(), 422, 'Solo se pueden anular permisos de fechas futuras.');
        $p->delete();

        return response()->json(['message' => 'Permiso anulado.']);
    }

    /** POST /api/asistencias/faltas → revisa una fecha a mano (por ejemplo, un día en que nadie abrió el sistema) */
    public function marcarFaltas(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date', 'before_or_equal:today']]);
        $fecha = Carbon::parse($d['fecha'])->startOfDay();
        $r = ControlAsistencia::registrarFaltas($fecha);

        return response()->json(['message' => "Se registraron {$r['faltas']} falta(s)".($r['permisos'] ? " y {$r['permisos']} permiso(s)" : '')
            ." para el {$fecha->format('d/m/Y')}."] + $r);
    }
}
