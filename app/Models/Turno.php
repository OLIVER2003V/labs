<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $table = 'turnos';

    protected $fillable = ['semestre_id', 'auxiliar_id', 'laboratorio_id', 'dia_semana', 'hora_inicio', 'hora_fin'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public function semestre(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Semestre::class, 'semestre_id');
    }
    public function auxiliar(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'auxiliar_id');
    }
    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
}
