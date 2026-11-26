<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use App\Models\Multa;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\User;
use App\Services\AprobacionReservas;
use App\Services\AsignadorAuxiliares;
use App\Services\Disponibilidad;
use App\Services\Multas;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Configuracion;
use App\Support\Prioridad;
use Illuminate\Validation\Rule;

/**
 * CU4 - Reservas, aprobación por JERARQUÍA y auxiliares de apoyo.
 * Jerarquía (App\Support\Prioridad), de mayor a menor:
 *   1. Hackathon, CUP, PSA   2. Examen   3. Capacitación externa, Conferencia   4. Clases   5. Prácticas de auxiliares de cátedra
 * Flujo: solicitar (PENDIENTE) → el Encargado aprueba o rechaza → el solicitante puede cancelar.
 *  - Si el horario está LIBRE se puede pedir en cualquier momento.
 *  - Si hay una clase o una reserva de MENOR prioridad, se puede pedir igual pero con al menos 4 días de anticipación;
 *    al aprobarse, la clase de ese día se suspende y las reservas menores se cancelan (con aviso a cada uno).
 *  - Si hay algo de IGUAL o MAYOR prioridad (o un feriado/bloqueo), no se puede: se proponen laboratorios libres.
 *  - Quien aprueba reservas (Encargado) no necesita aprobarse a sí mismo.
 *  - Interruptor "aprobación automática": si está activo, las solicitudes de docentes y del director se aprueban solas
 *    (siguiendo la misma jerarquía) y el Encargado solo recibe el aviso.
 *  - Docentes y director pueden pedir auxiliares de apoyo (AsignadorAuxiliares).
 */
class ReservaController extends CrudController
{
    protected string $modelo = Reserva::class;
    protected array $buscarEn = ['motivo', 'tipo', 'estado', 'laboratorio.codigo', 'solicitante.nombre', 'solicitante.apellido'];
    protected array $con = ['laboratorio', 'solicitante', 'resolutor', 'auxiliares:usuarios.id,nombre,apellido,codigo'];
    protected array $filtros = ['estado', 'laboratorio_id', 'tipo', 'uso'];
    protected string $ordenarPor = 'fecha';

    /** Roles cuyas solicitudes se aprueban solas cuando el interruptor está activo */
    const ROLES_APROBACION_AUTOMATICA = ['DOC', 'DIR'];

