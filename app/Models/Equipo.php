<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipo extends Model
{
    protected $table = 'equipos';

    protected $fillable = ['codigo', 'laboratorio_id', 'tipo', 'descripcion', 'estado'];

    protected function casts(): array
    {
        return [];
    }

    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    public function incidencias(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Incidencia::class, 'equipo_id');
    }
}
