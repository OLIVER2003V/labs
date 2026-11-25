<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reserva extends Model
{
    protected $table = 'reservas';

    protected $fillable = ['laboratorio_id', 'solicitante_id', 'fecha', 'hora_inicio', 'hora_fin', 'tipo', 'motivo', 'estado', 'resuelto_por', 'observacion', 'uso', 'uso_registrado_por', 'uso_registrado_en'];

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
    public function resolutor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
