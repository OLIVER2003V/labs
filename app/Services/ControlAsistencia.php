<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\FechaBloqueada;
use App\Models\PermisoAuxiliar;
use App\Models\Permuta;
use App\Models\Semestre;
use App\Models\Turno;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * CU7 - Reglas de asistencia de los auxiliares (MARENT / MARSAL / INSPMS del proyecto 1).
 *
 *   Entrada:  desde 15 min ANTES del inicio del turno.
 *             Hasta 10 min después → PUNTUAL · de 11 a 30 min → ATRASO · pasados 30 min ya no se puede marcar.
 *   Falta:    pasados 30 min sin entrada, el sistema registra FALTA; si el auxiliar tiene permiso ese día, PERMISO.
 *             No hay falta en un feriado o bloqueo de día completo del laboratorio.
 *   Permuta:  con una permuta APROBADA (CU5), el turno de ese día lo atiende (y responde por él) el reemplazante.
 */
class ControlAsistencia
{
    const ANTICIPACION_MIN = 15;
    const TOLERANCIA_MIN = 10;
    const LIMITE_MIN = 30;

    /** Turnos que el auxiliar debe atender en esa fecha: los suyos que no cedió + los que cubre por permuta aprobada */
    public static function turnosDe(int $auxiliarId, Carbon $fecha): Collection
    {
        $semestre = Semestre::activo();
        if (! $semestre || $fecha->lt($semestre->fecha_inicio) || $fecha->gt($semestre->fecha_fin)) {
            return collect();
        }
        $aprobadas = Permuta::whereDate('fecha', $fecha->toDateString())->where('estado', 'APROBADA');
        $cedidos = (clone $aprobadas)->where('solicitante_id', $auxiliarId)->pluck('turno_id');
        $cubre = (clone $aprobadas)->where('reemplazante_id', $auxiliarId)->pluck('turno_id');

        return Turno::with('laboratorio')->where('semestre_id', $semestre->id)->where('dia_semana', $fecha->dayOfWeekIso)
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('auxiliar_id', $auxiliarId)->whereNotIn('id', $cedidos))->orWhereIn('id', $cubre))
            ->orderBy('hora_inicio')->get()
            ->each(fn ($t) => $t->setAttribute('es_permuta', $cubre->contains($t->id)));
    }

    /** Momento desde el que se puede marcar, límite de puntualidad y límite para marcar (ej: 06:45 / 07:10 / 07:30) */
    public static function ventana(Turno $turno, Carbon $fecha): array
    {
        $inicio = Carbon::parse($fecha->toDateString().' '.$turno->hora_inicio);

        return [
            'desde' => $inicio->copy()->subMinutes(self::ANTICIPACION_MIN),
            'puntual' => $inicio->copy()->addMinutes(self::TOLERANCIA_MIN),
            'limite' => $inicio->copy()->addMinutes(self::LIMITE_MIN),
            'fin' => Carbon::parse($fecha->toDateString().' '.$turno->hora_fin),
        ];
    }

    /** Feriado o bloqueo de día completo que afecta al laboratorio del turno (ese día no se cuenta falta) */
    public static function bloqueo(Turno $turno, string $fecha): ?FechaBloqueada
    {
        return FechaBloqueada::whereDate('fecha', $fecha)->whereNull('hora_inicio')
            ->where(fn ($q) => $q->whereNull('laboratorio_id')->when($turno->laboratorio_id, fn ($x) => $x->orWhere('laboratorio_id', $turno->laboratorio_id)))
            ->first();
    }

    /**
     * Registra FALTA (o PERMISO) en los turnos de la fecha que pasaron su límite de 30 min sin entrada.
     * Se puede llamar las veces que sea: no duplica (un registro por turno y fecha).
     *
     * @return array{faltas: int, permisos: int}
     */
    public static function registrarFaltas(Carbon $fecha, ?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $semestre = Semestre::activo();
        $r = ['faltas' => 0, 'permisos' => 0];
        $futura = $fecha->isAfter($ahora) && ! $fecha->isSameDay($ahora);
        if (! $semestre || $futura || $fecha->dayOfWeekIso === 7
            || $fecha->lt($semestre->fecha_inicio) || $fecha->gt($semestre->fecha_fin)) {
            return $r;
        }
        $dia = $fecha->toDateString();
        $limite = $fecha->isSameDay($ahora) ? $ahora->copy()->subMinutes(self::LIMITE_MIN)->format('H:i:s') : '23:59:59';
        $registrados = Asistencia::whereDate('fecha', $dia)->pluck('turno_id')->flip();
        $permisos = PermisoAuxiliar::whereDate('fecha', $dia)->get()->keyBy('auxiliar_id');

        $turnos = Turno::where('semestre_id', $semestre->id)->where('dia_semana', $fecha->dayOfWeekIso)->where('hora_inicio', '<=', $limite)->get();
        foreach ($turnos as $t) {
            if (isset($registrados[$t->id]) || self::bloqueo($t, $dia)) {
                continue;
            }
            $responsable = Permuta::responsable($t, $dia);
            $permiso = $permisos[$responsable] ?? null;
            $a = Asistencia::firstOrCreate(['turno_id' => $t->id, 'fecha' => $dia], [
                'auxiliar_id' => $responsable,
                'estado' => $permiso ? 'PERMISO' : 'FALTA',
                'observacion' => $permiso ? "Permiso: {$permiso->motivo}" : 'Sin registro de entrada (pasaron '.self::LIMITE_MIN.' min del inicio)',
            ]);
            if ($a->wasRecentlyCreated) {
                $r[$permiso ? 'permisos' : 'faltas']++;
            }
        }

        return $r;
    }

    /** Justificación retroactiva: las FALTAS del auxiliar en esa fecha pasan a PERMISO. Devuelve cuántas cambió. */
    public static function justificar(PermisoAuxiliar $permiso): int
    {
        return Asistencia::where('auxiliar_id', $permiso->auxiliar_id)->whereDate('fecha', $permiso->fecha->toDateString())
            ->where('estado', 'FALTA')->update(['estado' => 'PERMISO', 'observacion' => mb_substr("Permiso: {$permiso->motivo}", 0, 200), 'updated_at' => now()]);
    }
}
