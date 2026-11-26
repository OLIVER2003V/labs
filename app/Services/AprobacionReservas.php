<?php

namespace App\Services;

use App\Models\Reserva;
use App\Models\User;
use App\Support\Prioridad;
use Illuminate\Support\Facades\DB;

/**
 * CU4 - Aprobar una reserva aplicando la JERARQUÍA (App\Support\Prioridad). Se usa al aprobar a mano (Encargado),
 * cuando la registra el propio Encargado y con la aprobación automática de docentes y director.
 *
 *   1. Si algo que NO se puede desplazar ocupa el horario (feriado, bloqueo, reserva de igual o mayor prioridad) → no se aprueba.
 *   2. Las reservas de MENOR prioridad que se cruzan se cancelan y se avisa a sus solicitantes.
 *   3. Las CLASES que se cruzan quedan suspendidas ese día y se avisa al docente.
 *   4. Se aprueba; las solicitudes pendientes de igual o menor prioridad en ese horario se rechazan.
 *   5. Se asignan los auxiliares pedidos (AsignadorAuxiliares).
 */
class AprobacionReservas
{
    /** @return array{desplazadas: int, clases: int, rechazadas: int, auxiliares: int, faltan: int} */
    public static function aprobar(Reserva $r, User $por, ?string $observacion = null): array
    {
        $r->loadMissing(['laboratorio', 'solicitante']);
        $fecha = $r->fecha->format('Y-m-d');
        $conflictos = Disponibilidad::conflictos($r->laboratorio_id, $fecha, $r->hora_inicio, $r->hora_fin, $r->id, $r->tipo);
        $fijo = collect($conflictos)->first(fn ($c) => ! $c['desplazable']);
        if ($fijo) {
            abort(response()->json([
                'message' => 'No se puede aprobar: '.$fijo['detalle'].' ocupa ese horario y no tiene menor prioridad que '.Prioridad::nombre($r->tipo).'.',
                'conflictos' => $conflictos,
                'sugerencias' => Disponibilidad::libres($fecha, $r->hora_inicio, $r->hora_fin, $r->laboratorio_id),
            ], 422));
        }

        return DB::transaction(function () use ($r, $por, $observacion, $conflictos, $fecha) {
            $quien = Prioridad::nombre($r->tipo)." (reserva #{$r->id})";
            $desplazadas = Reserva::with(['laboratorio', 'solicitante'])
                ->whereIn('id', collect($conflictos)->where('tipo', 'RESERVA')->pluck('reserva_id'))->get();
            Notificador::cancelarReservasAfectadas($desplazadas, "el laboratorio fue asignado a $quien, que tiene mayor prioridad", $por->id);

            $clases = collect($conflictos)->where('tipo', 'CLASE');
            foreach ($clases as $c) {
                Notificador::enviar($c['docente_id'], 'Su clase del '.$r->fecha->format('d/m/Y').' se suspende',
                    "{$c['detalle']} de {$c['hora_inicio']} a {$c['hora_fin']} en {$r->laboratorio->codigo}: ese día el laboratorio "
                    ."está asignado a $quien, que tiene prioridad sobre las clases. Coordine con sus estudiantes.", '/disponibilidad', $r->id);
            }

            // El trigger tg_reserva_sin_choque vuelve a verificar en la base de datos
            $r->update(['estado' => 'APROBADA', 'resuelto_por' => $por->id, 'observacion' => $observacion ?: $r->observacion]);
            if ($r->solicitante_id !== $por->id) {
                Notificador::enviar($r->solicitante_id, "Su reserva #{$r->id} fue APROBADA",
                    AsignadorAuxiliares::describir($r)." ({$r->motivo}). El laboratorio queda reservado para usted.", '/reservas', $r->id);
            }

            // Pendientes que piden ese horario con igual o menor prioridad: ya no se pueden cumplir
            $pendientes = Reserva::with('laboratorio')->where('laboratorio_id', $r->laboratorio_id)->whereDate('fecha', $fecha)
                ->where('estado', 'PENDIENTE')->where('id', '<>', $r->id)
                ->where('hora_inicio', '<', $r->hora_fin)->where('hora_fin', '>', $r->hora_inicio)->get()
                ->filter(fn ($p) => Prioridad::nivel($p->tipo) >= Prioridad::nivel($r->tipo));
            foreach ($pendientes as $p) {
                $motivo = "El laboratorio fue asignado a $quien en ese horario.";
                $p->update(['estado' => 'RECHAZADA', 'resuelto_por' => $por->id, 'observacion' => $motivo]);
                Notificador::enviar($p->solicitante_id, "Su reserva #{$p->id} fue rechazada",
                    AsignadorAuxiliares::describir($p)." ({$p->motivo}). $motivo Puede elegir otro laboratorio u horario en Disponibilidad.", '/reservas', $p->id);
            }

            $aux = AsignadorAuxiliares::asignar($r);

            return ['desplazadas' => $desplazadas->count(), 'clases' => $clases->count(), 'rechazadas' => $pendientes->count(),
                'auxiliares' => $aux['asignados'], 'faltan' => $aux['faltan']];
        });
    }

    /** Texto para el usuario con lo que pasó al aprobar */
    public static function resumen(array $r): string
    {
        return collect([
            $r['desplazadas'] ? "Se cancelaron {$r['desplazadas']} reserva(s) de menor prioridad." : null,
            $r['clases'] ? "Se suspendió {$r['clases']} clase(s) ese día y se avisó al docente." : null,
            $r['rechazadas'] ? "Se rechazaron {$r['rechazadas']} solicitud(es) que pedían ese horario." : null,
            $r['auxiliares'] ? "Se asignaron {$r['auxiliares']} auxiliar(es) con turno a esa hora." : null,
            $r['faltan'] ? "Faltan {$r['faltan']} auxiliar(es): los asigna el Encargado." : null,
        ])->filter()->implode(' ');
    }
}
