<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObjetoEncontrado extends Model
{
    protected $table = 'objetos_encontrados';

    protected $fillable = ['laboratorio_id', 'descripcion', 'fecha_hallazgo', 'registrado_por', 'estado', 'entregado_a', 'ci_receptor', 'fecha_entrega'];

    protected function casts(): array
    {
        return ['fecha_hallazgo' => 'date:Y-m-d', 'fecha_entrega' => 'datetime'];
    }

    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    public function registrador(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
