<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reserva extends Model
{
    protected $table = 'reservas';

    protected $fillable = ['laboratorio_id', 'solicitante_id', 'fecha', 'hora_inicio', 'hora_fin', 'tipo', 'motivo', 'estado', 'resuelto_por', 'observacion', 'uso', 'uso_registrado_por', 'uso_registrado_en', 'auxiliares_solicitados'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    public function solicitante(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }
    /** CU4 - Auxiliares que apoyan la reserva (asignado_por NULL = los asignó el sistema) */
    public function auxiliares(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reserva_auxiliares', 'reserva_id', 'auxiliar_id')->withPivot('asignado_por')->withTimestamps();
    }
    /** Reservas aprobadas (de hoy en adelante) que todavía no tienen todos los auxiliares que se pidieron */
    public function scopeFaltanAuxiliares(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('estado', 'APROBADA')->where('auxiliares_solicitados', '>', 0)->whereDate('fecha', '>=', now()->toDateString())
            ->whereRaw('(SELECT COUNT(*) FROM reserva_auxiliares ra WHERE ra.reserva_id = reservas.id) < reservas.auxiliares_solicitados');
    }
    public function resolutor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