    protected function consulta(Builder $query, Request $request): Builder
    {
        $u = $request->user();
        // Sin el permiso "ver todas", cada usuario ve sus reservas y las que le tocó apoyar como auxiliar
        if (! $u->tienePermiso('reservas.ver_todas')) {
            $query->where(fn ($q) => $q->where('solicitante_id', $u->id)
                ->orWhereExists(fn ($x) => $x->selectRaw('1')->from('reserva_auxiliares as ra')
                    ->whereColumn('ra.reserva_id', 'reservas.id')->where('ra.auxiliar_id', $u->id)));
        }
        if ($request->boolean('faltan_auxiliares')) {
            $query->faltanAuxiliares();
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
            'tipo' => ['required', Rule::in(Prioridad::codigos())],
            'motivo' => ['required', 'string', 'min:5', 'max:200'],
            'auxiliares_solicitados' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
    }

    protected function atributos(): array
    {
        return ['auxiliares_solicitados' => 'auxiliares'];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $usuario = $request->user();
        Multas::exigirSinVencidas($usuario, 'solicitar reservas'); // CU9: bloqueo por multas vencidas
        $lab = Laboratorio::findOrFail($datos['laboratorio_id']);
        abort_if($lab->estado !== 'DISPONIBLE', 422, "El laboratorio {$lab->codigo} está en estado {$lab->estado} y no acepta reservas.");
        abort_if(date('N', strtotime($datos['fecha'])) == 7, 422, 'Los laboratorios no atienden los domingos.');

        // Se puede reservar en cualquier fecha futura, haya o no semestre activo (entre semestres hay PSA, CUP,
        // cursos de verano…). Las clases del horario base solo ocupan los laboratorios dentro de su semestre.
        abort_if($datos['fecha'] > now()->addYear()->format('Y-m-d'), 422, 'Solo se puede reservar con hasta un año de anticipación.');

        $conflictos = Disponibilidad::conflictos($lab->id, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], null, $datos['tipo']);
        $fijo = collect($conflictos)->first(fn ($c) => ! $c['desplazable']);
        if ($fijo) {
            $alternativas = Disponibilidad::alternativas($datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $lab->id);
            $porNivel = $fijo['tipo'] === 'BLOQUEO' ? '' : ' (tiene igual o mayor prioridad que '.Prioridad::nombre($datos['tipo']).')';
            abort(response()->json([
                'message' => "El laboratorio {$lab->codigo} no está disponible: {$fijo['detalle']}$porNivel.",
                'conflictos' => $conflictos,
                'sugerencias' => array_map(fn ($a) => "{$a['codigo']} - {$a['nombre']}", $alternativas),
                'alternativas' => array_map(fn ($a) => $a + ['campo' => 'laboratorio_id'], $alternativas),
            ], 422));
        }
        // Ocupar el lugar de una clase o de una reserva menor exige avisar con tiempo (4 días)
        $minimo = now()->startOfDay()->addDays(Prioridad::DIAS_ANTICIPACION)->format('Y-m-d');
        if ($conflictos && $datos['fecha'] < $minimo) {
            abort(response()->json([
                'message' => "En ese horario hay {$conflictos[0]['detalle']}. Para ocupar ese lugar con ".Prioridad::nombre($datos['tipo'])
                    .' debe solicitarlo con al menos '.Prioridad::DIAS_ANTICIPACION.' días de anticipación (desde el '
                    .Carbon::parse($minimo)->format('d/m/Y').'). Si es para antes, elija un laboratorio libre.',
                'conflictos' => $conflictos,
                'alternativas' => array_map(fn ($a) => $a + ['campo' => 'laboratorio_id'],
                    Disponibilidad::alternativas($datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $lab->id)),
            ], 422));
        }

        // Solo docentes y director piden auxiliares (ej: para cuidar un examen), y no más de los que hay disponibles
        $datos['auxiliares_solicitados'] = $usuario->tienePermiso('reservas.pedir_auxiliares') ? (int) ($datos['auxiliares_solicitados'] ?? 0) : 0;
        if ($datos['auxiliares_solicitados'] > 0) {
            $hay = AsignadorAuxiliares::disponibles($datos['fecha'], $datos['hora_inicio'], $datos['hora_fin']);
            if ($datos['auxiliares_solicitados'] > $hay) {
                abort(response()->json([
                    'message' => "Solo hay $hay auxiliar(es) disponible(s) el ".Carbon::parse($datos['fecha'])->format('d/m/Y')
                        ." de {$datos['hora_inicio']} a {$datos['hora_fin']}. Pida como máximo $hay.",
                    'errors' => ['auxiliares_solicitados' => ["Como máximo $hay (los auxiliares disponibles en ese horario)."]],
                ], 422));
            }
        }
        $datos['solicitante_id'] = $usuario->id;
        $datos['estado'] = 'PENDIENTE'; // se aprueba (si corresponde) en despuesDeGuardar, con la jerarquía

        return $datos;
    }

    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        if (! $nuevo) {
            return;
        }
        $u = $request->user();
        $r = $registro->load(['laboratorio', 'solicitante']);

        // 1) Quien aprueba reservas (Encargado) no necesita aprobarse a sí mismo
        if ($u->tienePermiso('reservas.aprobar')) {
            $res = AprobacionReservas::aprobar($r, $u, 'Aprobada al registrarla (la registró quien aprueba reservas).');
            $this->mensaje = trim('Reserva registrada y aprobada. '.AprobacionReservas::resumen($res));

            return;
        }

