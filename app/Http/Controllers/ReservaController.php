<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use App\Models\Multa;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Services\Disponibilidad;
use App\Services\Multas;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU4 - Reservas, aprobación y resolución de conflictos.
 * Flujo: solicitar (PENDIENTE) → el Encargado aprueba o rechaza → el solicitante puede cancelar.
 *  - Si quien reserva puede aprobar (Encargado), su reserva queda APROBADA directamente.
 *  - Al aprobar una reserva, las demás solicitudes PENDIENTES que piden ese laboratorio y horario se rechazan solas.
 *  - Ante un choque se proponen laboratorios libres en la misma franja (botones en el formulario).
 * Regla FICCT: la clase del horario base tiene prioridad (ver HorarioController: cancela las reservas que pisa).
 */
class ReservaController extends CrudController
{
    protected string $modelo = Reserva::class;
    protected array $buscarEn = ['motivo', 'tipo', 'estado', 'laboratorio.codigo', 'solicitante.nombre', 'solicitante.apellido'];
    protected array $con = ['laboratorio', 'solicitante', 'resolutor'];
    protected array $filtros = ['estado', 'laboratorio_id', 'tipo', 'uso'];
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
        Multas::exigirSinVencidas($request->user(), 'solicitar reservas'); // CU9: bloqueo por multas vencidas
        $lab = Laboratorio::findOrFail($datos['laboratorio_id']);
        abort_if($lab->estado !== 'DISPONIBLE', 422, "El laboratorio {$lab->codigo} está en estado {$lab->estado} y no acepta reservas.");
        abort_if(date('N', strtotime($datos['fecha'])) == 7, 422, 'Los laboratorios no atienden los domingos.');

        // Se puede reservar en cualquier fecha futura, haya o no semestre activo (entre semestres hay PSA, CUP,
        // cursos de verano…). Las clases del horario base solo ocupan los laboratorios dentro de su semestre.
        abort_if($datos['fecha'] > now()->addYear()->format('Y-m-d'), 422, 'Solo se puede reservar con hasta un año de anticipación.');

        $conflictos = Disponibilidad::conflictos($lab->id, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin']);
        if ($conflictos) {
            $alternativas = Disponibilidad::alternativas($datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $lab->id);
            abort(response()->json([
                'message' => "El laboratorio {$lab->codigo} no está disponible: ".$conflictos[0]['detalle'].'.',
                'conflictos' => $conflictos,
                'sugerencias' => array_map(fn ($a) => "{$a['codigo']} - {$a['nombre']}", $alternativas),
                'alternativas' => array_map(fn ($a) => $a + ['campo' => 'laboratorio_id'], $alternativas),
            ], 422));
        }

        $usuario = $request->user();
        $datos['solicitante_id'] = $usuario->id;
        $datos['estado'] = 'PENDIENTE';
        // El Encargado no necesita aprobarse a sí mismo
        if ($usuario->tienePermiso('reservas.aprobar')) {
            $datos['estado'] = 'APROBADA';
            $datos['resuelto_por'] = $usuario->id;
            $datos['observacion'] = 'Aprobada al registrarla (la registró quien aprueba reservas).';
        }

        return $datos;
    }

