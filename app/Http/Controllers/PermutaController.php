<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Permuta;
use App\Models\Turno;
use App\Models\User;
use App\Services\Multas;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU5 - Permutas temporales de turno (SOLPMT / ACEPMT / APRPMT del proyecto 1).
 *
 *   1. El titular del turno SOLICITA que otro auxiliar lo cubra en una fecha          → PENDIENTE
 *   2. El reemplazante ACEPTA (→ ACEPTADA) o RECHAZA (→ RECHAZADA)
 *   3. El Encargado APRUEBA (→ APROBADA) o RECHAZA (→ RECHAZADA)
 *   El solicitante puede CANCELAR mientras no esté aprobada; el Encargado, también una aprobada.
 *
 * Reglas: solo de sus propios turnos · la fecha cae en el día del turno, dentro del semestre y antes de que empiece ·
 * el reemplazante es un auxiliar activo, distinto del titular y libre en ese horario · una permuta en curso por turno y fecha.
 * Con la permuta APROBADA, la asistencia de ese día la marca el reemplazante (ver AsistenciaController).
 */
class PermutaController extends CrudController
{
    protected string $modelo = Permuta::class;
    protected array $buscarEn = ['estado', 'motivo', 'solicitante.nombre', 'solicitante.apellido', 'reemplazante.nombre', 'reemplazante.apellido'];
    protected array $con = ['turno.laboratorio', 'solicitante', 'reemplazante', 'aprobador'];
    protected array $filtros = ['estado'];
    protected string $ordenarPor = 'fecha';