        // 2) Aprobación automática para docentes y director (interruptor del Encargado)
        if (in_array($u->rol?->codigo, self::ROLES_APROBACION_AUTOMATICA, true) && Configuracion::activo(Configuracion::APROBACION_AUTOMATICA)) {
            $res = AprobacionReservas::aprobar($r, $u, 'Aprobada automáticamente (aprobación automática activada).');
            $this->mensaje = trim('Reserva aprobada automáticamente. '.AprobacionReservas::resumen($res));
            Notificador::aQuienesPuedan('reservas.aprobar', "Reserva #{$r->id} aprobada automáticamente",
                "{$r->solicitante->nombre_completo}: ".AsignadorAuxiliares::describir($r)." ({$r->motivo}). ".AprobacionReservas::resumen($res),
                '/reservas', $r->id);

            return;
        }

        // 3) Solicitud normal → aviso a los Encargados
        $this->mensaje = 'Solicitud enviada. El Encargado de Laboratorios la revisará y recibirá una notificación.';
        $desplaza = Disponibilidad::conflictos($r->laboratorio_id, $r->fecha->format('Y-m-d'), $r->hora_inicio, $r->hora_fin, $r->id, $r->tipo);
        Notificador::aQuienesPuedan('reservas.aprobar', "Nueva solicitud de reserva #{$r->id}",
            "{$r->solicitante->nombre_completo} solicita ".AsignadorAuxiliares::describir($r).": {$r->motivo}."
            .($r->auxiliares_solicitados ? " Pide {$r->auxiliares_solicitados} auxiliar(es)." : '')
            .($desplaza ? ' Si la aprueba, desplaza: '.collect($desplaza)->pluck('detalle')->implode('; ').'.' : ''),
            '/reservas', $r->id, $u->id);
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

        $res = AprobacionReservas::aprobar($reserva, $request->user(), $request->input('observacion'));

