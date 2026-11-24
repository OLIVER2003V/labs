<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Semestre;
use App\Models\Turno;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU7 - Asistencia de auxiliares.
 * Entrada: se permite desde 15 min antes del turno; hasta 10 min después = PUNTUAL, luego ATRASO.
 */
class AsistenciaController extends Controller
{
    const ANTICIPACION_MIN = 15;
    const TOLERANCIA_MIN = 10;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'estado' => ['nullable', Rule::in(['PUNTUAL', 'ATRASO', 'FALTA', 'PERMISO'])],
        ]);

        $q = Asistencia::with(['auxiliar', 'turno.laboratorio'])->orderByDesc('fecha')->orderByDesc('id');
        if (! $request->user()->tienePermiso('asistencia.gestionar')) {
            $q->where('auxiliar_id', $request->user()->id);
        } elseif ($request->filled('auxiliar_id')) {
            $q->where('auxiliar_id', $request->integer('auxiliar_id'));
        }
        $q->when($request->filled('desde'), fn ($x) => $x->whereDate('fecha', '>=', $request->desde))
          ->when($request->filled('hasta'), fn ($x) => $x->whereDate('fecha', '<=', $request->hasta))
          ->when($request->filled('estado'), fn ($x) => $x->where('estado', $request->estado));

        return response()->json($q->paginate(min((int) $request->query('per_page', 10), 100)));
    }

    /** Turno del auxiliar para hoy en el que cae la hora actual (con anticipación) */
    private function turnoVigente(int $auxiliarId, Carbon $ahora): ?Turno
    {
        $semestre = Semestre::activo();
        if (! $semestre) {
            return null;
        }
        $hora = $ahora->format('H:i:s');
        $limite = $ahora->copy()->addMinutes(self::ANTICIPACION_MIN)->format('H:i:s');

        return Turno::where('semestre_id', $semestre->id)
            ->where('auxiliar_id', $auxiliarId)
            ->where('dia_semana', $ahora->dayOfWeekIso)
            ->where('hora_inicio', '<=', $limite)
            ->where('hora_fin', '>', $hora)
            ->orderBy('hora_inicio')
            ->first();
    }

    public function marcarEntrada(Request $request): JsonResponse
    {
        $ahora = now();
        $turno = $this->turnoVigente($request->user()->id, $ahora);
        abort_unless($turno, 422, 'No tiene un turno asignado en este horario ('.$ahora->format('H:i').').');

        $ya = Asistencia::where('turno_id', $turno->id)->whereDate('fecha', $ahora->toDateString())->first();
        abort_if($ya, 422, 'Ya registró su entrada para este turno a las '.substr((string) $ya?->hora_entrada, 0, 5).'.');

        $limitePuntual = Carbon::parse($ahora->toDateString().' '.$turno->hora_inicio)->addMinutes(self::TOLERANCIA_MIN);
        $estado = $ahora->lte($limitePuntual) ? 'PUNTUAL' : 'ATRASO';

        $asistencia = Asistencia::create([
            'turno_id' => $turno->id,
            'auxiliar_id' => $request->user()->id,
            'fecha' => $ahora->toDateString(),
            'hora_entrada' => $ahora->format('H:i:s'),
            'estado' => $estado,
        ]);

        return response()->json([
            'message' => "Entrada registrada a las {$ahora->format('H:i')} ({$estado}).",
            'data' => $asistencia,
        ], 201);
    }

    public function marcarSalida(Request $request): JsonResponse
    {
        $ahora = now();
        $asistencia = Asistencia::where('auxiliar_id', $request->user()->id)
            ->whereDate('fecha', $ahora->toDateString())
            ->whereNotNull('hora_entrada')->whereNull('hora_salida')
            ->latest('id')->first();
        abort_unless($asistencia, 422, 'No tiene una entrada abierta hoy. Registre primero su entrada.');
        $asistencia->update(['hora_salida' => $ahora->format('H:i:s')]);

        return response()->json(['message' => "Salida registrada a las {$ahora->format('H:i')}.", 'data' => $asistencia]);
    }

    /** El Encargado registra un permiso justificado */
    public function registrarPermiso(Request $request): JsonResponse
    {
        $d = $request->validate([
            'turno_id' => ['required', 'integer', 'exists:turnos,id'],
            'fecha' => ['required', 'date'],
            'observacion' => ['required', 'string', 'min:5', 'max:200'],
        ]);
        $turno = Turno::findOrFail($d['turno_id']);
        abort_if(Carbon::parse($d['fecha'])->dayOfWeekIso !== $turno->dia_semana, 422, 'La fecha no corresponde al día del turno.');

        $asistencia = Asistencia::updateOrCreate(
            ['turno_id' => $turno->id, 'fecha' => $d['fecha']],
            ['auxiliar_id' => $turno->auxiliar_id, 'estado' => 'PERMISO', 'observacion' => $d['observacion']]
        );

        return response()->json(['message' => 'Permiso registrado.', 'data' => $asistencia], 201);
    }

    /** Marca FALTA a los turnos de una fecha que terminaron sin registro de entrada */
    public function marcarFaltas(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date', 'before_or_equal:today']]);
        $fecha = Carbon::parse($d['fecha']);
        $semestre = Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo.');

        $turnos = Turno::where('semestre_id', $semestre->id)->where('dia_semana', $fecha->dayOfWeekIso)
            ->when($fecha->isToday(), fn ($q) => $q->where('hora_fin', '<', now()->format('H:i:s')))
            ->get();

        $n = 0;
        foreach ($turnos as $t) {
            $creada = Asistencia::firstOrCreate(
                ['turno_id' => $t->id, 'fecha' => $fecha->toDateString()],
                ['auxiliar_id' => $t->auxiliar_id, 'estado' => 'FALTA', 'observacion' => 'Sin registro de entrada']
            );
            $n += $creada->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['message' => "Se registraron $n falta(s) para el {$fecha->format('d/m/Y')}."]);
    }
}
