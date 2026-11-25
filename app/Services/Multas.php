<?php

namespace App\Services;

use App\Models\Asistencia;
use App\Models\ConceptoPago;
use App\Models\Multa;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * CU9 - Reglas de las multas.
 *
 *   ATRASO   → al marcar entrada entre los 11 y 30 min (CU7)                → MULTA-ATRASO  (Bs 10 por defecto)
 *   FALTA    → al registrarse la falta automática a los 30 min (CU7)        → MULTA-FALTA   (Bs 50 por defecto)
 *   RESERVA  → cuando el auxiliar/Encargado registra que no se utilizó (CU4) → MULTA-RESERVA (Bs 20 por defecto)
 *
 * El monto sale del concepto de cobro (el Encargado lo cambia en "Conceptos de cobro"; si lo desactiva, ese tipo ya no se multa).
 * Plazo para pagar: PAGOS_DIAS_MULTA días (7 por defecto). Pasado el plazo la multa está VENCIDA y el usuario
 * no puede solicitar reservas ni permutas hasta pagarla.
 * Un permiso posterior (CU7) anula la multa de la falta que justifica.
 */
class Multas
{
    const CONCEPTO = ['ATRASO' => 'MULTA-ATRASO', 'FALTA' => 'MULTA-FALTA', 'RESERVA' => 'MULTA-RESERVA'];

    /** Multa por un atraso o una falta (no hace nada si ya existe o si ese tipo de multa está desactivado) */
    public static function porAsistencia(Asistencia $a): ?Multa
    {
        if (! in_array($a->estado, ['ATRASO', 'FALTA'], true)) {
            return null;
        }
        $a->loadMissing('turno.laboratorio');
        $turno = substr($a->turno->hora_inicio, 0, 5).'–'.substr($a->turno->hora_fin, 0, 5).' ('.($a->turno->laboratorio?->codigo ?? 'Módulo').')';
        $motivo = ($a->estado === 'ATRASO' ? 'Atraso' : 'Falta sin justificar').' en el turno '.$turno.' del '.$a->fecha->format('d/m/Y')
            .($a->estado === 'ATRASO' && $a->hora_entrada ? ', entrada a las '.substr($a->hora_entrada, 0, 5) : '');

        return self::generar($a->estado, $a->auxiliar_id, $motivo, $a->fecha->toDateString(), ['asistencia_id' => $a->id]);
    }

    /** Multa por una reserva aprobada que no se utilizó */
    public static function porReservaNoUtilizada(Reserva $r): ?Multa
    {
        $r->loadMissing(['laboratorio', 'solicitante']);
        // Quien aprueba reservas (Encargado) no se multa a sí mismo
        if ($r->solicitante?->tienePermiso('reservas.aprobar')) {
            return null;
        }
        $motivo = "Reserva #{$r->id} del {$r->laboratorio->codigo} el ".$r->fecha->format('d/m/Y').' de '.substr($r->hora_inicio, 0, 5)
            .' a '.substr($r->hora_fin, 0, 5).' no utilizada ni cancelada';

        return self::generar('RESERVA', $r->solicitante_id, $motivo, $r->fecha->toDateString(), ['reserva_id' => $r->id]);
    }

    private static function generar(string $tipo, int $usuarioId, string $motivo, string $fecha, array $origen): ?Multa
    {
        $concepto = ConceptoPago::where('codigo', self::CONCEPTO[$tipo])->where('activo', true)->first();
        if (! $concepto || Multa::where($origen)->exists()) {
            return null;
        }
        $multa = Multa::create($origen + [
            'usuario_id' => $usuarioId, 'tipo' => $tipo, 'concepto_id' => $concepto->id, 'monto' => $concepto->monto,
            'motivo' => mb_substr($motivo, 0, 200), 'fecha' => $fecha, 'estado' => 'PENDIENTE',
            'vence_en' => today()->addDays(self::diasDePlazo()),
        ]);
        Notificador::enviar($usuarioId, 'Se registró una multa de Bs '.number_format((float) $multa->monto, 2),
            "$motivo. Puede pagarla con QR hasta el ".$multa->vence_en->format('d/m/Y').'; si vence sin pagar no podrá solicitar reservas ni permutas.',
            '/multas');

        return $multa;
    }

    /** Anula las multas PENDIENTES de esas asistencias (ej: un permiso justificó las faltas). Devuelve cuántas. */
    public static function anularPorAsistencias(Collection|array $asistenciaIds, string $motivo, ?int $porUsuarioId): int
    {
        $multas = Multa::whereIn('asistencia_id', collect($asistenciaIds))->where('estado', 'PENDIENTE')->get();
        foreach ($multas as $m) {
            self::anular($m, $motivo, $porUsuarioId);
        }

        return $multas->count();
    }

    public static function anular(Multa $m, string $motivo, ?int $porUsuarioId): void
    {
        $m->update(['estado' => 'ANULADA', 'anulada_por' => $porUsuarioId, 'observacion' => mb_substr($motivo, 0, 200)]);
        Notificador::enviar($m->usuario_id, 'Se anuló una multa de Bs '.number_format((float) $m->monto, 2), "{$m->motivo}. Motivo: $motivo", '/multas');
    }

    /** Multas vencidas del usuario: [cantidad, monto total] */
    public static function vencidas(int $usuarioId): array
    {
        $q = Multa::where('usuario_id', $usuarioId)->where('estado', 'PENDIENTE')->whereDate('vence_en', '<', today());

        return [(clone $q)->count(), (float) (clone $q)->sum('monto')];
    }

    /** Bloqueo: con multas vencidas no se puede realizar la acción */
    public static function exigirSinVencidas(User $u, string $accion): void
    {
        [$n, $total] = self::vencidas($u->id);
        abort_if($n > 0, 422, "Tiene $n multa(s) vencida(s) por Bs ".number_format($total, 2).". Páguela(s) en Pagos → Multas para poder $accion.");
    }

    public static function diasDePlazo(): int
    {
        return max(1, (int) config('pagos.dias_multa', 7));
    }
}
