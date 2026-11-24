<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Semestre extends Model
{
    protected $table = 'semestres';

    protected $fillable = ['gestion', 'fecha_inicio', 'fecha_fin', 'estado'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date:Y-m-d', 'fecha_fin' => 'date:Y-m-d'];
    }

    public function fechasBloqueadas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FechaBloqueada::class, 'semestre_id');
    }
    public static function activo(): ?self
    {
        return static::where('estado', 'ACTIVO')->first();
    }
}
