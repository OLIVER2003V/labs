<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipo extends Model
{
    protected $table = 'equipos';

    protected $fillable = ['codigo', 'activo_fijo', 'laboratorio_id', 'tipo', 'descripcion', 'estado'];

    protected function casts(): array
    {
        return [];
    }

    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    /** CU6: configuración de red (1 a 1) */
    public function red(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EquipoRed::class, 'equipo_id');
    }
    public function incidencias(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Incidencia::class, 'equipo_id');
    }
}