    const DIAS = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    protected function consulta(Builder $query, Request $request): Builder
    {
        // Sin "aprobar", cada auxiliar ve solo las permutas donde participa
        $yo = $request->user();
        if (! $yo->tienePermiso('permutas.aprobar')) {
            $query->where(fn ($q) => $q->where('solicitante_id', $yo->id)->orWhere('reemplazante_id', $yo->id));
        }
        // ?accion=1 → solo las que esperan algo de mí (lo que cuenta el número rojo del menú)
        if ($request->boolean('accion')) {
            $query->whereDate('fecha', '>=', now()->toDateString())
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('reemplazante_id', $yo->id)->where('estado', 'PENDIENTE'))
                    ->when($yo->tienePermiso('permutas.aprobar'), fn ($x) => $x->orWhere('estado', 'ACEPTADA')));
        }

        return $query->orderByDesc('id');
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'turno_id' => ['required', 'integer', 'exists:turnos,id'],
            'reemplazante_id' => ['required', 'integer', 'exists:usuarios,id'],
            'fecha' => ['required', 'date', 'after_or_equal:today'],
            'motivo' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $yo = $request->user();
        Multas::exigirSinVencidas($yo, 'solicitar permutas'); // CU9: bloqueo por multas vencidas
        $turno = Turno::with('semestre')->findOrFail($datos['turno_id']);
        abort_if($turno->auxiliar_id !== $yo->id, 422, 'Solo puede pedir permuta de sus propios turnos.');
        $this->validarFechaDelTurno($turno, $datos['fecha']);

        abort_if(Permuta::where('turno_id', $turno->id)->whereDate('fecha', $datos['fecha'])->whereIn('estado', Permuta::EN_CURSO)->exists(),
            422, 'Ese turno ya tiene una permuta en curso para esa fecha.');

        $reemplazante = User::with('rol')->findOrFail($datos['reemplazante_id']);
        abort_if($reemplazante->id === $yo->id, 422, 'El reemplazante debe ser otro auxiliar.');
        abort_unless($reemplazante->rol?->codigo === 'AUX' && $reemplazante->estado === 'ACTIVO', 422, 'El reemplazante debe ser un auxiliar activo.');
        $this->exigirLibre($reemplazante, $turno, $datos['fecha']);

        return $datos + ['solicitante_id' => $yo->id, 'estado' => 'PENDIENTE'];
    }

    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        /** @var Permuta $registro */
        $registro->load(['solicitante', 'reemplazante']);
        Notificador::enviar($registro->reemplazante_id, "{$registro->solicitante->nombre_completo} le pide cubrir su turno",
            "Turno {$registro->describir()}. Motivo: {$registro->motivo}. Entre a Permutas de turno para aceptar o rechazar.", '/permutas');
        $this->mensaje = "Solicitud enviada a {$registro->reemplazante->nombre_completo}. Cuando acepte, pasará al Encargado para su aprobación.";
    }

    /** Fase 2: el reemplazante acepta o rechaza.  PUT /api/permutas/{id}/responder  { aceptar: bool, observacion? } */
    public function responder(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['aceptar' => ['required', 'boolean'], 'observacion' => ['nullable', 'string', 'max:200']]);
        $p = Permuta::with(['turno', 'solicitante', 'reemplazante'])->findOrFail($id);
        abort_if($p->reemplazante_id !== $request->user()->id, 403, 'Solo el auxiliar al que se le pidió puede responder esta permuta.');
        $this->exigirEstado($p, ['PENDIENTE']);
        $this->exigirFutura($p);

        if (! $d['aceptar']) {
            $p->update(['estado' => 'RECHAZADA', 'observacion' => $d['observacion'] ?: "Rechazada por {$p->reemplazante->nombre_completo}."]);
            Notificador::enviar($p->solicitante_id, "{$p->reemplazante->nombre_completo} no puede cubrir su turno",
                "Turno {$p->describir()}. ".($d['observacion'] ?? 'Puede pedírselo a otro auxiliar.'), '/permutas');

            return response()->json(['message' => 'Permuta rechazada. Se avisó al solicitante.', 'data' => $p->fresh($this->con)]);
        }

        $this->exigirLibre($p->reemplazante, $p->turno, $p->fecha->format('Y-m-d'), $p->id);
        $p->update(['estado' => 'ACEPTADA', 'observacion' => $d['observacion'] ?? null]);
        Notificador::enviar($p->solicitante_id, "{$p->reemplazante->nombre_completo} aceptó cubrir su turno",
            "Turno {$p->describir()}. Falta la aprobación del Encargado.", '/permutas');
        Notificador::aQuienesPuedan('permutas.aprobar', "Permuta #{$p->id} por aprobar",
            "{$p->reemplazante->nombre_completo} cubrirá a {$p->solicitante->nombre_completo}: {$p->describir()}. Motivo: {$p->motivo}.", '/permutas');

        return response()->json(['message' => 'Permuta aceptada. Ahora la debe aprobar el Encargado.', 'data' => $p->fresh($this->con)]);
    }

    /** Fase 3: el Encargado aprueba.  PUT /api/permutas/{id}/aprobar */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $p = Permuta::with(['turno', 'solicitante', 'reemplazante'])->findOrFail($id);
        abort_if($p->estado === 'PENDIENTE', 422, 'El reemplazante todavía no aceptó esta permuta.');
        $this->exigirEstado($p, ['ACEPTADA']);
        $this->exigirFutura($p);
        $this->exigirLibre($p->reemplazante, $p->turno, $p->fecha->format('Y-m-d'), $p->id); // pudo cambiar algo desde que aceptó

        $p->update(['estado' => 'APROBADA', 'aprobado_por' => $request->user()->id]);
        foreach ([$p->solicitante_id, $p->reemplazante_id] as $u) {
            Notificador::enviar($u, "Permuta #{$p->id} aprobada",
                "{$p->reemplazante->nombre_completo} cubre el turno de {$p->solicitante->nombre_completo}: {$p->describir()}. "
                .'Ese día la asistencia la marca el reemplazante.', '/permutas');
        }

        return response()->json(['message' => 'Permuta aprobada. Ese día la asistencia la marca el reemplazante.', 'data' => $p->fresh($this->con)]);
    }

    /** El Encargado rechaza (pendiente o aceptada).  PUT /api/permutas/{id}/rechazar  { observacion } */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['observacion' => ['required', 'string', 'min:5', 'max:200']], ['observacion.required' => 'Indique el motivo del rechazo.']);
        $p = Permuta::findOrFail($id);
        $this->exigirEstado($p, ['PENDIENTE', 'ACEPTADA']);
        $p->update(['estado' => 'RECHAZADA', 'aprobado_por' => $request->user()->id, 'observacion' => $d['observacion']]);
        foreach ([$p->solicitante_id, $p->reemplazante_id] as $u) {
            Notificador::enviar($u, "Permuta #{$p->id} rechazada por el Encargado", "Turno {$p->describir()}. Motivo: {$d['observacion']}", '/permutas');
        }

        return response()->json(['message' => 'Permuta rechazada. Se avisó a ambos auxiliares.', 'data' => $p->fresh($this->con)]);
    }

    /** El solicitante la retira (o el Encargado la anula, incluso aprobada).  PUT /api/permutas/{id}/cancelar */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $yo = $request->user();
        $p = Permuta::with(['solicitante'])->findOrFail($id);
        $esEncargado = $yo->tienePermiso('permutas.aprobar');
        abort_unless($p->solicitante_id === $yo->id || $esEncargado, 403, 'Solo el solicitante o el Encargado pueden cancelar esta permuta.');
        $this->exigirEstado($p, $esEncargado ? Permuta::EN_CURSO : ['PENDIENTE', 'ACEPTADA']);
        $this->exigirFutura($p);
        $p->update(['estado' => 'CANCELADA', 'observacion' => "Cancelada por {$yo->nombre_completo}."]);

        $avisar = array_diff([$p->solicitante_id, $p->reemplazante_id], [$yo->id]);
        foreach ($avisar as $u) {
            Notificador::enviar($u, "Permuta #{$p->id} cancelada", "Turno {$p->describir()}. La canceló {$yo->nombre_completo}; el turno vuelve a su titular.", '/permutas');
        }

        return response()->json(['message' => 'Permuta cancelada.', 'data' => $p->fresh($this->con)]);
    }

    // ------------------------------------------------------------------ reglas compartidas

    private function validarFechaDelTurno(Turno $turno, string $fecha): void
    {
        $f = Carbon::parse($fecha);
        abort_if($turno->semestre->estado !== 'ACTIVO', 422, 'El turno no pertenece al semestre activo.');
        abort_if($f->lt($turno->semestre->fecha_inicio) || $f->gt($turno->semestre->fecha_fin), 422,
            "La fecha está fuera del semestre {$turno->semestre->gestion}.");
        abort_if($f->dayOfWeekIso !== $turno->dia_semana, 422,
            'El '.$f->format('d/m/Y').' es '.self::DIAS[$f->dayOfWeekIso].', pero el turno es los '.self::DIAS[$turno->dia_semana].'.');
        abort_if($f->isToday() && now()->format('H:i:s') >= $turno->hora_inicio, 422, 'Ese turno ya comenzó hoy: no se puede permutar.');
        abort_if(Asistencia::where('turno_id', $turno->id)->whereDate('fecha', $fecha)->exists(), 422, 'Ese turno ya tiene asistencia registrada en esa fecha.');
    }

    /**
     * El reemplazante no puede estar ocupado en ese horario: ni con un turno propio (que no haya cedido),
     * ni cubriendo ya a otro auxiliar ese mismo día.
     */
    private function exigirLibre(User $reemplazante, Turno $turno, string $fecha, ?int $ignorar = null): void
    {
        $cedidos = Permuta::where('solicitante_id', $reemplazante->id)->whereDate('fecha', $fecha)->where('estado', 'APROBADA')->pluck('turno_id');
        $propio = Turno::where('semestre_id', $turno->semestre_id)->where('auxiliar_id', $reemplazante->id)
            ->where('dia_semana', $turno->dia_semana)->whereNotIn('id', $cedidos)
            ->where('hora_inicio', '<', $turno->hora_fin)->where('hora_fin', '>', $turno->hora_inicio)->first();
        abort_if($propio, 422, "{$reemplazante->nombre_completo} tiene su propio turno de ".substr($propio?->hora_inicio, 0, 5).' a '.substr($propio?->hora_fin, 0, 5).' ese día.');

        $cubriendo = Permuta::with('turno')->where('reemplazante_id', $reemplazante->id)->whereDate('fecha', $fecha)
            ->whereIn('estado', ['ACEPTADA', 'APROBADA'])->where('id', '<>', $ignorar ?? 0)
            ->whereHas('turno', fn ($q) => $q->where('hora_inicio', '<', $turno->hora_fin)->where('hora_fin', '>', $turno->hora_inicio))->first();
        abort_if($cubriendo, 422, "{$reemplazante->nombre_completo} ya cubre otro turno en ese horario (permuta #{$cubriendo?->id}).");
    }

    private function exigirEstado(Permuta $p, array $estados): void
    {
        abort_unless(in_array($p->estado, $estados, true), 422, "La permuta está {$p->estado}: no se puede realizar esta acción.");
    }

    private function exigirFutura(Permuta $p): void
    {
        abort_if($p->fecha->format('Y-m-d') < now()->toDateString(), 422, 'La fecha de la permuta ya pasó.');
    }
}