    /** Solicitud nueva → aviso a los Encargados. Reserva del Encargado (ya aprobada) → se rechazan las solicitudes que chocan. */
    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        if ($nuevo && $registro->estado === 'APROBADA') {
            $n = $this->rechazarSolicitudesQueChocan($registro, $request->user()->id);
            $this->mensaje = 'Reserva registrada y aprobada.'.($n ? " Se rechazaron $n solicitud(es) pendiente(s) que pedían ese horario." : '');

            return;
        }
        if ($nuevo) {
            $this->mensaje = 'Solicitud enviada. El Encargado de Laboratorios la revisará y recibirá una notificación.';
            $r = $registro->load(['laboratorio', 'solicitante']);
            Notificador::aQuienesPuedan('reservas.aprobar', "Nueva solicitud de reserva #{$r->id}",
                "{$r->solicitante->nombre_completo} solicita {$r->laboratorio->codigo} el ".$r->fecha->format('d/m/Y')
                .' de '.substr($r->hora_inicio, 0, 5).' a '.substr($r->hora_fin, 0, 5)." para {$r->tipo}: {$r->motivo}.",
                '/reservas', $r->id, $request->user()->id);
        }
    }

    /**
     * Una vez aprobada una reserva, las demás solicitudes PENDIENTES del mismo laboratorio, fecha y horario
     * ya no se pueden cumplir: se rechazan y se avisa a cada solicitante.
     */
    private function rechazarSolicitudesQueChocan(Reserva $aprobada, int $porUsuarioId): int
    {
        $otras = Reserva::with('laboratorio')
            ->where('laboratorio_id', $aprobada->laboratorio_id)->whereDate('fecha', $aprobada->fecha->format('Y-m-d'))
            ->where('estado', 'PENDIENTE')->where('id', '<>', $aprobada->id)
            ->where('hora_inicio', '<', $aprobada->hora_fin)->where('hora_fin', '>', $aprobada->hora_inicio)
            ->get();
        foreach ($otras as $r) {
            $motivo = "El laboratorio fue asignado a la reserva #{$aprobada->id} ({$aprobada->tipo}) en ese horario.";
            $r->update(['estado' => 'RECHAZADA', 'resuelto_por' => $porUsuarioId, 'observacion' => $motivo]);
            $this->avisarSolicitante($r, "Su reserva #{$r->id} fue rechazada", "$motivo Puede elegir otro laboratorio u horario en Disponibilidad.");
        }

        return $otras->count();
    }

    private function avisarSolicitante(Reserva $r, string $titulo, string $mensaje): void
    {
        $r->loadMissing('laboratorio');
        Notificador::enviar($r->solicitante_id, $titulo,
            "{$r->laboratorio->codigo} el ".$r->fecha->format('d/m/Y').' de '.substr($r->hora_inicio, 0, 5).' a '.substr($r->hora_fin, 0, 5)." ({$r->motivo}). $mensaje",
            '/reservas', $r->id);
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

        $rechazadas = DB::transaction(function () use ($reserva, $request) {
            // El trigger tg_reserva_sin_choque vuelve a verificar en la base de datos
            $reserva->update(['estado' => 'APROBADA', 'resuelto_por' => $request->user()->id, 'observacion' => $request->input('observacion')]);
            $this->avisarSolicitante($reserva, "Su reserva #{$reserva->id} fue APROBADA", 'El laboratorio queda reservado para usted.');

            return $this->rechazarSolicitudesQueChocan($reserva, $request->user()->id);
        });

        return response()->json([
            'message' => 'Reserva aprobada.'.($rechazadas ? " Se rechazaron $rechazadas solicitud(es) que pedían el mismo horario y se avisó a sus solicitantes." : ''),
            'data' => $reserva->fresh($this->con),
        ]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['observacion' => ['required', 'string', 'min:5', 'max:200']],
            ['observacion.required' => 'Indique el motivo del rechazo.']);
        $reserva = Reserva::findOrFail($id);
        abort_if($reserva->estado !== 'PENDIENTE', 422, "La reserva ya fue resuelta (estado {$reserva->estado}).");
        $reserva->update(['estado' => 'RECHAZADA', 'resuelto_por' => $request->user()->id, 'observacion' => $datos['observacion']]);
        $this->avisarSolicitante($reserva, "Su reserva #{$reserva->id} fue rechazada", "Motivo: {$datos['observacion']}");

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
        if ($reserva->solicitante_id !== $usuario->id) { // la canceló el Encargado: se avisa al solicitante
            $this->avisarSolicitante($reserva, "Su reserva #{$reserva->id} fue cancelada", "La canceló {$usuario->nombre_completo}.");
        }

        return response()->json(['message' => 'Reserva cancelada.', 'data' => $reserva->fresh($this->con)]);
    }

    /** Minutos de tolerancia después del inicio antes de poder marcar "no se presentó" */
    const TOLERANCIA_USO_MIN = 15;
    /** Días hacia atrás en los que todavía se puede registrar el uso */
    const DIAS_REGISTRO_USO = 7;

    /**
     * CU9 - El auxiliar o el Encargado registra si la reserva se utilizó.  PUT /api/reservas/{id}/uso  { uso, observacion? }
     *   NO_UTILIZADA → multa al solicitante (MULTA-RESERVA)   ·   corregir a UTILIZADA anula esa multa si sigue pendiente
     */
    public function registrarUso(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['uso' => ['required', Rule::in(['UTILIZADA', 'NO_UTILIZADA'])], 'observacion' => ['nullable', 'string', 'max:200']]);
        $r = Reserva::with(['laboratorio', 'solicitante'])->findOrFail($id);
        abort_if($r->estado !== 'APROBADA', 422, "Solo se registra el uso de reservas aprobadas (esta está {$r->estado}).");
        $inicio = \Carbon\Carbon::parse($r->fecha->format('Y-m-d').' '.$r->hora_inicio);
        abort_if(now()->lt($inicio->copy()->addMinutes($d['uso'] === 'NO_UTILIZADA' ? self::TOLERANCIA_USO_MIN : 0)), 422,
            $d['uso'] === 'NO_UTILIZADA'
                ? 'Todavía no puede marcarla como no utilizada: espere hasta las '.$inicio->copy()->addMinutes(self::TOLERANCIA_USO_MIN)->format('H:i').' del '.$r->fecha->format('d/m/Y').' (15 min de tolerancia).'
                : 'La reserva todavía no comenzó.');
        abort_if($r->fecha->lt(today()->subDays(self::DIAS_REGISTRO_USO)), 422, 'Pasaron más de '.self::DIAS_REGISTRO_USO.' días: ya no se puede registrar el uso.');

        $multa = null;
        DB::transaction(function () use ($r, $d, $request, &$multa) {
            $r->update(['uso' => $d['uso'], 'uso_registrado_por' => $request->user()->id, 'uso_registrado_en' => now()]
                + (! empty($d['observacion']) ? ['observacion' => $d['observacion']] : []));
            if ($d['uso'] === 'NO_UTILIZADA') {
                $multa = Multas::porReservaNoUtilizada($r);
            } elseif ($m = \App\Models\Multa::where('reserva_id', $r->id)->where('estado', 'PENDIENTE')->first()) {
                Multas::anular($m, 'Se corrigió: la reserva sí se utilizó.', $request->user()->id);
            }
        });

        return response()->json([
            'message' => $d['uso'] === 'UTILIZADA' ? 'Se registró que la reserva se utilizó.'
                : 'Se registró que la reserva no se utilizó.'.($multa ? " Se generó una multa de Bs ".number_format((float) $multa->monto, 2)." a {$r->solicitante->nombre_completo}." : ''),
            'data' => $r->fresh($this->con),
        ]);
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