        return response()->json([
            'message' => trim('Reserva aprobada. '.AprobacionReservas::resumen($res)),
            'data' => $reserva->fresh($this->con),
        ]);
    }

    /** GET /api/reservas/auxiliares-disponibles?fecha=&hora_inicio=&hora_fin= → cuántos auxiliares se pueden pedir */
    public function auxiliaresDisponibles(Request $request): JsonResponse
    {
        $d = $request->validate([
            'fecha' => ['required', 'date'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
        ]);

        return response()->json(['disponibles' => AsignadorAuxiliares::disponibles($d['fecha'], $d['hora_inicio'], $d['hora_fin'])]);
    }

    /** GET /api/reservas/configuracion → estado del interruptor de aprobación automática */
    public function configuracion(): JsonResponse
    {
        return response()->json(['aprobacion_automatica' => Configuracion::activo(Configuracion::APROBACION_AUTOMATICA)]);
    }

    /** PUT /api/reservas/configuracion { aprobacion_automatica: bool } */
    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $d = $request->validate(['aprobacion_automatica' => ['required', 'boolean']]);
        Configuracion::guardar(Configuracion::APROBACION_AUTOMATICA, $d['aprobacion_automatica'] ? '1' : '0');

        return response()->json([
            'aprobacion_automatica' => (bool) $d['aprobacion_automatica'],
            'message' => $d['aprobacion_automatica']
                ? 'Aprobación automática ACTIVADA: las solicitudes de docentes y del director se aprueban solas (respetando la jerarquía). Usted recibirá un aviso de cada una.'
                : 'Aprobación automática DESACTIVADA: usted aprueba cada solicitud.',
        ]);
    }

    /** GET /api/reservas/{id}/auxiliares → asignados + candidatos (auxiliares con turno que coincide) + el resto de auxiliares */
    public function auxiliares(int $id): JsonResponse
    {
        $r = Reserva::with(['laboratorio', 'auxiliares'])->findOrFail($id);
        $candidatos = AsignadorAuxiliares::candidatos($r);
        $ocupados = AsignadorAuxiliares::ocupadosEnEseHorario($r);
        $conTurno = $candidatos->pluck('auxiliar.id')->flip();
        $asignados = $r->auxiliares->pluck('id')->flip();
        $otros = User::whereHas('rol', fn ($q) => $q->where('codigo', 'AUX'))->where('estado', 'ACTIVO')->orderBy('nombre')->get()
            ->reject(fn ($u) => isset($conTurno[$u->id]) || isset($asignados[$u->id]))
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->nombre_completo, 'codigo' => $u->codigo, 'ocupado' => isset($ocupados[$u->id])])
            ->values();

        return response()->json([
            'reserva' => ['id' => $r->id, 'descripcion' => AsignadorAuxiliares::describir($r), 'estado' => $r->estado,
                'solicitados' => (int) $r->auxiliares_solicitados],
            'asignados' => $r->auxiliares->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->nombre_completo, 'codigo' => $u->codigo,
                'automatico' => $u->pivot->asignado_por === null])->values(),
            'con_turno' => $candidatos->map(fn ($c) => ['id' => $c['auxiliar']->id, 'nombre' => $c['auxiliar']->nombre_completo,
                'codigo' => $c['auxiliar']->codigo,
                'turno' => "{$c['turno']->laboratorio->codigo} ".substr($c['turno']->hora_inicio, 0, 5).'–'.substr($c['turno']->hora_fin, 0, 5),
                'cubre' => $c['cubre']])->values(),
            'otros' => $otros,
        ]);
    }

    /** POST /api/reservas/{id}/auxiliares { auxiliar_id } → el Encargado asigna uno a mano */
    public function asignarAuxiliar(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['auxiliar_id' => ['required', 'integer', 'exists:usuarios,id']]);
        $r = Reserva::with('laboratorio')->findOrFail($id);
        abort_if($r->estado !== 'APROBADA', 422, 'Solo se asignan auxiliares a reservas aprobadas.');
        abort_if($r->fecha->format('Y-m-d') < now()->format('Y-m-d'), 422, 'La reserva ya pasó.');
        $aux = User::with('rol')->findOrFail($d['auxiliar_id']);
        abort_if($aux->rol?->codigo !== 'AUX' || $aux->estado !== 'ACTIVO', 422, 'Solo se pueden asignar auxiliares activos.');
        abort_if(isset(AsignadorAuxiliares::ocupadosEnEseHorario($r)[$aux->id]), 422,
            "{$aux->nombre_completo} ya apoya otra reserva a esa hora.");
        abort_if($r->auxiliares()->where('usuarios.id', $aux->id)->exists(), 422, 'Ese auxiliar ya está asignado.');

        AsignadorAuxiliares::agregar($r, $aux, $request->user()->id);
        $total = $r->auxiliares()->count();
        $faltan = max(0, $r->auxiliares_solicitados - $total);

        return response()->json(['message' => "Se asignó a {$aux->nombre_completo} y se le avisó."
            .($faltan ? " Faltan $faltan." : ' Ya están todos los auxiliares pedidos.')]);
    }

    /** DELETE /api/reservas/{id}/auxiliares/{auxiliarId} → quitar un auxiliar asignado */
    public function quitarAuxiliar(int $id, int $auxiliarId): JsonResponse
    {
        $r = Reserva::with('laboratorio')->findOrFail($id);
        $aux = User::findOrFail($auxiliarId);
        abort_unless($r->auxiliares()->detach($aux->id), 404, 'Ese auxiliar no estaba asignado.');
        Notificador::enviar($aux, "Ya no apoya la reserva #{$r->id}", AsignadorAuxiliares::describir($r).'. El Encargado lo quitó de esa reserva.', '/reservas', $r->id);

        return response()->json(['message' => "Se quitó a {$aux->nombre_completo} de la reserva."]);
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
        foreach ($reserva->auxiliares as $aux) { // los auxiliares asignados ya no tienen que ir
            Notificador::enviar($aux, "La reserva #{$reserva->id} fue cancelada",
                AsignadorAuxiliares::describir($reserva).'. Ya no necesita apoyar esa reserva.', '/reservas', $reserva->id);
        }
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
