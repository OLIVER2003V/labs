<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laboratorio extends Model
{
    protected $table = 'laboratorios';

    protected $fillable = ['codigo', 'nombre', 'capacidad', 'ubicacion', 'estado', 'tarifa_hora'];

    protected function casts(): array
    {
        return ['capacidad' => 'integer', 'tarifa_hora' => 'decimal:2'];
    }

    public function equipos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Equipo::class, 'laboratorio_id');
    }
}
