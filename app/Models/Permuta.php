<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CU5 - Permuta temporal: el titular de un turno pide que otro auxiliar lo cubra en UNA fecha.
 * No cambia el turno semanal; solo esa fecha. Si está APROBADA, la asistencia de ese día la marca el reemplazante.
 */
class Permuta extends Model
{
    protected $table = 'permutas';

    /** Estados en los que la permuta todavía "ocupa" el turno y la fecha */
    const EN_CURSO = ['PENDIENTE', 'ACEPTADA', 'APROBADA'];

    protected $fillable = ['turno_id', 'solicitante_id', 'reemplazante_id', 'fecha', 'motivo', 'estado', 'aprobado_por', 'observacion'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function reemplazante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reemplazante_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /** ¿Quién debe atender el turno en esa fecha? El reemplazante si hay una permuta APROBADA; si no, el titular. */
    public static function responsable(Turno $turno, string $fecha): int
    {
        return static::where('turno_id', $turno->id)->whereDate('fecha', $fecha)->where('estado', 'APROBADA')
            ->value('reemplazante_id') ?? $turno->auxiliar_id;
    }

    /** Texto corto para notificaciones: "LAB-44 · lunes 23/11/2026 · 07:00–11:00" */
    public function describir(): string
    {
        $this->loadMissing('turno.laboratorio');
        $dias = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        return ($this->turno->laboratorio?->codigo ?? 'Módulo').' · '.$dias[$this->turno->dia_semana].' '.$this->fecha->format('d/m/Y')
            .' · '.substr($this->turno->hora_inicio, 0, 5).'–'.substr($this->turno->hora_fin, 0, 5);
    }
}
