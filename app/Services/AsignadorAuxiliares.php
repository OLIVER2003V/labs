<?php

namespace App\Services;

use App\Models\PermisoAuxiliar;
use App\Models\Permuta;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\Turno;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU4 - Auxiliares de apoyo para una reserva (ej: un examen).
 * El docente o el director pide cuántos necesita (opcional). Al aprobarse la reserva, el sistema asigna solo a los
 * auxiliares cuyo TURNO coincide con el horario de la reserva (si hubo permuta ese día, al reemplazante; nunca a quien
 * tiene permiso ese día ni a quien ya apoya otra reserva a esa hora).
 *   - Si pidió hasta 5: se asignan automáticamente todos los que coincidan (hasta lo pedido).
 *   - Si pidió más de 5: se asignan 4 automáticamente y el resto lo asigna el Encargado.
 * Lo que falte (porque no hay suficientes turnos que coincidan) lo asigna el Encargado desde Reservas.
 */
class AsignadorAuxiliares
{
    const AUTOMATICOS_SI_SON_MUCHOS = 4;
    const LIMITE_AUTOMATICO = 5;

    /**
     * Auxiliares que pueden apoyar esa reserva, del más adecuado al menos:
     * primero los que su turno cubre todo el horario, y entre ellos los del mismo laboratorio.
     * @return Collection<int, array{auxiliar: User, turno: Turno, cubre: bool, mismo_lab: bool}>
     */
    public static function candidatos(Reserva $r): Collection
    {
        $fecha = $r->fecha->format('Y-m-d');
        $semestre = Semestre::activo();
        if (! $semestre || $fecha < $semestre->fecha_inicio->format('Y-m-d') || $fecha > $semestre->fecha_fin->format('Y-m-d')) {
            return collect();
        }
        $ocupados = self::ocupadosEnEseHorario($r);
        $conPermiso = PermisoAuxiliar::whereDate('fecha', $fecha)->pluck('auxiliar_id')->flip();
        $yaAsignados = DB::table('reserva_auxiliares')->where('reserva_id', $r->id)->pluck('auxiliar_id')->flip();

        return Turno::with('laboratorio')->where('semestre_id', $semestre->id)->where('dia_semana', Carbon::parse($fecha)->dayOfWeekIso)
            ->where('hora_inicio', '<', $r->hora_fin)->where('hora_fin', '>', $r->hora_inicio)->get()
            ->map(function (Turno $t) use ($r, $fecha) {
                $aux = User::find(Permuta::responsable($t, $fecha)); // con permuta aprobada ese día atiende el reemplazante

                return $aux ? ['auxiliar' => $aux, 'turno' => $t, 'mismo_lab' => $t->laboratorio_id === $r->laboratorio_id,
                    'cubre' => $t->hora_inicio <= self::hms($r->hora_inicio) && $t->hora_fin >= self::hms($r->hora_fin)] : null;
            })
            ->filter(fn ($c) => $c && $c['auxiliar']->estado === 'ACTIVO' && ! isset($conPermiso[$c['auxiliar']->id])
                && ! isset($ocupados[$c['auxiliar']->id]) && ! isset($yaAsignados[$c['auxiliar']->id]))
            ->sortByDesc(fn ($c) => ($c['cubre'] ? 2 : 0) + ($c['mismo_lab'] ? 1 : 0))
            ->unique(fn ($c) => $c['auxiliar']->id)
            ->values();
    }

    /** Asignación automática al aprobar la reserva. @return array{asignados: int, faltan: int} */
    public static function asignar(Reserva $r): array
    {
        $pedidos = (int) $r->auxiliares_solicitados;
        if ($pedidos <= 0) {
            return ['asignados' => 0, 'faltan' => 0];
        }
        $ya = $r->auxiliares()->count();
        $tope = $pedidos > self::LIMITE_AUTOMATICO ? self::AUTOMATICOS_SI_SON_MUCHOS : $pedidos;
        $elegidos = self::candidatos($r)->take(max(0, $tope - $ya));
        foreach ($elegidos as $c) {
            self::agregar($r, $c['auxiliar'], null);
        }
        $faltan = max(0, $pedidos - $ya - $elegidos->count());
        if ($faltan) {
            $r->loadMissing('laboratorio');
            Notificador::aQuienesPuedan('reservas.aprobar', "Reserva #{$r->id}: faltan $faltan auxiliar(es)",
                self::describir($r).". Se pidieron $pedidos auxiliares y se asignaron ".($ya + $elegidos->count())
                .' automáticamente (los que tienen turno a esa hora). Asigne el resto en Reservas → Auxiliares.', '/reservas', $r->id);
        }

        return ['asignados' => $elegidos->count(), 'faltan' => $faltan];
    }

    /** Asigna un auxiliar (asignadoPor NULL = automático) y le avisa */
    public static function agregar(Reserva $r, User $aux, ?int $asignadoPor): void
    {
        DB::table('reserva_auxiliares')->insertOrIgnore(['reserva_id' => $r->id, 'auxiliar_id' => $aux->id, 'asignado_por' => $asignadoPor,
            'created_at' => now(), 'updated_at' => now()]);
        Notificador::enviar($aux, 'Se le asignó apoyar una reserva', self::describir($r).". Motivo: {$r->motivo}.", '/reservas', $r->id);
    }

    /** Auxiliares que ya apoyan otra reserva aprobada que se cruza con esta (no pueden estar en dos lugares) */
    public static function ocupadosEnEseHorario(Reserva $r): Collection
    {
        return DB::table('reserva_auxiliares as ra')->join('reservas as x', 'x.id', '=', 'ra.reserva_id')
            ->where('x.id', '<>', $r->id)->where('x.estado', 'APROBADA')->whereDate('x.fecha', $r->fecha->format('Y-m-d'))
            ->where('x.hora_inicio', '<', $r->hora_fin)->where('x.hora_fin', '>', $r->hora_inicio)
            ->pluck('ra.auxiliar_id')->flip();
    }

    public static function describir(Reserva $r): string
    {
        $r->loadMissing('laboratorio');

        return \App\Support\Prioridad::nombre($r->tipo)." en {$r->laboratorio->codigo} el ".$r->fecha->format('d/m/Y')
            .' de '.substr($r->hora_inicio, 0, 5).' a '.substr($r->hora_fin, 0, 5);
    }

    private static function hms(string $h): string
    {
        return strlen($h) === 5 ? "$h:00" : $h;
    }
}
