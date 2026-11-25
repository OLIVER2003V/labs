<?php

namespace App\Services;

use App\Models\FechaBloqueada;
use App\Models\Horario;
use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use Carbon\Carbon;

/**
 * Reglas de disponibilidad (CU3/CU4).
 * Un laboratorio está ocupado en una franja si:
 *   - la fecha está bloqueada (feriado, PSA, mantenimiento) para ese laboratorio o para todos,
 *   - tiene una clase del horario base ese día de la semana (semestre activo),
 *   - o tiene una reserva APROBADA que se superpone.
 * Dos franjas se superponen cuando: inicioA < finB  y  inicioB < finA.
 */
class Disponibilidad
{
    /** @return array<int, array{tipo:string, detalle:string, hora_inicio?:string, hora_fin?:string}> */
    public static function conflictos(int $laboratorioId, string $fecha, string $horaInicio, string $horaFin, ?int $ignorarReservaId = null): array
    {
        $conflictos = [];
        // Normalizamos a HH:MM:SS para comparar como texto igual que PostgreSQL ("10:00" → "10:00:00")
        $horaInicio = self::hms($horaInicio);
        $horaFin = self::hms($horaFin);

        $bloqueos = FechaBloqueada::whereDate('fecha', $fecha)
            ->where(fn ($q) => $q->whereNull('laboratorio_id')->orWhere('laboratorio_id', $laboratorioId))
            ->get();
        foreach ($bloqueos as $b) {
            if ($b->seCruzaCon($horaInicio, $horaFin)) {
                $conflictos[] = ['tipo' => 'BLOQUEO', 'detalle' => "Fecha bloqueada ({$b->tipo}, {$b->horario()}): {$b->motivo}"]
                    + ($b->esDiaCompleto() ? [] : ['hora_inicio' => substr($b->hora_inicio, 0, 5), 'hora_fin' => substr($b->hora_fin, 0, 5)]);
            }
        }

        foreach (self::clasesDelDia($fecha, $laboratorioId) as $h) {
            if ($h->hora_inicio < $horaFin && $horaInicio < $h->hora_fin) {
                $conflictos[] = [
                    'tipo' => 'CLASE',
                    'detalle' => "Clase {$h->materia->sigla} grupo {$h->grupo} ({$h->docente->nombre_completo})",
                    'hora_inicio' => substr($h->hora_inicio, 0, 5),
                    'hora_fin' => substr($h->hora_fin, 0, 5),
                ];
            }
        }

        $reservas = Reserva::with('solicitante')
            ->where('laboratorio_id', $laboratorioId)->whereDate('fecha', $fecha)
            ->where('estado', 'APROBADA')
            ->when($ignorarReservaId, fn ($q) => $q->where('id', '<>', $ignorarReservaId))
            ->where('hora_inicio', '<', $horaFin)->where('hora_fin', '>', $horaInicio)
            ->get();
        foreach ($reservas as $r) {
            $conflictos[] = [
                'tipo' => 'RESERVA',
                'detalle' => "Reserva #{$r->id} ({$r->tipo}) de {$r->solicitante->nombre_completo}",
                'hora_inicio' => substr($r->hora_inicio, 0, 5),
                'hora_fin' => substr($r->hora_fin, 0, 5),
            ];
        }

        return $conflictos;
    }

    /** Estado de todos los laboratorios en una franja: libre / ocupado y por qué */
    public static function franja(string $fecha, string $horaInicio, string $horaFin): array
    {
        return Laboratorio::orderBy('codigo')->get()->map(function (Laboratorio $lab) use ($fecha, $horaInicio, $horaFin) {
            $conflictos = $lab->estado === 'DISPONIBLE'
                ? self::conflictos($lab->id, $fecha, $horaInicio, $horaFin)
                : [['tipo' => 'ESTADO', 'detalle' => "Laboratorio en estado {$lab->estado}"]];

            return [
                'laboratorio' => $lab->only(['id', 'codigo', 'nombre', 'capacidad', 'ubicacion', 'estado']),
                'libre' => empty($conflictos),
                'conflictos' => $conflictos,
            ];
        })->all();
    }

    /** Laboratorios libres en la franja (para proponer alternativas ante un choque) */
    public static function libres(string $fecha, string $horaInicio, string $horaFin, ?int $excepto = null): array
    {
        return collect(self::franja($fecha, $horaInicio, $horaFin))
            ->filter(fn ($l) => $l['libre'] && $l['laboratorio']['id'] !== $excepto)
            ->map(fn ($l) => $l['laboratorio']['codigo'].' - '.$l['laboratorio']['nombre'])
            ->values()->all();
    }

