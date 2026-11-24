<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incidencia extends Model
{
    protected $table = 'incidencias';

    protected $fillable = ['equipo_id', 'reportado_por', 'descripcion', 'estado', 'solucion', 'fecha_solucion'];

    protected function casts(): array
    {
        return ['fecha_solucion' => 'datetime'];
    }

    public function equipo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }
    public function reportante(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reportado_por');
    }
}
