<?php

namespace App\Services;

use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos a los usuarios:
 *  1. Siempre: notificación dentro del sistema (tabla notificaciones → campanita del encabezado).
 *  2. Además, correo electrónico si hay un servidor de correo configurado (MAIL_MAILER=smtp en .env).
 *     Si el correo falla, la notificación interna igual queda registrada.
 */
class Notificador
{
    public static function enviar(User|int $usuario, string $titulo, string $mensaje, ?string $url = null, ?int $reservaId = null): void
    {
        $usuario = $usuario instanceof User ? $usuario : User::find($usuario);
        if (! $usuario) {
            return;
        }
        DB::table('notificaciones')->insert([
            'usuario_id' => $usuario->id, 'titulo' => mb_substr($titulo, 0, 150), 'mensaje' => mb_substr($mensaje, 0, 600),
            'url' => $url, 'reserva_id' => $reservaId, 'created_at' => now(),
        ]);

        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            try {
                Mail::raw($mensaje."\n\n— Laboratorios 4to Piso · FICCT - UAGRM", fn ($m) => $m->to($usuario->email)->subject($titulo));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Avisa a todos los usuarios activos cuyo rol tenga el permiso (ej: reservas.aprobar → Encargados) */
    public static function aQuienesPuedan(string $permiso, string $titulo, string $mensaje, ?string $url = null, ?int $reservaId = null, ?int $excepto = null): void
    {
        $ids = DB::table('usuarios')
            ->join('rol_permiso', 'rol_permiso.rol_id', '=', 'usuarios.rol_id')
            ->join('permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
            ->where('permisos.codigo', $permiso)->where('usuarios.estado', 'ACTIVO')->whereNull('usuarios.deleted_at')
            ->when($excepto, fn ($q) => $q->where('usuarios.id', '<>', $excepto))
            ->pluck('usuarios.id');
        foreach ($ids as $id) {
            self::enviar($id, $titulo, $mensaje, $url, $reservaId);
        }
    }

    /** Avisa a los Encargados de Laboratorio (rol ENC) activos, ej: cada pago recibido */
    public static function aEncargados(string $titulo, string $mensaje, ?string $url = null): void
    {
        User::whereHas('rol', fn ($q) => $q->where('codigo', \App\Support\Encargado::ROL))->where('estado', 'ACTIVO')
            ->get()->each(fn ($u) => self::enviar($u, $titulo, $mensaje, $url));
    }

    /**
     * Cancela reservas afectadas por un bloqueo o por un laboratorio fuera de servicio
     * y avisa a cada solicitante con el motivo.
     */
    public static function cancelarReservasAfectadas(Collection $reservas, string $motivo, ?int $porUsuarioId): int
    {
        foreach ($reservas as $r) {
            /** @var Reserva $r */
            $r->update(['estado' => 'CANCELADA', 'resuelto_por' => $porUsuarioId, 'observacion' => mb_substr("Cancelada por el sistema: $motivo", 0, 200)]);
            self::enviar(
                $r->solicitante_id,
                "Su reserva #{$r->id} fue cancelada",
                "Su reserva del {$r->laboratorio->codigo} para el ".$r->fecha->format('d/m/Y').' de '.substr($r->hora_inicio, 0, 5).' a '.substr($r->hora_fin, 0, 5)
                ." ({$r->motivo}) fue cancelada. Motivo: $motivo. Puede solicitar otra fecha o laboratorio desde Disponibilidad.",
                '/reservas',
                $r->id,
            );
        }

        return $reservas->count();
    }

    /** Texto corto de una reserva para mostrarla en una lista de confirmación */
    public static function describir(Reserva $r): string
    {
        return "#{$r->id} · {$r->laboratorio->codigo} · ".$r->fecha->format('d/m/Y').' '.substr($r->hora_inicio, 0, 5).'–'.substr($r->hora_fin, 0, 5)
            ." · {$r->solicitante->nombre_completo} · {$r->motivo} ({$r->estado})";
    }
}