    /** Igual que libres() pero con el id, para que la pantalla ofrezca botones "usar este laboratorio" */
    public static function alternativas(string $fecha, string $horaInicio, string $horaFin, ?int $excepto = null): array
    {
        return collect(self::franja($fecha, $horaInicio, $horaFin))
            ->filter(fn ($l) => $l['libre'] && $l['laboratorio']['id'] !== $excepto)
            ->map(fn ($l) => ['id' => $l['laboratorio']['id'], 'codigo' => $l['laboratorio']['codigo'], 'nombre' => $l['laboratorio']['nombre']])
            ->values()->all();
    }

    /**
     * Reservas futuras (pendientes o aprobadas) que caen sobre una clase semanal:
     * mismo laboratorio, mismo día de la semana, horas superpuestas y dentro de las fechas del semestre.
     * Regla FICCT: la clase del horario base tiene prioridad, esas reservas se cancelan.
     */
    public static function reservasSobreClase(Semestre $semestre, int $laboratorioId, int $dia, string $horaInicio, string $horaFin): \Illuminate\Support\Collection
    {
        $desde = max(now()->toDateString(), $semestre->fecha_inicio->format('Y-m-d'));

        return Reserva::with(['laboratorio', 'solicitante'])
            ->where('laboratorio_id', $laboratorioId)
            ->whereIn('estado', ['PENDIENTE', 'APROBADA'])
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $semestre->fecha_fin->format('Y-m-d'))
            ->whereRaw('EXTRACT(ISODOW FROM fecha) = ?', [$dia])
            ->where('hora_inicio', '<', self::hms($horaFin))->where('hora_fin', '>', self::hms($horaInicio))
            ->orderBy('fecha')->orderBy('hora_inicio')->get();
    }

    /** Agenda del día: por laboratorio, todas las clases y reservas (para la vista de calendario) */
    public static function agenda(string $fecha): array
    {
        $bloqueosDia = FechaBloqueada::whereDate('fecha', $fecha)->get();

        return Laboratorio::orderBy('codigo')->get()->map(function (Laboratorio $lab) use ($fecha, $bloqueosDia) {
            $suyos = $bloqueosDia->filter(fn ($b) => $b->laboratorio_id === null || $b->laboratorio_id === $lab->id);
            // Día completo → toda la fila bloqueada; con horario → un bloque más en la agenda (ej: PSA 08:00–12:00)
            $bloqueo = $suyos->first(fn ($b) => $b->esDiaCompleto());
            $bloques = [];
            foreach ($suyos->reject(fn ($b) => $b->esDiaCompleto()) as $b) {
                $bloques[] = [
                    'tipo' => 'BLOQUEO', 'inicio' => substr($b->hora_inicio, 0, 5), 'fin' => substr($b->hora_fin, 0, 5),
                    'titulo' => $b->tipo, 'detalle' => $b->motivo,
                ];
            }

            foreach (self::clasesDelDia($fecha, $lab->id) as $h) {
                $bloques[] = [
                    'tipo' => 'CLASE', 'inicio' => substr($h->hora_inicio, 0, 5), 'fin' => substr($h->hora_fin, 0, 5),
                    'titulo' => "{$h->materia->sigla}-{$h->grupo}", 'detalle' => $h->docente->nombre_completo,
                ];
            }
            $reservas = Reserva::with('solicitante')->where('laboratorio_id', $lab->id)->whereDate('fecha', $fecha)
                ->whereIn('estado', ['APROBADA', 'PENDIENTE'])->get();
            foreach ($reservas as $r) {
                $bloques[] = [
                    'tipo' => $r->estado === 'APROBADA' ? 'RESERVA' : 'PENDIENTE',
                    'inicio' => substr($r->hora_inicio, 0, 5), 'fin' => substr($r->hora_fin, 0, 5),
                    'titulo' => $r->tipo, 'detalle' => $r->solicitante->nombre_completo.' - '.$r->motivo,
                ];
            }
            usort($bloques, fn ($a, $b) => strcmp($a['inicio'], $b['inicio']));

            return [
                'laboratorio' => $lab->only(['id', 'codigo', 'nombre', 'estado']),
                'bloqueo' => $bloqueo ? "{$bloqueo->tipo}: {$bloqueo->motivo}" : null,
                'bloques' => $bloques,
            ];
        })->all();
    }

    public static function hms(string $hora): string
    {
        return strlen($hora) === 5 ? $hora.':00' : $hora;
    }

    private static function clasesDelDia(string $fecha, int $laboratorioId)
    {
        $semestre = Semestre::activo();
        $dia = Carbon::parse($fecha)->dayOfWeekIso; // 1=lunes ... 7=domingo
        if (! $semestre || $dia === 7
            || $fecha < $semestre->fecha_inicio->format('Y-m-d') || $fecha > $semestre->fecha_fin->format('Y-m-d')) {
            return collect();
        }

        return Horario::with(['materia', 'docente'])
            ->where('semestre_id', $semestre->id)
            ->where('laboratorio_id', $laboratorioId)
            ->where('dia_semana', $dia)
            ->orderBy('hora_inicio')
            ->get();
    }
}
