<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Services\Disponibilidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU4 - Reservas, aprobación y resolución de conflictos.
 * Flujo: solicitar (PENDIENTE) → el Encargado aprueba o rechaza → el solicitante puede cancelar.
 * Regla FICCT: ante un choque conserva el laboratorio el docente con horario asignado; se proponen laboratorios libres.
 */
class ReservaController extends CrudController
{
    protected string $modelo = Reserva::class;
    protected array $buscarEn = ['motivo', 'tipo', 'estado', 'laboratorio.codigo', 'solicitante.nombre', 'solicitante.apellido'];
    protected array $con = ['laboratorio', 'solicitante', 'resolutor'];
    protected array $filtros = ['estado', 'laboratorio_id', 'tipo'];
    protected string $ordenarPor = 'fecha';

    protected function consulta(Builder $query, Request $request): Builder
    {
        // Sin el permiso "ver todas", cada usuario ve solo sus propias reservas
        if (! $request->user()->tienePermiso('reservas.ver_todas')) {
            $query->where('solicitante_id', $request->user()->id);
        }

        return $query->orderBy('hora_inicio', 'desc');
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'fecha' => ['required', 'date', 'after_or_equal:today'],
            'hora_inicio' => ['required', 'date_format:H:i', 'after_or_equal:07:00'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio', 'before_or_equal:22:00'],
            'tipo' => ['required', Rule::in(['EXAMEN', 'RECUPERACION', 'PRACTICA', 'EVENTO', 'DEFENSA'])],
            'motivo' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $lab = Laboratorio::findOrFail($datos['laboratorio_id']);
        abort_if($lab->estado !== 'DISPONIBLE', 422, "El laboratorio {$lab->codigo} está en estado {$lab->estado} y no acepta reservas.");
        abort_if(date('N', strtotime($datos['fecha'])) == 7, 422, 'Los laboratorios no atienden los domingos.');

        $semestre = Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo.');
        abort_if($datos['fecha'] < $semestre->fecha_inicio->format('Y-m-d') || $datos['fecha'] > $semestre->fecha_fin->format('Y-m-d'),
            422, "La fecha está fuera del semestre activo ({$semestre->gestion}).");

        $conflictos = Disponibilidad::conflictos($lab->id, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin']);
        if ($conflictos) {
            $libres = Disponibilidad::libres($datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $lab->id);
            abort(response()->json([
                'message' => "El laboratorio {$lab->codigo} no está disponible: ".$conflictos[0]['detalle'].'.',
                'conflictos' => $conflictos,
                'sugerencias' => $libres,
            ], 422));
        }

        $datos['solicitante_id'] = $request->user()->id;
        $datos['estado'] = 'PENDIENTE';

        return $datos;
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        $reserva = Reserva::findOrFail($id);
        abort_if($reserva->estado !== 'PENDIENTE', 422, "La reserva ya fue resuelta (estado {$reserva->estado}).");
        abort_if($reserva->fecha->format('Y-m-d') < now()->format('Y-m-d'), 422, 'No se puede aprobar una reserva de una fecha que ya pasó. Recházela.');

        $conflictos = Disponibilidad::conflictos($reserva->laboratorio_id, $reserva->fecha->format('Y-m-d'),
            $reserva->hora_inicio, $reserva->hora_fin, $reserva->id);
        if ($conflictos) {
            abort(response()->json([
                'message' => 'No se puede aprobar: '.$conflictos[0]['detalle'].'.',
                'conflictos' => $conflictos,
                'sugerencias' => Disponibilidad::libres($reserva->fecha->format('Y-m-d'), $reserva->hora_inicio, $reserva->hora_fin, $reserva->laboratorio_id),
            ], 422));
        }

        // El trigger tg_reserva_sin_choque vuelve a verificar en la base de datos
        $reserva->update(['estado' => 'APROBADA', 'resuelto_por' => $request->user()->id, 'observacion' => $request->input('observacion')]);

        return response()->json(['message' => 'Reserva aprobada.', 'data' => $reserva->fresh($this->con)]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['observacion' => ['required', 'string', 'min:5', 'max:200']],
            ['observacion.required' => 'Indique el motivo del rechazo.']);
        $reserva = Reserva::findOrFail($id);
        abort_if($reserva->estado !== 'PENDIENTE', 422, "La reserva ya fue resuelta (estado {$reserva->estado}).");
        $reserva->update(['estado' => 'RECHAZADA', 'resuelto_por' => $request->user()->id, 'observacion' => $datos['observacion']]);

        return response()->json(['message' => 'Reserva rechazada.', 'data' => $reserva->fresh($this->con)]);
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        $reserva = Reserva::findOrFail($id);
        $usuario = $request->user();
        abort_if($reserva->solicitante_id !== $usuario->id && ! $usuario->tienePermiso('reservas.aprobar'),
            403, 'Solo puede cancelar sus propias reservas.');
        abort_unless(in_array($reserva->estado, ['PENDIENTE', 'APROBADA']), 422, 'Solo se pueden cancelar reservas pendientes o aprobadas.');
        abort_if($reserva->fecha->format('Y-m-d') < now()->format('Y-m-d'), 422, 'No se puede cancelar una reserva de una fecha pasada.');
        $reserva->update(['estado' => 'CANCELADA']);

        return response()->json(['message' => 'Reserva cancelada.', 'data' => $reserva->fresh($this->con)]);
    }

    /** GET /api/disponibilidad?fecha=&hora_inicio=&hora_fin= */
    public function disponibilidad(Request $request): JsonResponse
    {
        $d = $request->validate([
            'fecha' => ['required', 'date'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
        ]);

        return response()->json(Disponibilidad::franja($d['fecha'], $d['hora_inicio'], $d['hora_fin']));
    }

    /** GET /api/disponibilidad/agenda?fecha= */
    public function agenda(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date']]);

        return response()->json(Disponibilidad::agenda($d['fecha']));
    }
}
